<?php

namespace App\Http\Controllers\Accountant;

use App\Http\Controllers\Controller;
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

    public function index(Request $request)
    {
        $accountant = auth('accountant')->user();
        $storeId = $accountant->store_id;
        $statuses = ['pending', 'completed', 'rejected', 'cancelled'];
        $status = in_array($request->get('status'), $statuses, true) ? $request->get('status') : null;

        $incoming = StoreTransfer::with(['senderStore', 'receiverStore', 'items.senderProduct', 'items.receiverProduct', 'createdBy', 'actionBy'])
            ->where('receiver_store_id', $storeId)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(10, ['*'], 'incoming_page');

        $outgoing = StoreTransfer::with(['senderStore', 'receiverStore', 'items.senderProduct', 'items.receiverProduct', 'createdBy', 'actionBy'])
            ->where('sender_store_id', $storeId)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(10, ['*'], 'outgoing_page');

        $receiverProducts = Product::where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name', 'quantity', 'barcode', 'category_id']);

        $incoming->getCollection()->each(function (StoreTransfer $transfer) use ($receiverProducts) {
            $transfer->items->each(function ($item) use ($receiverProducts, $transfer) {
                $suggestions = $this->transfers->suggestReceiverProducts($item, $transfer->receiver_store_id);
                $item->setRelation('receiverSuggestions', $suggestions->isNotEmpty() ? $suggestions : $receiverProducts->take(8));
            });
        });

        return view('accountants.store-transfers.index', compact('incoming', 'outgoing', 'receiverProducts', 'status', 'statuses'));
    }

    public function create()
    {
        $accountant = auth('accountant')->user();
        $store = $accountant->store;
        $stores = Store::where('user_id', $store->user_id)
            ->where('id', '!=', $store->id)
            ->orderBy('name')
            ->get(['id', 'name']);
        $products = Product::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name', 'quantity', 'product_type', 'is_splittable']);

        return view('accountants.store-transfers.create', compact('store', 'stores', 'products'));
    }

    public function store(Request $request)
    {
        $accountant = auth('accountant')->user();
        $store = $accountant->store;
        $receiverStoreIds = Store::where('user_id', $store->user_id)
            ->where('id', '!=', $store->id)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $validated = $request->validate([
            'receiver_store_id' => ['required', Rule::in($receiverStoreIds)],
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
            $accountant
        );

        return redirect()
            ->route('accountant.transfers.index')
            ->with('success', "تم إنشاء طلب النقل رقم #{$transfer->id} وخصم الكمية من متجرك بانتظار استلام المتجر الآخر.");
    }

    public function approve(Request $request, StoreTransfer $transfer)
    {
        $accountant = auth('accountant')->user();
        $validated = $request->validate([
            'receiver_product_id' => 'required|array',
            'receiver_product_id.*' => ['required', Rule::exists('products', 'id')->where(fn ($query) => $query->where('store_id', $accountant->store_id))],
        ]);

        $this->transfers->approveTransfer($transfer, $validated['receiver_product_id'], $accountant, false);

        return back()->with('success', 'تم استلام النقل وإضافة الكمية لمخزون متجرك.');
    }

    public function reject(Request $request, StoreTransfer $transfer)
    {
        $validated = $request->validate(['reason' => 'required|string|max:1000']);
        $this->transfers->rejectTransfer($transfer, $validated['reason'], auth('accountant')->user());

        return back()->with('success', 'تم رفض النقل وإرجاع الكمية للمتجر المرسل.');
    }

    public function cancel(StoreTransfer $transfer)
    {
        $this->transfers->cancelTransfer($transfer, auth('accountant')->user());

        return back()->with('success', 'تم إلغاء النقل وإرجاع الكمية للمتجر المرسل.');
    }

    public function markSeen(StoreTransfer $transfer)
    {
        $this->transfers->markSeen($transfer, auth('accountant')->user());

        return back()->with('success', 'تم تعليم إشعار النقل كمقروء.');
    }
}
