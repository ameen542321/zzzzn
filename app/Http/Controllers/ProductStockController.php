<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\InventoryLog;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductStockController extends Controller
{
    /**
     * صفحة إدارة المخزون
     */
    public function index(Store $store, Product $product)
    {
        $this->ensureProductBelongsToStore($store, $product);

        $movements = $product->stockMovements()
            ->orderBy('created_at', 'desc')
            ->paginate(25);

        $inventoryAuditStatus = $product->inventoryAuditStatus($store);
        $latestInventoryAudit = $product->inventoryLogs()
            ->where('type', Product::INVENTORY_AUDIT_CONFIRMED_TYPE)
            ->latest()
            ->first();

        return view('user.stores.products.stock.index', compact('store', 'product', 'movements', 'inventoryAuditStatus', 'latestInventoryAudit'));
    }

    public function confirmAudit(Store $store, Product $product)
    {
        $this->ensureProductBelongsToStore($store, $product);

        $status = $product->inventoryAuditStatus($store);

        if (! $status['can_confirm']) {
            return back()->with($status['color'] === 'green' ? 'success' : 'error', $status['message']);
        }

        InventoryLog::create([
            'store_id' => $store->id,
            'user_id' => auth()->id() ?? $store->user_id,
            'product_id' => $product->id,
            'quantity_change' => 0,
            'type' => Product::INVENTORY_AUDIT_CONFIRMED_TYPE,
        ]);

        return back()->with('success', 'تم تأكيد جرد المنتج لهذه الدورة بنجاح.');
    }

    /**
     * زيادة المخزون
     */
    public function increase(Request $request, Store $store, Product $product)
    {
        $this->ensureProductBelongsToStore($store, $product);

        $request->validate([
            'quantity'  => 'required|numeric|min:0.001',
            'unit_type' => 'nullable|in:unit,piece,roll,meter,meters',
            'note'      => 'nullable|string|max:255',
        ]);

        $unitType = $this->resolveStockUnitType($request, $product);

        DB::transaction(function () use ($request, $store, $product, $unitType) {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->ensureProductBelongsToStore($store, $lockedProduct);

            // نجعل الموديل هو المرجع الوحيد لتحويل الوحدة أثناء الزيادة
            $lockedProduct->increaseStock(
                (float) $request->quantity,
                $request->note,
                auth()->id(),
                $unitType
            );
        });

        return back()->with('success', 'تمت زيادة المخزون بنجاح');
    }

    /**
     * خصم المخزون
     */
    public function decrease(Request $request, Store $store, Product $product)
    {
        $this->ensureProductBelongsToStore($store, $product);

        $request->validate([
            'quantity'  => 'required|numeric|min:0.01',
            'unit_type' => 'nullable|in:unit,piece,roll,meter,meters',
            'note'      => 'nullable|string|max:255',
        ]);

        $rawQuantity = (float) $request->quantity;
        $unitType = $this->resolveStockUnitType($request, $product);

        $deducted = DB::transaction(function () use ($request, $store, $product, $rawQuantity, $unitType) {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->ensureProductBelongsToStore($store, $lockedProduct);

            // نستخدم نفس دالة التحويل المركزية الموجودة في الموديل
            // حتى لا يختلف التحقق المسبق عن الخصم الفعلي لاحقاً.
            $actualAmountToDeduct = $lockedProduct->normalizeQuantityByUnit($rawQuantity, $unitType);

            if ($actualAmountToDeduct > (float) $lockedProduct->getRawOriginal('quantity')) {
                return false;
            }

            $lockedProduct->decreaseStock($rawQuantity, $request->note, auth()->id(), $unitType);

            return true;
        });

        if (!$deducted) {
            return back()->withErrors(['quantity' => 'الكمية المتوفرة لا تكفي'])->withInput();
        }

        return back()->with('success', 'تم خصم الكمية من المخزن بنجاح');
    }


    private function resolveStockUnitType(Request $request, Product $product): string
    {
        if ($request->filled('unit_type')) {
            return (string) $request->unit_type;
        }

        return $product->product_type === 'fractional' ? 'roll' : 'unit';
    }

    private function ensureProductBelongsToStore(Store $store, Product $product): void
    {
        if ((int) $product->store_id !== (int) $store->id) {
            abort(404);
        }
    }

}
