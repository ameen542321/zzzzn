<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorePurchaseOrder;
use App\Services\StorePurchaseOrderService;
use App\Support\ArabicPdf as PDF;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StorePurchaseOrderController extends Controller
{
    public function __construct(private StorePurchaseOrderService $orders)
    {
    }

    public function index(Request $request, Store $store)
    {
        $this->authorizeStore($store);
        $statuses = ['draft', 'sent', 'received', 'approved', 'cancelled'];
        $status = in_array($request->get('status'), $statuses, true) ? $request->get('status') : null;
        $dateFrom = $request->filled('date_from') ? $request->date('date_from')->startOfDay() : now()->startOfMonth();
        $dateTo = $request->filled('date_to') ? $request->date('date_to')->endOfDay() : now()->endOfMonth();

        $orders = StorePurchaseOrder::withCount('items')
            ->where('store_id', $store->id)
            ->where('user_id', auth('web')->id())
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(20);

        $dateFromValue = $dateFrom->format('Y-m-d');
        $dateToValue = $dateTo->format('Y-m-d');

        return view('user.store-purchase-orders.index', compact('store', 'orders', 'status', 'statuses', 'dateFromValue', 'dateToValue'));
    }

    public function create(Store $store)
    {
        $this->authorizeStore($store);
        $products = $this->suggestedProducts($store);

        return view('user.store-purchase-orders.create', compact('store', 'products'));
    }

    public function store(Request $request, Store $store)
    {
        $this->authorizeStore($store);
        $payload = $this->validatedOrderPayload($request, $store);

        $order = $this->orders->createOrder($store, auth('web')->user(), $payload);

        return redirect()->route('user.stores.purchase-orders.show', [$store->id, $order->id])
            ->with('success', 'تم تجهيز الطلبية كمسودة. راجعها ثم اضغط اعتماد الطلبية لإرسالها للمورد.');
    }

    public function show(Store $store, StorePurchaseOrder $order)
    {
        $this->authorizeOrder($store, $order);
        $order->load(['items.product', 'items.matchedProduct', 'store']);
        $products = Product::where('store_id', $store->id)->orderBy('name')->get(['id', 'name', 'cost_price']);
        $categories = Category::where('store_id', $store->id)->orderBy('name')->get(['id', 'name']);
        $whatsappText = $this->buildWhatsappText($order);

        return view('user.store-purchase-orders.show', compact('store', 'order', 'products', 'categories', 'whatsappText'));
    }

    public function edit(Store $store, StorePurchaseOrder $order)
    {
        $this->authorizeOrder($store, $order);
        if ($order->status !== 'draft') {
            return redirect()->route('user.stores.purchase-orders.show', [$store->id, $order->id])
                ->withErrors(['order' => 'يمكن تعديل الطلبية قبل اعتماد إرسالها فقط.']);
        }

        $order->load(['items.product']);
        $products = Product::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name', 'quantity', 'min_stock', 'cost_price', 'product_type', 'roll_length', 'is_splittable']);

        return view('user.store-purchase-orders.create', compact('store', 'products', 'order'));
    }

    public function update(Request $request, Store $store, StorePurchaseOrder $order)
    {
        $this->authorizeOrder($store, $order);
        $payload = $this->validatedOrderPayload($request, $store);

        $updatedOrder = $this->orders->updateDraftOrder($order, auth('web')->user(), $payload);

        return redirect()->route('user.stores.purchase-orders.show', [$store->id, $updatedOrder->id])
            ->with('success', 'تم حفظ تعديلات مسودة الطلبية وتحديث تكاليف المنتجات من بياناتها الحالية.');
    }

    public function pdf(Store $store, StorePurchaseOrder $order)
    {
        $this->authorizeOrder($store, $order);
        $order->load(['items.product', 'items.matchedProduct', 'store']);
        $pdf = PDF::loadView('pdf.store-purchase-order', compact('store', 'order'))->setOption('encoding', 'utf-8');
        $file = 'طلبية_توريد_' . preg_replace('/[^\p{Arabic}\p{L}\p{N}\-_]+/u', '_', $store->name) . '_' . $order->id . '.pdf';

        return $pdf->download($file);
    }

    public function markSent(Store $store, StorePurchaseOrder $order)
    {
        $this->authorizeOrder($store, $order);
        $this->orders->markSent($order, auth('web')->user());

        return redirect()->route('user.stores.purchase-orders.show', [$store->id, $order->id])
            ->with('success', 'تم اعتماد الطلبية. سيتم فتح واتساب وتجهيز ملف PDF، ولم يعد بالإمكان تعديل الطلبية.')
            ->with('open_whatsapp', true)
            ->with('download_pdf', true);
    }

    public function receive(Request $request, Store $store, StorePurchaseOrder $order)
    {
        $this->authorizeOrder($store, $order);

        $itemsInput = collect($request->input('items', []))
            ->mapWithKeys(function ($item, $key) {
                if (is_array($item) && empty($item['id']) && is_numeric($key)) {
                    $item['id'] = (int) $key;
                }

                return [$key => $item];
            })
            ->all();

        $request->merge(['items' => $itemsInput]);

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => ['required', Rule::exists('store_purchase_order_items', 'id')->where(fn ($query) => $query->where('store_purchase_order_id', $order->id))],
            'items.*.quantity_received' => 'nullable|numeric|min:0',
            'items.*.cost_price_at_receipt' => 'nullable|numeric|min:0',
            'items.*.unit_type' => 'nullable|string|in:unit,roll,meter,meters,piece,kit,default,normalized',
            'items.*.matched_product_id' => ['nullable', Rule::exists('products', 'id')->where(fn ($query) => $query->where('store_id', $store->id))],
            'items.*.update_product_cost' => 'nullable|boolean',
            'items.*.receipt_notes' => 'nullable|string|max:1000',
        ], [
            'items.required' => 'يجب إرسال بيانات الاستلام أولاً.',
            'items.*.id.required' => 'معرف عنصر الطلبية مطلوب لعملية التحديث.',
            'items.*.id.exists' => 'عنصر الطلبية المحدد غير صحيح أو لا ينتمي لهذه الطلبية.',
            'items.*.quantity_received.numeric' => 'الكمية المستلمة يجب أن تكون رقمًا.',
            'items.*.quantity_received.min' => 'الكمية المستلمة لا يمكن أن تكون أقل من صفر.',
            'items.*.cost_price_at_receipt.numeric' => 'سعر الاستلام يجب أن يكون رقمًا.',
            'items.*.cost_price_at_receipt.min' => 'سعر الاستلام لا يمكن أن يكون أقل من صفر.',
            'items.*.matched_product_id.exists' => 'المنتج المقابل المختار غير صحيح أو لا يتبع هذا المتجر.',
        ]);

        $items = collect($validated['items'])
            ->keyBy(fn ($item) => (int) $item['id'])
            ->all();

        $this->orders->receive($order, auth('web')->user(), $items);

        return back()->with('success', 'تم اعتماد بيانات الاستلام. راجع الفروقات ثم نفّذ الاعتماد المخزني لإضافة المنتجات.');
    }

    public function approve(Store $store, StorePurchaseOrder $order)
    {
        $this->authorizeOrder($store, $order);
        $this->orders->approve($order, auth('web')->user());

        return back()->with('success', 'تم اعتماد الاستلام وتحديث المخزون.');
    }

    public function cancel(Store $store, StorePurchaseOrder $order)
    {
        $this->authorizeOrder($store, $order);
        $this->orders->cancel($order, auth('web')->user());

        return redirect()->route('user.stores.purchase-orders.index', $store->id)
            ->with('success', 'تم إلغاء طلبية التوريد.');
    }

    public function destroy(Store $store, StorePurchaseOrder $order)
    {
        $this->authorizeOrder($store, $order);

        if ($order->status !== 'cancelled') {
            return back()->withErrors(['order' => 'يمكن حذف الطلبية بعد إلغائها فقط.']);
        }

        $order->delete();

        return redirect()->route('user.stores.purchase-orders.index', $store->id)
            ->with('success', 'تم حذف طلبية التوريد الملغية.');
    }

    private function validatedOrderPayload(Request $request, Store $store): array
    {
        $validated = $request->validate([
            'supplier_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'items' => 'nullable|array',
            'items.*.product_id' => ['nullable', Rule::exists('products', 'id')->where(fn ($query) => $query->where('store_id', $store->id))],
            'items.*.quantity_requested' => 'nullable|numeric|min:0.01',
            'items.*.unit_type' => 'nullable|string|in:unit,roll,meter,meters,piece,kit,default,normalized',
            'items.*.receipt_notes' => 'nullable|string|max:255',
            'custom_items' => 'nullable|array',
            'custom_items.*.custom_product_name' => 'required_with:custom_items|string|max:255',
            'custom_items.*.quantity_requested' => 'nullable|numeric|min:0',
            'custom_items.*.unit_type' => 'nullable|string|in:unit,roll,meter,meters,piece,kit,default,normalized',
            'custom_items.*.receipt_notes' => 'nullable|string|max:255',
            'custom_items.*.cost_price_at_order' => 'nullable|numeric|min:0',
        ]);

        $items = collect($validated['items'] ?? [])
            ->filter(fn ($item) => ! empty($item['product_id']) && ! empty($item['quantity_requested']))
            ->values()
            ->all();
        $customItems = collect($validated['custom_items'] ?? [])
            ->filter(fn ($item) => ! empty($item['custom_product_name']))
            ->values()
            ->all();

        if (empty($items) && empty($customItems)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['items' => 'يجب اختيار منتج واحد على الأقل أو إضافة منتج مخصص.']);
        }

        return [
            'supplier_name' => $validated['supplier_name'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'items' => $items,
            'custom_items' => $customItems,
        ];
    }

    private function suggestedProducts(Store $store)
    {
        $recentSaleIds = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->where('products.store_id', $store->id)
            ->where('sales.created_at', '>=', now()->subDays(30))
            ->pluck('products.id');

        $recentMovementIds = DB::table('stock_movements')
            ->join('products', 'stock_movements.product_id', '=', 'products.id')
            ->where('products.store_id', $store->id)
            ->where('stock_movements.created_at', '>=', now()->subDays(30))
            ->pluck('products.id');

        $activeProductIds = $recentSaleIds->merge($recentMovementIds)->unique()->values();

        return Product::where('store_id', $store->id)
            ->whereIn('id', $activeProductIds)
            ->orderByRaw("CASE WHEN product_type = 'fractional' AND roll_length > 0 THEN ((quantity / roll_length) <= min_stock) ELSE (quantity <= min_stock) END DESC")
            ->orderBy('quantity')
            ->orderBy('name')
            ->get(['id', 'name', 'quantity', 'min_stock', 'cost_price', 'product_type', 'roll_length', 'is_splittable']);
    }

    private function buildWhatsappText(StorePurchaseOrder $order): string
    {
        $lines = [
            'السلام عليكم ورحمة الله وبركاته',
            'طلب بضاعة جديد من متجر: ' . $order->store->name,
            '',
            'المنتجات المطلوبة:',
        ];

        foreach ($order->items as $index => $item) {
            $note = trim((string) ($item->receipt_notes ?? ''));
            $lines[] = ($index + 1) . '. ' . $item->productName() . ': ' . $this->formatQuantityForMessage((float) $item->quantity_requested, $item->unit_type, $item->product) . ($note !== '' ? ' - ' . $note : '');
        }

        $lines[] = '';
        $lines[] = 'هذه الرسالة تم إرسالها عبر CARLED.';

        return implode("\n", $lines);
    }



    private function formatQuantityForMessage(float $quantity, ?string $unitType, ?Product $product = null): string
    {
        $quantityText = $quantity > 0
            ? rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.')
            : 'كمية غير محددة';

        $unit = $this->unitLabel($unitType, $product);

        return trim($quantityText . ' ' . $unit);
    }

    private function unitLabel(?string $unitType, ?Product $product = null): string
    {
        $matchedLabel = match ($unitType) {
            'meter', 'meters' => 'متر',
            'piece' => 'حبة',
            'kit' => 'طقم',
            'roll' => 'رول',
            default => '',
        };

        if ($matchedLabel === '' && $product && isset($product->product_type)) {
            return $product->product_type === 'fractional' ? 'متر' : 'حبة';
        }

        return $matchedLabel;
    }

    private function authorizeOrder(Store $store, StorePurchaseOrder $order): void
    {
        $this->authorizeStore($store);
        if ((int) $order->store_id !== (int) $store->id || (int) $order->user_id !== (int) auth('web')->id()) {
            abort(403);
        }
    }

    private function authorizeStore(Store $store): void
    {
        if ((int) $store->user_id !== (int) auth('web')->id()) {
            abort(403);
        }
    }
}
