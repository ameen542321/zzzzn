<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Store;
use App\Models\StorePurchaseOrder;
use App\Models\StorePurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorePurchaseOrderService
{
    public function createOrder(Store $store, User $user, array $payload): StorePurchaseOrder
    {
        $this->ensureOwner($store, $user);

        return DB::transaction(function () use ($store, $user, $payload) {
            $order = StorePurchaseOrder::create([
                'store_id' => $store->id,
                'user_id' => $user->id,
                'supplier_name' => $payload['supplier_name'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'status' => 'draft',
            ]);

            $this->replaceDraftItems($order, $store, $payload);

            return $order->fresh(['store', 'items.product', 'items.matchedProduct']);
        });
    }

    public function updateDraftOrder(StorePurchaseOrder $order, User $user, array $payload): StorePurchaseOrder
    {
        $this->ensureOwner($order->store, $user);

        return DB::transaction(function () use ($order, $payload) {
            $lockedOrder = StorePurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedOrder->load('store');

            if ($lockedOrder->status !== 'draft') {
                throw ValidationException::withMessages(['order' => 'يمكن تعديل الطلبية قبل اعتماد إرسالها فقط.']);
            }

            $lockedOrder->update([
                'supplier_name' => $payload['supplier_name'] ?? null,
                'notes' => $payload['notes'] ?? null,
            ]);

            $lockedOrder->items()->delete();
            $this->replaceDraftItems($lockedOrder, $lockedOrder->store, $payload);

            return $lockedOrder->fresh(['store', 'items.product', 'items.matchedProduct']);
        });
    }

    public function markSent(StorePurchaseOrder $order, User $user): StorePurchaseOrder
    {
        $this->ensureOwner($order->store, $user);
        if ($order->status !== 'draft') {
            throw ValidationException::withMessages(['order' => 'يمكن إرسال الطلبية من حالة المسودة فقط.']);
        }

        $order->update(['status' => 'sent', 'sent_at' => now()]);

        return $order->fresh(['store', 'items.product', 'items.matchedProduct']);
    }

    public function receive(StorePurchaseOrder $order, User $user, array $items): StorePurchaseOrder
    {
        $this->ensureOwner($order->store, $user);
        if ($order->status !== 'sent') {
            throw ValidationException::withMessages(['order' => 'يجب إرسال الطلبية للمورد قبل تسجيل الاستلام.']);
        }

        return DB::transaction(function () use ($order, $items) {
            $lockedOrder = StorePurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedOrder->load(['items.product', 'items.matchedProduct', 'store']);

            foreach ($lockedOrder->items as $item) {
                $incoming = $items[$item->id] ?? [];
                $receiptPrice = array_key_exists('cost_price_at_receipt', $incoming) && $incoming['cost_price_at_receipt'] !== null && $incoming['cost_price_at_receipt'] !== ''
                    ? (float) $incoming['cost_price_at_receipt']
                    : null;
                $receivedUnitType = $incoming['unit_type'] ?? $item->unit_type;

                $receivedQuantity = array_key_exists('quantity_received', $incoming) && $incoming['quantity_received'] !== null && $incoming['quantity_received'] !== ''
                    ? (float) $incoming['quantity_received']
                    : (float) ($item->quantity_requested ?? 0);
                $expectedCostForReceived = $this->receiptLineCost($item, $receivedQuantity, $receivedUnitType, $incoming['matched_product_id'] ?? null);
                $receiptPrice ??= $expectedCostForReceived;
                $hasReceiptPrice = $receiptPrice > 0;
                $variance = $receiptPrice - $expectedCostForReceived;
                $variancePercent = ($hasReceiptPrice && $expectedCostForReceived > 0) ? ($variance / $expectedCostForReceived) * 100 : 0;

                $item->update([
                    'quantity_received' => $receivedQuantity,
                    'unit_type' => $receivedUnitType,
                    'cost_price_at_receipt' => $receiptPrice,
                    'matched_product_id' => $incoming['matched_product_id'] ?? $item->matched_product_id,
                    'price_variance' => $variance,
                    'price_variance_percent' => $variancePercent,
                    'update_product_cost' => ! empty($incoming['update_product_cost']),
                    'receipt_notes' => $incoming['receipt_notes'] ?? $item->receipt_notes,
                ]);
            }

            $lockedOrder->load('items');

            if (! $lockedOrder->items->contains(fn (StorePurchaseOrderItem $item) => (float) ($item->quantity_received ?? 0) > 0)) {
                throw ValidationException::withMessages(['items' => 'يجب إدخال كمية مستلمة واحدة على الأقل قبل اعتماد بيانات الاستلام.']);
            }

            $lockedOrder->update(['status' => 'received', 'received_at' => now()]);

            return $lockedOrder->fresh(['store', 'items.product', 'items.matchedProduct']);
        });
    }

    public function approve(StorePurchaseOrder $order, User $user): StorePurchaseOrder
    {
        $this->ensureOwner($order->store, $user);

        return DB::transaction(function () use ($order, $user) {
            $lockedOrder = StorePurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($lockedOrder->status !== 'received') {
                throw ValidationException::withMessages(['order' => 'يجب اعتماد بيانات الاستلام قبل الاعتماد المخزني.']);
            }

            $lockedOrder->load(['items.product', 'items.matchedProduct', 'store']);

            foreach ($lockedOrder->items as $item) {
                $quantity = (float) ($item->quantity_received ?? 0);

                if ($quantity <= 0) {
                    continue;
                }

                $productId = $item->product_id ?: $item->matched_product_id;
                if (! $productId) {
                    throw ValidationException::withMessages(['items' => "يجب ربط المنتج المخصص ({$item->productName()}) بمنتج مقابل قبل اعتماد وإغلاق الطلبية."]);
                }

                $receiptPrice = (float) ($item->cost_price_at_receipt ?? 0);
                $expectedCostForReceived = $this->receiptLineCost($item, $quantity, $item->unit_type ?: 'unit', $productId);
                if ($receiptPrice > 0 && round($receiptPrice, 2) !== round($expectedCostForReceived, 2) && ! $item->update_product_cost) {
                    throw ValidationException::withMessages(['items' => "يوجد فرق تكلفة في ({$item->productName()})، اختر تحديث التكلفة أو عدّل سعر الاستلام قبل الاعتماد."]);
                }

                $product = Product::where('store_id', $lockedOrder->store_id)->whereKey($productId)->lockForUpdate()->firstOrFail();
                $product->increaseStock($quantity, "استلام طلبية توريد #{$lockedOrder->id}", $user->id, $item->unit_type ?: 'unit');

                if ($item->update_product_cost && $item->cost_price_at_receipt !== null) {
                    $product->update([
                        'cost_price' => $this->normalizedProductCostFromReceipt($product, $receiptPrice, $quantity, $item->unit_type ?: 'unit'),
                    ]);
                }
            }

            if (! $lockedOrder->items->contains(fn (StorePurchaseOrderItem $item) => (float) ($item->quantity_received ?? 0) > 0)) {
                throw ValidationException::withMessages(['items' => 'يجب إدخال كمية مستلمة واحدة على الأقل قبل اعتماد وإغلاق الطلبية.']);
            }

            $lockedOrder->update(['status' => 'approved', 'approved_at' => now()]);

            return $lockedOrder->fresh(['store', 'items.product', 'items.matchedProduct']);
        });
    }

    public function cancel(StorePurchaseOrder $order, User $user): StorePurchaseOrder
    {
        $this->ensureOwner($order->store, $user);
        if (! in_array($order->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['order' => 'يمكن إلغاء الطلبية قبل تسجيل الاستلام فقط.']);
        }

        $order->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return $order->fresh(['store', 'items.product', 'items.matchedProduct']);
    }



    private function replaceDraftItems(StorePurchaseOrder $order, Store $store, array $payload): void
    {
        foreach ($payload['items'] as $item) {
            $product = Product::where('store_id', $store->id)->find($item['product_id']);
            if (! $product) {
                throw ValidationException::withMessages(['items' => 'أحد المنتجات لا يتبع هذا المتجر.']);
            }

            StorePurchaseOrderItem::create([
                'store_purchase_order_id' => $order->id,
                'product_id' => $product->id,
                'quantity_requested' => (float) ($item['quantity_requested'] ?? 0),
                'unit_type' => $item['unit_type'] ?? 'unit',
                // عند تعديل المنتج نفسه قبل اعتماد الطلبية، نعيد احتساب التكلفة من بيانات المنتج الحالية.
                'cost_price_at_order' => $this->orderLineCost($product, (float) ($item['quantity_requested'] ?? 0), $item['unit_type'] ?? 'unit'),
                'receipt_notes' => $item['receipt_notes'] ?? null,
            ]);
        }

        foreach ($payload['custom_items'] ?? [] as $item) {
            StorePurchaseOrderItem::create([
                'store_purchase_order_id' => $order->id,
                'custom_product_name' => $item['custom_product_name'],
                'quantity_requested' => (float) ($item['quantity_requested'] ?? 0),
                'unit_type' => $item['unit_type'] ?? 'unit',
                'cost_price_at_order' => $item['cost_price_at_order'] ?? 0,
                'receipt_notes' => $item['receipt_notes'] ?? null,
            ]);
        }
    }

    private function receiptLineCost(StorePurchaseOrderItem $item, float $quantity, string $unitType, ?int $matchedProductId = null): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        $product = $item->product;
        if (! $product && $matchedProductId) {
            $product = Product::where('store_id', $item->order->store_id)->find($matchedProductId);
        }

        if ($product) {
            return $this->orderLineCost($product, $quantity, $unitType);
        }

        $requestedQuantity = (float) ($item->quantity_requested ?? 0);
        $orderPrice = (float) ($item->cost_price_at_order ?? 0);

        return ($requestedQuantity > 0 && $quantity > 0)
            ? round(($orderPrice / $requestedQuantity) * $quantity, 2)
            : $orderPrice;
    }

    private function normalizedProductCostFromReceipt(Product $product, float $receiptPrice, float $quantity, string $unitType): float
    {
        if ($receiptPrice <= 0 || $quantity <= 0) {
            return (float) ($product->cost_price ?? 0);
        }

        $unitReceiptCost = $receiptPrice / $quantity;

        if (in_array($unitType, ['meter', 'meters'], true) && (float) ($product->roll_length ?? 0) > 0) {
            return round($unitReceiptCost * (float) $product->roll_length, 2);
        }

        if ($unitType === 'piece' && (int) ($product->items_per_unit ?? 0) > 0) {
            return round($unitReceiptCost * (int) $product->items_per_unit, 2);
        }

        return round($unitReceiptCost, 2);
    }

    private function orderLineCost(Product $product, float $quantity, string $unitType): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        $cost = (float) ($product->cost_price ?? 0);

        if (in_array($unitType, ['meter', 'meters'], true) && (float) ($product->roll_length ?? 0) > 0) {
            return round(($cost / (float) $product->roll_length) * $quantity, 2);
        }

        if ($unitType === 'piece' && (int) ($product->items_per_unit ?? 0) > 0) {
            return round(($cost / (float) $product->items_per_unit) * $quantity, 2);
        }

        return round($cost * $quantity, 2);
    }

    private function ensureOwner(Store $store, User $user): void
    {
        if ((int) $store->user_id !== (int) $user->id) {
            abort(403);
        }
    }
}
