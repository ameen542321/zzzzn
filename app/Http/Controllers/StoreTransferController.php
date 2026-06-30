<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreTransfer;
use App\Services\StoreTransferService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreTransferController extends Controller
{
    public function __construct(private StoreTransferService $transfers)
    {
    }

    public function index(Request $request, Store $store)
    {
        $this->authorizeOwnerStore($store);
        $storeIds = auth('web')->user()->stores()->pluck('id');
        $statuses = ['pending', 'completed', 'rejected', 'cancelled'];
        $status = in_array($request->get('status'), $statuses, true) ? $request->get('status') : null;

        $transfers = StoreTransfer::with(['senderStore', 'receiverStore', 'items.senderProduct', 'items.receiverProduct', 'createdBy', 'actionBy'])
            ->where(function ($query) use ($storeIds) {
                $query->whereIn('sender_store_id', $storeIds)
                    ->orWhereIn('receiver_store_id', $storeIds);
            })
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(20);

        $receiverProductsByStore = Product::whereIn('store_id', $storeIds)
            ->orderBy('name')
            ->get(['id', 'store_id', 'name', 'quantity', 'barcode', 'category_id'])
            ->groupBy('store_id');

        $transfers->getCollection()->each(function (StoreTransfer $transfer) use ($receiverProductsByStore) {
            $fallback = $receiverProductsByStore->get($transfer->receiver_store_id, collect());
            $transfer->items->each(function ($item) use ($transfer, $fallback) {
                $suggestions = $this->transfers->suggestReceiverProducts($item, $transfer->receiver_store_id);
                $item->setRelation('receiverSuggestions', $suggestions->isNotEmpty() ? $suggestions : $fallback->take(8));
            });
        });

        return view('user.store-transfers.index', compact('store', 'transfers', 'status', 'statuses'));
    }

    public function create(Store $store)
    {
        $this->authorizeOwnerStore($store);
        $user = auth('web')->user();
        $stores = $user->stores()->where('id', '!=', $store->id)->orderBy('name')->get(['id', 'name']);
        $products = Product::where('store_id', $store->id)->orderBy('name')->get(['id', 'name', 'quantity', 'product_type', 'is_splittable']);

        return view('user.store-transfers.create', compact('store', 'stores', 'products'));
    }

    public function store(Request $request, Store $store)
    {
        $this->authorizeOwnerStore($store);
        $user = auth('web')->user();
        $storeIds = $user->stores()->pluck('id');

        $validated = $request->validate([
            'receiver_store_id' => ['required', Rule::in($storeIds->filter(fn ($id) => (int) $id !== (int) $store->id)->map(fn ($id) => (string) $id)->all())],
            'sender_product_id' => ['required', Rule::exists('products', 'id')->where(fn ($query) => $query->where('store_id', $store->id))],
            'quantity' => 'required|numeric|min:0.001',
            'unit_type' => 'required|string|in:unit,roll,meter,meters,piece,kit,default,normalized',
            'notes' => 'nullable|string|max:1000',
        ]);

        $transfer = $this->transfers->createTransfer(
            $store,
            Store::findOrFail($validated['receiver_store_id']),
            Product::findOrFail($validated['sender_product_id']),
            (float) $validated['quantity'],
            $validated['unit_type'],
            $validated['notes'] ?? null,
            $user
        );

        return redirect()
            ->route('user.stores.transfers.index', $store->id)
            ->with('success', "تم إنشاء طلب النقل رقم #{$transfer->id} وخصم الكمية من المتجر المرسل بانتظار الاستلام.");
    }

    public function approve(Request $request, Store $store, StoreTransfer $transfer)
    {
        $this->authorizeOwnerStore($store);
        $validated = $request->validate([
            'receiver_product_id' => 'required|array',
            'receiver_product_id.*' => ['required', Rule::exists('products', 'id')->where(fn ($query) => $query->where('store_id', $transfer->receiver_store_id))],
        ]);

        $this->transfers->approveTransfer($transfer, $validated['receiver_product_id'], auth('web')->user(), false);

        return back()->with('success', 'تم اعتماد النقل وإضافة الكمية للمتجر المستلم.');
    }

    public function ownerApprove(Request $request, Store $store, StoreTransfer $transfer)
    {
        $this->authorizeOwnerStore($store);
        $validated = $request->validate([
            'receiver_product_id' => 'required|array',
            'receiver_product_id.*' => ['required', Rule::exists('products', 'id')->where(fn ($query) => $query->where('store_id', $transfer->receiver_store_id))],
        ]);

        $this->transfers->approveTransfer($transfer, $validated['receiver_product_id'], auth('web')->user(), true);

        return back()->with('success', 'تم اعتماد النقل بواسطة المالك نيابة عن المستلم.');
    }

    public function reject(Request $request, Store $store, StoreTransfer $transfer)
    {
        $this->authorizeOwnerStore($store);
        $validated = $request->validate(['reason' => 'required|string|max:1000']);
        $this->transfers->rejectTransfer($transfer, $validated['reason'], auth('web')->user());

        return back()->with('success', 'تم رفض طلب النقل وإرجاع الكمية للمتجر المرسل.');
    }

    public function cancel(Store $store, StoreTransfer $transfer)
    {
        $this->authorizeOwnerStore($store);
        $this->transfers->cancelTransfer($transfer, auth('web')->user());

        return back()->with('success', 'تم إلغاء طلب النقل وإرجاع الكمية للمتجر المرسل.');
    }

    private function authorizeOwnerStore(Store $store): void
    {
        $user = auth('web')->user();

        if (!$user || (int) $store->user_id !== (int) $user->id) {
            abort(403);
        }
    }
}