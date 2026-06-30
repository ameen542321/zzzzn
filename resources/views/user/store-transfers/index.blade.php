@extends('dashboard.app')
@section('title', 'النقل المخزني')
@section('content')
@php
    $formatQty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') ?: '0';
@endphp
<div class="max-w-7xl mx-auto p-6 space-y-6" dir="rtl">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black text-white">النقل المخزني بين المتاجر</h1>
            <p class="text-gray-400 text-sm mt-1">إدارة طلبات النقل الصادرة والواردة بين متاجرك.</p>
        </div>
        <a href="{{ route('user.stores.transfers.create', $store->id) }}" class="px-5 py-3 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold">+ طلب نقل جديد</a>
    </div>



    @php($statusLabels = ['pending' => 'معلق', 'completed' => 'مكتمل', 'rejected' => 'مرفوض', 'cancelled' => 'ملغي'])
    <div class="flex flex-wrap gap-2 bg-gray-900/60 border border-gray-800 rounded-2xl p-3">
        <a href="{{ route('user.stores.transfers.index', $store->id) }}" class="px-4 py-2 rounded-lg text-sm font-bold {{ empty($status) ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">الكل</a>
        @foreach($statusLabels as $value => $label)
            <a href="{{ route('user.stores.transfers.index', ['store' => $store->id, 'status' => $value]) }}" class="px-4 py-2 rounded-lg text-sm font-bold {{ ($status ?? null) === $value ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if(session('success'))
        <div class="rounded-xl border border-emerald-700 bg-emerald-950/40 p-4 text-emerald-200">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border border-red-800 bg-red-950/40 p-4 text-red-200">{{ $errors->first() }}</div>
    @endif

    <div class="space-y-4">
        @forelse($transfers as $transfer)
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5 space-y-4">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-white font-black">طلب #{{ $transfer->id }}</span>
                            <span class="px-3 py-1 rounded-full text-xs font-bold {{ $transfer->status === 'pending' ? 'bg-amber-500/10 text-amber-300 border border-amber-500/20' : ($transfer->status === 'completed' ? 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/20' : 'bg-red-500/10 text-red-300 border border-red-500/20') }}">
                                {{ ['pending' => 'معلق', 'completed' => 'مكتمل', 'rejected' => 'مرفوض', 'cancelled' => 'ملغي'][$transfer->status] ?? $transfer->status }}
                            </span>
                        </div>
                        <p class="text-gray-400 text-sm mt-2">من: <span class="text-gray-200">{{ $transfer->senderStore?->name }}</span> ← إلى: <span class="text-gray-200">{{ $transfer->receiverStore?->name }}</span></p>
                        <p class="text-gray-500 text-xs mt-1">منذ {{ $transfer->created_at?->diffForHumans() }}</p>
                        @if($transfer->notes)
                            <p class="text-amber-200 text-xs mt-2 bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2">ملاحظة الطلب: {{ $transfer->notes }}</p>
                        @endif
                    </div>
                    @if($transfer->status === 'pending')
                        <div class="flex gap-2 flex-wrap">
                            <form method="POST" action="{{ route('user.stores.transfers.cancel', [$store->id, $transfer->id]) }}" onsubmit="return confirm('سيتم إلغاء الطلب وإرجاع الكمية للمتجر المرسل، هل أنت متأكد؟')">
                                @csrf
                                <button class="px-4 py-2 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-100 text-sm font-bold">إلغاء</button>
                            </form>
                            <form method="POST" action="{{ route('user.stores.transfers.reject', [$store->id, $transfer->id]) }}" onsubmit="return confirm('سيتم رفض الطلب وإرجاع الكمية للمرسل، هل أنت متأكد؟')" class="flex gap-2">
                                @csrf
                                <input name="reason" required placeholder="سبب الرفض" class="rounded-lg bg-gray-800 border border-gray-700 text-white px-3 py-2 text-sm">
                                <button class="px-4 py-2 rounded-lg bg-red-700 hover:bg-red-600 text-white text-sm font-bold">رفض</button>
                            </form>
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    @foreach($transfer->items as $item)
                        <div class="rounded-xl border border-gray-800 bg-gray-950/50 p-4 space-y-3">
                            <div>
                                <p class="text-white font-bold">{{ $item->senderProduct?->name }}</p>
                                <p class="text-gray-400 text-xs">الكمية: {{ $formatQty($item->requested_quantity) }} {{ $item->unit_type }} — المحولة: {{ $formatQty($item->normalized_quantity) }}</p>
                                @if($item->receiverProduct)
                                    <p class="text-emerald-300 text-sm mt-1">منتج المستلم: {{ $item->receiverProduct->name }}</p>
                                @endif
                            </div>

                            @if($transfer->status === 'pending')
                                <form method="POST" action="{{ route('user.stores.transfers.owner-approve', [$store->id, $transfer->id]) }}" class="space-y-3">
                                    @csrf
                                    <label class="block text-gray-300 text-xs font-bold">اختر المنتج المقابل في المتجر المستلم</label>
                                    <input type="hidden" id="receiver-product-id-{{ $item->id }}" name="receiver_product_id[{{ $item->id }}]">
                                    <div class="product-picker relative" data-hidden-input="receiver-product-id-{{ $item->id }}">
                                        <input type="text" data-picker-input required autocomplete="off" placeholder="ابحث باسم المنتج واختره..." class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white px-3 py-2 text-sm">
                                        <div data-picker-options class="hidden absolute z-50 mt-2 w-full max-h-56 overflow-y-auto rounded-xl border border-gray-700 bg-gray-950 shadow-2xl">
                                            @foreach(($item->receiverSuggestions ?? collect()) as $suggestion)
                                                <button type="button" data-picker-option data-id="{{ $suggestion->id }}" data-label="{{ $suggestion->name }}" class="block w-full px-3 py-2 text-right text-sm text-gray-100 hover:bg-gray-800">
                                                    {{ $suggestion->name }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                    <button class="w-full rounded-lg bg-emerald-700 hover:bg-emerald-600 text-white px-4 py-2 font-bold">اعتماد نيابة عن المستلم</button>
                                    <p class="text-xs text-amber-300">إذا لم تجد المنتج، أنشئه في المتجر المستلم ثم عد لاعتماد الطلب.</p>
                                    <a href="{{ route('user.stores.products.create', $transfer->receiver_store_id) }}" target="_blank" class="inline-flex w-full items-center justify-center rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-2 text-sm font-bold text-amber-200 hover:bg-amber-500/20">فتح صفحة إنشاء منتج للمتجر المستلم</a>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-10 text-center text-gray-400">لا توجد طلبات نقل حتى الآن.</div>
        @endforelse
    </div>

    {{ $transfers->appends(['status' => $status])->links() }}
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.product-picker').forEach((picker) => {
        const input = picker.querySelector('[data-picker-input]');
        const optionsBox = picker.querySelector('[data-picker-options]');
        const hidden = document.getElementById(picker.dataset.hiddenInput);
        const options = Array.from(picker.querySelectorAll('[data-picker-option]'));

        function close() {
            optionsBox?.classList.add('hidden');
        }

        function open() {
            optionsBox?.classList.remove('hidden');
        }

        function filter() {
            const term = (input?.value || '').trim().toLowerCase();
            options.forEach((option) => {
                option.classList.toggle('hidden', term && !option.dataset.label.toLowerCase().includes(term));
            });
            open();
        }

        function selectOption(option) {
            if (!option || !input || !hidden) return;
            input.value = option.dataset.label;
            hidden.value = option.dataset.id;
            close();
        }

        input?.addEventListener('focus', filter);
        input?.addEventListener('input', () => {
            if (hidden) hidden.value = '';
            filter();
        });
        options.forEach((option) => option.addEventListener('click', () => selectOption(option)));
        document.addEventListener('click', (event) => {
            if (!picker.contains(event.target)) close();
        });
    });
});
</script>
@endsection
