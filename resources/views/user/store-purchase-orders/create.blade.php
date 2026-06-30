@extends('dashboard.app')
@section('title', isset($order) ? 'تعديل طلبية توريد' : 'إرسال طلبية توريد')
@section('content')
@php
    $isEdit = isset($order);
    $productOptions = $products->map(function ($product) {
        $unitOptions = [];
        if (($product->product_type ?? null) === 'fractional' || (float) $product->roll_length > 0) {
            $unitOptions = [['value' => 'roll', 'label' => 'رول'], ['value' => 'meter', 'label' => 'متر']];
        } elseif ($product->is_splittable) {
            $unitOptions = [['value' => 'kit', 'label' => 'طقم'], ['value' => 'piece', 'label' => 'حبة']];
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'quantity' => (float) $product->quantity,
            'cost_price' => (float) $product->cost_price,
            'unit_options' => $unitOptions,
        ];
    })->values();

    $existingProductRows = $isEdit ? $order->items->whereNotNull('product_id')->map(function ($item) {
        return [
            'product_id' => $item->product_id,
            'quantity_requested' => (float) $item->quantity_requested,
            'unit_type' => $item->unit_type ?: 'unit',
            'receipt_notes' => (string) ($item->receipt_notes ?? ''),
        ];
    })->values() : collect();

    $existingCustomRows = $isEdit ? $order->items->whereNull('product_id')->map(function ($item) {
        return [
            'custom_product_name' => (string) $item->custom_product_name,
            'quantity_requested' => (float) $item->quantity_requested,
            'unit_type' => $item->unit_type ?: 'unit',
            'receipt_notes' => (string) ($item->receipt_notes ?? ''),
            'cost_price_at_order' => (float) ($item->cost_price_at_order ?? 0),
        ];
    })->values() : collect();
@endphp
<div class="max-w-5xl mx-auto p-4 md:p-6 space-y-4" dir="rtl">
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <h1 class="text-2xl font-black text-white">{{ $isEdit ? 'تعديل مسودة طلبية توريد' : 'إرسال طلبية توريد' }}</h1>
            <p class="text-gray-400 text-sm mt-1">{{ $isEdit ? 'يمكن التعديل فقط قبل اعتماد الطلبية وإرسالها للمورد.' : 'ابحث عن المنتج، اختره، ثم عدّل الكمية والملاحظات في السطور المضافة.' }}</p>
        </div>
        <a href="{{ route('user.stores.purchase-orders.index', $store->id) }}" class="px-4 py-2 rounded-lg bg-gray-800 text-gray-200 hover:bg-gray-700 transition">عودة</a>
    </div>

    @if($errors->any())
        <div class="rounded-xl border border-red-800 bg-red-950/40 p-4 text-red-200">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ $isEdit ? route('user.stores.purchase-orders.update', [$store->id, $order->id]) : route('user.stores.purchase-orders.store', $store->id) }}" class="space-y-4" id="purchaseOrderForm">
        @csrf
        @if($isEdit) @method('PUT') @endif
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 bg-gray-900 border border-gray-800 rounded-2xl p-4">
            <div>
                <label class="block text-gray-300 text-sm mb-1">اسم المورد / المندوب <span class="text-gray-500">(اختياري)</span></label>
                <input name="supplier_name" value="{{ old('supplier_name', $order->supplier_name ?? '') }}" placeholder="يمكن تركه فارغًا" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white px-3 py-2 focus:outline-none focus:border-blue-600">
            </div>
            <div>
                <label class="block text-gray-300 text-sm mb-1">ملاحظة داخلية <span class="text-gray-500">(اختياري)</span></label>
                <input name="notes" value="{{ old('notes', $order->notes ?? '') }}" placeholder="لا تظهر للمورد" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white px-3 py-2 focus:outline-none focus:border-blue-600">
            </div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-4 space-y-3">
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <h2 class="text-white font-bold">اختيار المنتجات</h2>
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-blue-900 text-blue-100 text-xs cursor-help" title="تظهر المنتجات النافدة دائمًا، وتظهر المنتجات ذات البيع خلال آخر 30 يومًا إذا كانت منخفضة أو قريبة من الحد.">؟</span>
            </div>

            <div class="relative" id="productPicker">
                <input type="search" id="productPickerInput" autocomplete="off" placeholder="اكتب اسم المنتج ثم اختره..." class="w-full rounded-xl bg-gray-800 border border-gray-700 text-white px-4 py-3 focus:border-emerald-500 focus:ring-0 focus:outline-none">
                <div id="productPickerMenu" class="hidden absolute z-30 mt-2 w-full max-h-72 overflow-y-auto rounded-xl border border-gray-700 bg-gray-950 shadow-2xl"></div>
            </div>

            <div id="selectedProducts" class="space-y-2"></div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-4 space-y-3">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div>
                    <h2 class="text-white font-bold">منتجات مخصصة</h2>
                    <p class="text-gray-500 text-xs mt-1">للمنتج غير الموجود أو لون/حجم/مقاس محدد؛ الكمية والتكلفة يمكن تركهما لحين الاستلام.</p>
                </div>
                <button type="button" id="addCustom" class="px-4 py-2 rounded-lg bg-amber-600 text-white font-bold hover:bg-amber-500 transition">+ إضافة منتج مخصص</button>
            </div>
            <div id="customRows" class="space-y-2"></div>
        </div>

        <button class="w-full rounded-xl bg-gradient-to-l from-emerald-600 to-green-500 hover:from-emerald-500 hover:to-green-400 text-white font-black py-4 shadow-lg shadow-emerald-900/30 border border-emerald-300/20 active:scale-[0.99] transition" title="{{ $isEdit ? 'حفظ التعديلات وتحديث تكاليف المنتجات من بياناتها الحالية' : 'حفظ الطلبية كمسودة للمراجعة قبل اعتمادها' }}">{{ $isEdit ? 'حفظ تعديلات المسودة' : 'تجهيز الطلبية للمراجعة' }}</button>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const products = @json($productOptions);
    const existingProductRows = @json($existingProductRows);
    const existingCustomRows = @json($existingCustomRows);
    const isEdit = @json($isEdit);
    const input = document.getElementById('productPickerInput');
    const menu = document.getElementById('productPickerMenu');
    const selected = document.getElementById('selectedProducts');
    let rowIndex = 0;
    let customIndex = 0;

    const money = new Intl.NumberFormat('ar-SA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function renderMenu(term = '') {
        const normalized = term.trim().toLowerCase();
        const matches = products
            .filter((product) => !normalized || product.name.toLowerCase().includes(normalized))
            .slice(0, 30);

        if (!matches.length) {
            menu.innerHTML = '<div class="p-4 text-sm text-gray-400">لا توجد نتائج. يمكنك إضافة منتج مخصص أسفل الصفحة.</div>';
            menu.classList.remove('hidden');
            return;
        }

        menu.innerHTML = matches.map((product) => `
            <button type="button" data-product-id="${product.id}" class="js-pick-product w-full text-right p-3 hover:bg-gray-800 border-b border-gray-800 last:border-b-0">
                <span class="block text-white font-bold">${product.name}</span>
                <span class="block text-xs text-gray-400">المتوفر: ${money.format(product.quantity)} • التكلفة: ${money.format(product.cost_price)} ر.س</span>
            </button>
        `).join('');
        menu.classList.remove('hidden');
    }

    function addProductRow(product, initial = {}) {
        // التحقق من عدم إضافة المنتج مسبقاً لمنع التكرار
        const existingRow = document.querySelector(`#selectedProducts [data-product-id="${product.id}"]`);
        if (existingRow) {
            const qtyInput = existingRow.querySelector('.js-qty');
            if (qtyInput) {
                qtyInput.value = initial.quantity_requested ?? (parseFloat(qtyInput.value || 0) + 1);
                qtyInput.focus();
            }
            return;
        }

        const idx = rowIndex++;
        const hasUnitOptions = product.unit_options.length > 0;
        const unitField = hasUnitOptions
            ? `<select name="items[${idx}][unit_type]" class="rounded bg-gray-800 border border-gray-700 text-white px-3 py-2 focus:outline-none focus:border-blue-600">${product.unit_options.map((option) => `<option value="${option.value}">${option.label}</option>`).join('')}</select>`
            : `<input type="hidden" name="items[${idx}][unit_type]" value="unit">`;

        const wrapper = document.createElement('div');
        wrapper.dataset.productId = product.id;
        wrapper.className = 'grid grid-cols-1 md:grid-cols-[1fr_110px_120px_1fr_90px] gap-2 items-center rounded-xl border border-gray-800 bg-gray-950/60 p-3 text-gray-200';
        wrapper.innerHTML = `
            <div>
                <div class="font-bold text-white">${product.name}</div>
                <div class="text-xs text-gray-400">المتوفر: ${money.format(product.quantity)} • التكلفة: ${money.format(product.cost_price)} ر.س</div>
            </div>
            <input type="hidden" name="items[${idx}][product_id]" value="${product.id}">
            <input name="items[${idx}][quantity_requested]" required type="number" step="0.01" min="0.01" placeholder="الكمية" class="js-qty rounded bg-gray-800 border border-gray-700 text-white px-3 py-2 focus:outline-none focus:border-blue-600">
            ${unitField}
            <input name="items[${idx}][receipt_notes]" maxlength="255" placeholder="ملاحظات إضافية: لون، حجم، مقاس..." title="حدد الحجم أو الألوان المطلوبة هنا" class="rounded bg-gray-800 border border-gray-700 text-white px-3 py-2 focus:outline-none focus:border-blue-600">
            <button type="button" class="rounded bg-red-700 text-white px-3 py-2 js-remove hover:bg-red-600 transition">حذف</button>
        `;
        wrapper.querySelector('.js-remove').addEventListener('click', () => wrapper.remove());
        selected.prepend(wrapper);
        if (initial.quantity_requested !== undefined) wrapper.querySelector('.js-qty').value = initial.quantity_requested;
        if (initial.unit_type) {
            const unitSelect = wrapper.querySelector(`select[name="items[${idx}][unit_type]"]`);
            if (unitSelect) unitSelect.value = initial.unit_type;
        }
        if (initial.receipt_notes) wrapper.querySelector(`input[name="items[${idx}][receipt_notes]"]`).value = initial.receipt_notes;
        if (!initial.product_id) wrapper.querySelector('.js-qty')?.focus();
    }

    input?.addEventListener('focus', () => renderMenu(input.value));
    input?.addEventListener('input', () => renderMenu(input.value));
    menu?.addEventListener('click', (event) => {
        const button = event.target.closest('.js-pick-product');
        if (!button) return;
        const product = products.find((item) => String(item.id) === button.dataset.productId);
        if (!product) return;
        addProductRow(product);
        input.value = '';
        menu.classList.add('hidden');
    });
    document.addEventListener('click', (event) => {
        if (!document.getElementById('productPicker')?.contains(event.target)) {
            menu.classList.add('hidden');
        }
    });

    document.getElementById('purchaseOrderForm')?.addEventListener('submit', async (event) => {
        const hasProductQty = Array.from(document.querySelectorAll('#selectedProducts .js-qty')).some((field) => parseFloat(field.value || '0') > 0);
        const hasCustom = document.querySelectorAll('#customRows [data-custom-row]').length > 0;
        if (!hasProductQty && !hasCustom) {
            event.preventDefault();
            if (typeof Swal !== 'undefined') {
                Swal.fire('تنبيه', 'اختر منتجًا وأدخل كمية أو أضف منتجًا مخصصًا.', 'warning');
            } else {
                alert('اختر منتجًا وأدخل كمية أو أضف منتجًا مخصصًا.');
            }
            return;
        }

        event.preventDefault();
        if (typeof Swal === 'undefined') {
            if (window.confirm(isEdit ? 'سيتم حفظ التعديلات وتحديث التكلفة من بيانات المنتج الحالية. هل تريد المتابعة؟' : 'سيتم حفظ الطلبية كمسودة للمراجعة قبل اعتمادها. هل تريد المتابعة؟')) {
                event.target.submit();
            }
            return;
        }

        const result = await Swal.fire({
            title: isEdit ? 'تأكيد حفظ التعديلات' : 'تأكيد تجهيز الطلبية',
            text: isEdit ? 'سيتم حفظ التعديلات وتحديث تكاليف المنتجات من بياناتها الحالية.' : 'سيتم حفظ الطلبية كمسودة للمراجعة قبل اعتمادها وإرسالها للمورد.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: isEdit ? 'نعم، احفظ التعديلات' : 'نعم، جهز للمراجعة',
            cancelButtonText: 'مراجعة البيانات'
        });
        if (result.isConfirmed) {
            event.target.submit();
        }
    });

    function addCustomRow(initial = {}) {
        const currentIndex = customIndex++;
        const wrapper = document.createElement('div');
        wrapper.dataset.customRow = '1';
        wrapper.className = 'grid grid-cols-1 md:grid-cols-[1fr_120px_140px_1fr_120px_90px] gap-2 bg-gray-950/60 border border-gray-800 rounded-xl p-3';
        wrapper.innerHTML = `
            <input name="custom_items[${currentIndex}][custom_product_name]" required placeholder="اسم المنتج" class="rounded bg-gray-800 border border-gray-700 text-white px-3 py-2 focus:outline-none focus:border-blue-600">
            <input name="custom_items[${currentIndex}][quantity_requested]" type="number" step="0.01" min="0" placeholder="الكمية إن عرفت" class="rounded bg-gray-800 border border-gray-700 text-white px-3 py-2 focus:outline-none focus:border-blue-600">
            <select name="custom_items[${currentIndex}][unit_type]" class="rounded bg-gray-800 border border-gray-700 text-white px-3 py-2 focus:outline-none focus:border-blue-600"><option value="unit">بدون تحديد</option><option value="roll">رول</option><option value="meter">متر</option><option value="piece">حبة</option><option value="kit">طقم</option></select>
            <input name="custom_items[${currentIndex}][receipt_notes]" maxlength="255" placeholder="ملاحظات إضافية: لون، حجم، مقاس..." class="rounded bg-gray-800 border border-gray-700 text-white px-3 py-2 focus:outline-none focus:border-blue-600">
            <input name="custom_items[${currentIndex}][cost_price_at_order]" type="number" step="0.01" min="0" placeholder="التكلفة إن عرفت" class="rounded bg-gray-800 border border-gray-700 text-white px-3 py-2 focus:outline-none focus:border-blue-600">
            <button type="button" class="rounded bg-red-700 text-white px-3 py-2 js-remove hover:bg-red-600 transition">حذف</button>
        `;
        wrapper.querySelector('.js-remove').addEventListener('click', () => wrapper.remove());
        document.getElementById('customRows').appendChild(wrapper);
        wrapper.querySelector(`input[name="custom_items[${currentIndex}][custom_product_name]"]`).value = initial.custom_product_name || '';
        wrapper.querySelector(`input[name="custom_items[${currentIndex}][quantity_requested]"]`).value = initial.quantity_requested ?? '';
        wrapper.querySelector(`select[name="custom_items[${currentIndex}][unit_type]"]`).value = initial.unit_type || 'unit';
        wrapper.querySelector(`input[name="custom_items[${currentIndex}][receipt_notes]"]`).value = initial.receipt_notes || '';
        wrapper.querySelector(`input[name="custom_items[${currentIndex}][cost_price_at_order]"]`).value = initial.cost_price_at_order ?? '';
        if (!initial.custom_product_name) wrapper.querySelector('input')?.focus();
    }

    document.getElementById('addCustom')?.addEventListener('click', () => addCustomRow());

    existingProductRows.forEach((row) => {
        const product = products.find((item) => Number(item.id) === Number(row.product_id));
        if (product) addProductRow(product, row);
    });
    existingCustomRows.forEach((row) => addCustomRow(row));
});
</script>
@endsection
