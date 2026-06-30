@extends('dashboard.app')
@section('title', 'إرسال نقل مخزني')
@section('content')
@php
    $formatQty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') ?: '0';
@endphp
<div class="max-w-4xl mx-auto p-6 space-y-6" dir="rtl">
    <div class="flex items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black text-white">إرسال منتج لمتجر آخر</h1>
            <p class="text-gray-400 text-sm mt-1">سيتم خصم الكمية من متجر {{ $store->name }} فورًا وحجزها حتى يوافق المتجر المستلم.</p>
        </div>
        <a href="{{ route('accountant.transfers.index') }}" class="px-4 py-2 rounded-lg bg-gray-800 text-gray-200 hover:bg-gray-700">رجوع</a>
    </div>

    @if ($errors->any())
        <div class="rounded-xl border border-red-800 bg-red-950/40 p-4 text-red-200">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('accountant.transfers.store') }}" class="bg-gray-900 border border-gray-800 rounded-2xl p-6 space-y-5">
        @csrf

        <div>
            <label class="block text-gray-300 font-bold mb-2">المتجر المستلم</label>
            <input type="text" data-select-search="receiver-store-select" placeholder="ابحث باسم المتجر..." class="mb-2 w-full rounded-xl bg-gray-950 border border-gray-700 text-white px-4 py-2 text-sm">
            <select id="receiver-store-select" name="receiver_store_id" required class="w-full rounded-xl bg-gray-800 border border-gray-700 text-white px-4 py-3">
                <option value="">اختر المتجر المستلم</option>
                @foreach($stores as $receiverStore)
                    <option value="{{ $receiverStore->id }}" @selected(old('receiver_store_id') == $receiverStore->id)>{{ $receiverStore->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-gray-300 font-bold mb-2">المنتج المرسل</label>
            <input type="hidden" name="sender_product_id" id="sender-product-id" value="{{ old('sender_product_id') }}">
            <div class="product-picker relative" data-hidden-input="sender-product-id" data-old-value="{{ old('sender_product_id') }}">
                <input type="text" data-picker-input required autocomplete="off" placeholder="ابحث باسم المنتج واختره..." class="w-full rounded-xl bg-gray-800 border border-gray-700 text-white px-4 py-3">
                <div data-picker-options class="hidden absolute z-50 mt-2 w-full max-h-64 overflow-y-auto rounded-xl border border-gray-700 bg-gray-950 shadow-2xl">
                    @foreach($products as $product)
                        <button type="button" data-picker-option data-id="{{ $product->id }}" data-label="{{ $product->name }}" data-product-type="{{ $product->product_type }}" data-is-splittable="{{ (int) $product->is_splittable }}" class="block w-full px-4 py-3 text-right text-sm text-gray-100 hover:bg-gray-800">
                            {{ $product->name }}
                        </button>
                    @endforeach
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-2">اكتب جزءًا من الاسم ثم اختر المنتج من القائمة الظاهرة.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-gray-300 font-bold mb-2">الكمية</label>
                <input type="number" name="quantity" step="0.001" min="0.001" value="{{ old('quantity') }}" required class="w-full rounded-xl bg-gray-800 border border-gray-700 text-white px-4 py-3">
            </div>
            <div>
                <label class="block text-gray-300 font-bold mb-2">الوحدة</label>
                <select id="unit-type-select" name="unit_type" required data-old-value="{{ old('unit_type', 'unit') }}" class="w-full rounded-xl bg-gray-800 border border-gray-700 text-white px-4 py-3"></select>
                <p id="unit-type-help" class="text-xs text-gray-500 mt-2">اختر المنتج أولًا لعرض الوحدات المناسبة.</p>
            </div>
        </div>

        <div>
            <label class="block text-gray-300 font-bold mb-2">ملاحظات</label>
            <textarea name="notes" rows="3" class="w-full rounded-xl bg-gray-800 border border-gray-700 text-white px-4 py-3" placeholder="اختياري">{{ old('notes') }}</textarea>
        </div>

        <div class="rounded-xl border border-amber-500/20 bg-amber-500/10 p-4 text-sm text-amber-100">
            إذا اخترت منتجًا رول/تظليل ستظهر لك وحدة رول أو متر. اختيار رول يعني نقل رول كامل، واختيار متر يعني نقل كمية بالأمتار.
        </div>

        <button class="w-full rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-black py-3">إرسال طلب النقل</button>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-select-search]').forEach((input) => {
        const select = document.getElementById(input.dataset.selectSearch);
        if (!select) return;
        const options = Array.from(select.options);
        input.addEventListener('input', () => {
            const term = input.value.trim().toLowerCase();
            options.forEach((option, index) => {
                option.hidden = index !== 0 && term && !option.text.toLowerCase().includes(term);
            });
        });
    });

    function setupProductPickers() {
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
                picker.dataset.productType = option.dataset.productType || '';
                picker.dataset.isSplittable = option.dataset.isSplittable || '0';
                close();
                input.dispatchEvent(new Event('product-selected'));
            }

            input?.addEventListener('focus', filter);
            input?.addEventListener('input', () => {
                if (hidden) hidden.value = '';
                picker.dataset.productType = '';
                picker.dataset.isSplittable = '0';
                filter();
            });
            options.forEach((option) => option.addEventListener('click', () => selectOption(option)));
            document.addEventListener('click', (event) => {
                if (!picker.contains(event.target)) close();
            });

            const oldValue = picker.dataset.oldValue;
            if (oldValue) {
                const oldOption = options.find((option) => option.dataset.id === oldValue);
                if (oldOption) selectOption(oldOption);
            }
        });
    }

    const productPicker = document.querySelector('.product-picker[data-hidden-input="sender-product-id"]');
    const productInput = productPicker?.querySelector('[data-picker-input]');
    const unitSelect = document.getElementById('unit-type-select');
    const unitHelp = document.getElementById('unit-type-help');
    const oldValue = unitSelect?.dataset.oldValue || 'unit';
    const optionSets = {
        normal: [['unit', 'وحدة']],
        fractional: [['roll', 'رول كامل'], ['meter', 'متر']],
        splittable: [['kit', 'طقم كامل'], ['piece', 'حبة']]
    };

    function renderUnits() {
        if (!unitSelect) return;
        const type = productPicker?.dataset.productType;
        const isSplittable = productPicker?.dataset.isSplittable === '1';
        const units = type === 'fractional' ? optionSets.fractional : (isSplittable ? optionSets.splittable : optionSets.normal);
        unitSelect.innerHTML = '';
        units.forEach(([value, label]) => {
            const option = new Option(label, value, false, value === oldValue);
            unitSelect.add(option);
        });
        if (![...unitSelect.options].some((option) => option.selected)) {
            unitSelect.selectedIndex = 0;
        }
        unitHelp.textContent = type === 'fractional'
            ? 'هذا المنتج يدعم النقل كرول كامل أو بالأمتار.'
            : (isSplittable ? 'هذا المنتج يدعم النقل كطقم كامل أو حبات.' : 'هذا المنتج ينقل كوحدة عادية.');
    }

    setupProductPickers();
    productInput?.addEventListener('product-selected', renderUnits);
    productInput?.addEventListener('input', renderUnits);
    renderUnits();
});
</script>
@endsection
