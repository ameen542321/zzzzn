@extends('dashboard.app')
@section('title', 'النقل المخزني')
@section('content')
@php
    $formatQty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') ?: '0';
@endphp
<div class="max-w-7xl mx-auto p-6 space-y-6" dir="rtl">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black text-white">النقل المخزني</h1>
            <p class="text-gray-400 text-sm mt-1">معالجة البضاعة الواردة ومتابعة الصادر من متجرك.</p>
        </div>
        <a href="{{ route('accountant.transfers.create') }}" class="px-5 py-3 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold">+ إرسال منتج لمتجر آخر</a>
    </div>



    @php($statusLabels = ['pending' => 'معلق', 'completed' => 'مكتمل', 'rejected' => 'مرفوض', 'cancelled' => 'ملغي'])
    <div class="flex flex-wrap gap-2 bg-gray-900/60 border border-gray-800 rounded-2xl p-3">
        <a href="{{ route('accountant.transfers.index') }}" class="px-4 py-2 rounded-lg text-sm font-bold {{ empty($status) ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">الكل</a>
        @foreach($statusLabels as $value => $label)
            <a href="{{ route('accountant.transfers.index', ['status' => $value]) }}" class="px-4 py-2 rounded-lg text-sm font-bold {{ ($status ?? null) === $value ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if(session('success'))
        <div class="rounded-xl border border-emerald-700 bg-emerald-950/40 p-4 text-emerald-200">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border border-red-800 bg-red-950/40 p-4 text-red-200">{{ $errors->first() }}</div>
    @endif

    <section class="space-y-4">
        <h2 class="text-xl font-black text-white">بضاعة واردة بحاجة لمعالجة</h2>
        @forelse($incoming as $transfer)
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5 space-y-4">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
                    <div>
                        <p class="text-white font-black">طلب #{{ $transfer->id }}</p>
                        <p class="text-gray-400 text-sm mt-1">من: {{ $transfer->senderStore?->name }} — الحالة: {{ ['pending' => 'معلق', 'completed' => 'مكتمل', 'rejected' => 'مرفوض', 'cancelled' => 'ملغي'][$transfer->status] ?? $transfer->status }}</p>
                        <p class="text-gray-500 text-xs mt-1">{{ $transfer->created_at?->diffForHumans() }}</p>
                        @if($transfer->notes)
                            <p class="text-amber-200 text-xs mt-2 bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2">ملاحظة الطلب: {{ $transfer->notes }}</p>
                        @endif
                    </div>
                    @if($transfer->status === 'pending')
                        <form method="POST" action="{{ route('accountant.transfers.reject', $transfer->id) }}" onsubmit="return confirm('سيتم رفض النقل وإرجاع الكمية للمرسل، هل أنت متأكد؟')" class="flex gap-2 flex-wrap">
                            @csrf
                            <input name="reason" required placeholder="سبب الرفض" class="rounded-lg bg-gray-800 border border-gray-700 text-white px-3 py-2 text-sm">
                            <button class="px-4 py-2 rounded-lg bg-red-700 hover:bg-red-600 text-white text-sm font-bold">رفض</button>
                        </form>
                    @endif
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    @foreach($transfer->items as $item)
                        <div class="rounded-xl border border-gray-800 bg-gray-950/50 p-4 space-y-3">
                            <p class="text-white font-bold">{{ $item->senderProduct?->name }}</p>
                            <p class="text-gray-400 text-xs">الكمية: {{ $formatQty($item->requested_quantity) }} {{ $item->unit_type }} — المحولة: {{ $formatQty($item->normalized_quantity) }}</p>
                            @if($item->receiverProduct)
                                <p class="text-emerald-300 text-sm">تمت إضافته إلى: {{ $item->receiverProduct->name }}</p>
                            @endif

                            @if($transfer->status === 'pending')
                                <form method="POST" action="{{ route('accountant.transfers.approve', $transfer->id) }}" class="space-y-3">
                                    @csrf
                                    <label class="block text-gray-300 text-xs font-bold">قرار المستلم: اختر المنتج المقابل في متجرك ثم وافق على الاستلام</label>
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
                                    <button class="w-full rounded-lg bg-emerald-700 hover:bg-emerald-600 text-white px-4 py-2 font-bold">موافقة واستلام</button>
                                    <p class="text-xs text-amber-300">إذا لم تجد المنتج، افتح صفحة إنشاء المنتج عند المالك أو أضفه من صلاحيات إدارة المنتجات ثم عد للموافقة.</p>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8 text-center text-gray-400">لا توجد بضاعة واردة.</div>
        @endforelse
        {{ $incoming->appends(['status' => $status])->links() }}
    </section>

    <section class="space-y-4">
        <h2 class="text-xl font-black text-white">بضاعة صادرة قيد الانتظار</h2>
        @forelse($outgoing as $transfer)
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <p class="text-white font-bold">طلب #{{ $transfer->id }} إلى {{ $transfer->receiverStore?->name }}</p>
                    <p class="text-gray-400 text-sm">الحالة: {{ ['pending' => 'معلق', 'completed' => 'مكتمل', 'rejected' => 'مرفوض', 'cancelled' => 'ملغي'][$transfer->status] ?? $transfer->status }}</p>
                </div>
                @if($transfer->status === 'pending')
                    <form method="POST" action="{{ route('accountant.transfers.cancel', $transfer->id) }}" onsubmit="return confirm('سيتم إلغاء النقل وإرجاع الكمية لمتجرك، هل أنت متأكد؟')">
                        @csrf
                        <button class="px-4 py-2 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-100 font-bold">إلغاء</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8 text-center text-gray-400">لا توجد بضاعة صادرة.</div>
        @endforelse
        {{ $outgoing->appends(['status' => $status])->links() }}
    </section>
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
