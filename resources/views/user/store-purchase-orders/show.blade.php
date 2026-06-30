@extends('dashboard.app')
@section('title', 'طلبية توريد #' . $order->id)
@section('content')
@php($labels = ['draft'=>'مسودة','sent'=>'مرسلة','received'=>'تم الاستلام','approved'=>'معتمدة','cancelled'=>'ملغية'])

<div class="max-w-7xl mx-auto p-4 sm:p-6 space-y-6" dir="rtl">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-gray-900 border border-gray-800 p-4 rounded-2xl shadow-sm">
        <div>
            <h1 class="text-2xl font-black text-white">طلبية توريد #{{ $order->id }}</h1>
            <p class="text-gray-400 text-sm mt-1">{{ $order->supplier_name ?: 'بدون مورد' }} • {{ $labels[$order->status] ?? $order->status }}</p>
        </div>
        <div class="flex gap-2 flex-wrap">
            <a href="{{ route('user.stores.purchase-orders.index', $store->id) }}" class="px-4 py-2 rounded-xl bg-blue-700 text-white text-sm font-bold hover:bg-blue-600 transition shadow-sm">عودة</a>
            @if(in_array($order->status, ['sent','received','approved'], true))
                <a id="purchaseOrderPdfLink" href="{{ route('user.stores.purchase-orders.pdf', [$store->id, $order->id]) }}" class="px-4 py-2 rounded-xl bg-purple-700 text-white text-sm font-bold shadow-sm hover:bg-purple-600 transition">تصدير PDF للاستلام</a>
                <a id="whatsappLink" target="_blank" href="https://wa.me/?text={{ rawurlencode($whatsappText) }}" class="px-4 py-2 rounded-xl bg-green-700 text-white text-sm font-bold hover:bg-green-600 transition shadow-sm">إرسال واتساب</a>
            @endif
        </div>
    </div>

    @if($errors->any())<div class="rounded-xl border border-red-800 bg-red-950/40 p-4 text-red-200 text-sm">{{ $errors->first() }}</div>@endif

    <div class="flex gap-2 flex-wrap">
        @if($order->status === 'draft')
            <a href="{{ route('user.stores.purchase-orders.edit', [$store->id, $order->id]) }}" class="px-4 py-2 rounded-xl bg-blue-700 hover:bg-blue-600 text-white text-sm font-bold shadow-sm transition">تعديل الطلبية</a>
            <form method="POST" action="{{ route('user.stores.purchase-orders.cancel', [$store->id, $order->id]) }}" onsubmit="return confirm('هل تريد إلغاء هذه الطلبية؟')">@csrf<button class="px-4 py-2 rounded-xl bg-red-700 hover:bg-red-600 text-white text-sm font-bold shadow-sm transition">إلغاء الطلبية</button></form>
            <form method="POST" action="{{ route('user.stores.purchase-orders.mark-sent', [$store->id, $order->id]) }}" onsubmit="return confirm('بعد إرسال الطلبية سيتم فتح واتساب وتجهيز PDF ولن يمكن تعديلها. هل تريد المتابعة؟')">@csrf<button class="px-4 py-2 rounded-xl bg-green-700 hover:bg-green-600 text-white text-sm font-bold shadow-sm transition">إرسال</button></form>
        @elseif($order->status === 'sent')
            <div class="rounded-xl border border-green-500/50 bg-green-600 px-4 py-2 text-white text-sm font-black shadow-lg shadow-green-950/30">✅ تم اعتماد الطلبية وإرسالها للمورد بنجاح. الخطوة التالية: اعتماد الاستلام.</div>
        @elseif($order->status === 'received')
            <div class="w-full rounded-xl border border-green-500/50 bg-green-600 px-4 py-3 text-white text-sm font-black shadow-lg shadow-green-950/30">✅ تم اعتماد بيانات الاستلام بنجاح. الخطوة التالية: الاعتماد المخزني.</div>
            <a target="_blank" href="https://wa.me/?text={{ rawurlencode($whatsappText) }}" class="px-4 py-2 rounded-xl bg-green-700 hover:bg-green-600 text-white text-sm font-bold shadow-sm transition">إرسال واتساب للموزع</a>
            <a href="{{ route('user.stores.purchase-orders.pdf', [$store->id, $order->id]) }}" class="px-4 py-2 rounded-xl bg-purple-700 hover:bg-purple-600 text-white text-sm font-bold shadow-sm transition">تحميل ملف الاستلام</a>
        @endif
    </div>

    @if(in_array($order->status, ['draft','received','approved','cancelled'], true))
        <div class="p-5 bg-gray-900 border border-gray-800 rounded-2xl shadow-sm space-y-4">
            <div>
                <h2 class="text-white font-black text-base">{{ $order->status === 'draft' ? 'مراجعة بنود الطلبية قبل الاعتماد' : 'ملخص بنود الطلبية' }}</h2>
                <p class="text-gray-400 text-xs mt-1">
                    @if($order->status === 'draft')
                        يمكنك تعديل البنود أو إلغاء المسودة قبل ضغط اعتماد الطلبية.
                    @elseif($order->status === 'received')
                        تم اعتماد الاستلام، ويمكن الآن تنفيذ الاعتماد المخزني للمستلم فقط.
                    @else
                        عرض مختصر للبنود والكميات المسجلة في الطلبية.
                    @endif
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($order->items as $item)
                    <?php
                        $variance = (float) ($item->price_variance ?? 0);
                        $productForCost = $item->product ?: $item->matchedProduct;
                        $currentProductCost = (float) ($productForCost?->cost_price ?? 0);
                        $receivedQuantity = (float) ($item->quantity_received ?? 0);
                        $receiptCost = (float) ($item->cost_price_at_receipt ?? $item->cost_price_at_order ?? 0);
                        $newProductCost = $currentProductCost;
                        if ($item->update_product_cost && $productForCost && $receivedQuantity > 0 && $receiptCost > 0) {
                            $unitReceiptCost = $receiptCost / $receivedQuantity;
                            if (in_array($item->unit_type, ['meter', 'meters'], true) && (float) ($productForCost->roll_length ?? 0) > 0) {
                                $newProductCost = round($unitReceiptCost * (float) $productForCost->roll_length, 2);
                            } elseif ($item->unit_type === 'piece' && (int) ($productForCost->items_per_unit ?? 0) > 0) {
                                $newProductCost = round($unitReceiptCost * (int) $productForCost->items_per_unit, 2);
                            } else {
                                $newProductCost = round($unitReceiptCost, 2);
                            }
                        }
                    ?>
                    <div class="rounded-2xl border border-gray-800 bg-gray-950/50 p-4 space-y-3 text-gray-200">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-white font-black text-sm">{{ $item->productName() }}</p>
                                @if($item->receipt_notes)
                                    <p class="text-amber-300 text-xs mt-1">{{ $item->receipt_notes }}</p>
                                @endif
                            </div>
                            <span class="rounded-lg bg-gray-900 px-2 py-1 text-[11px] text-gray-400">{{ $item->unit_type ?: 'unit' }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div class="rounded-xl bg-gray-900 border border-gray-800 p-2">
                                <span class="block text-gray-500">المطلوب</span>
                                <strong class="text-white">{{ (float) $item->quantity_requested > 0 ? number_format($item->quantity_requested, 2) : 'غير محدد' }}</strong>
                            </div>
                            <div class="rounded-xl bg-gray-900 border border-gray-800 p-2">
                                <span class="block text-gray-500">تكلفة الطلب</span>
                                <strong class="text-white">{{ number_format((float) $item->cost_price_at_order, 2) }} ر.س</strong>
                            </div>
                            <div class="rounded-xl bg-gray-900 border border-gray-800 p-2">
                                <span class="block text-gray-500">المستلم</span>
                                <strong class="text-white">{{ $item->quantity_received !== null ? number_format((float) $item->quantity_received, 2) : '-' }}</strong>
                            </div>
                            <div class="rounded-xl bg-gray-900 border border-gray-800 p-2">
                                <span class="block text-gray-500">تكلفة الاستلام</span>
                                <strong class="text-white">{{ $item->cost_price_at_receipt !== null ? number_format((float) $item->cost_price_at_receipt, 2).' ر.س' : '-' }}</strong>
                            </div>
                            @if($order->status === 'received')
                                <div class="rounded-xl bg-gray-900 border border-gray-800 p-2">
                                    <span class="block text-gray-500">فرق السعر</span>
                                    @if($variance > 0)
                                        <strong class="text-red-400">أكثر بـ {{ number_format($variance, 2) }} ر.س</strong>
                                    @elseif($variance < 0)
                                        <strong class="text-emerald-400">أقل بـ {{ number_format(abs($variance), 2) }} ر.س</strong>
                                    @else
                                        <strong class="text-gray-400">لا يوجد فرق</strong>
                                    @endif
                                </div>
                                <div class="rounded-xl bg-gray-900 border border-gray-800 p-2">
                                    <span class="block text-gray-500">تكلفة المنتج</span>
                                    <strong class="text-white">الحالية: {{ number_format($currentProductCost, 2) }} ر.س</strong>
                                    @if($item->update_product_cost)
                                        <span class="block text-red-300 mt-1">الجديدة: {{ number_format($newProductCost, 2) }} ر.س</span>
                                    @else
                                        <span class="block text-gray-500 mt-1">لن يتم تحديثها</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if($order->status === 'sent')
    <form method="POST" action="{{ route('user.stores.purchase-orders.receive', [$store->id, $order->id]) }}" class="space-y-4">
        @csrf
        <div class="p-5 bg-blue-950/40 border border-blue-600/40 rounded-2xl shadow-sm space-y-3">
            <h2 class="text-white font-black text-base">اعتماد بيانات الاستلام ومراجعة الأسعار</h2>
            <p class="text-blue-100 text-sm leading-relaxed">هذه الخطوة تحفظ بيانات الاستلام فقط ولا تضيف أي كمية للمخزون. بعد اعتماد الاستلام ستظهر لك خطوة الاعتماد المخزني لإضافة الكميات المعتمدة فعليًا.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-xs">
                <div class="rounded-xl bg-gray-950/50 border border-blue-900/50 p-3 text-gray-200">
                    <strong class="block text-white mb-1">إذا تركت الكمية فارغة</strong>
                    سيتم اعتماد كمية الطلب كاملة ككمية مستلمة.
                </div>
                <div class="rounded-xl bg-gray-950/50 border border-blue-900/50 p-3 text-gray-200">
                    <strong class="block text-white mb-1">إذا تركت السعر فارغًا</strong>
                    سيتم اعتماد تكلفة النظام الحالية لهذا البند.
                </div>
                <div class="rounded-xl bg-gray-950/50 border border-blue-900/50 p-3 text-gray-200">
                    <strong class="block text-white mb-1">إذا تغيّر السعر</strong>
                    سيظهر الفرق بوضوح، ولن يتم تحديث تكلفة المنتج إلا عند اختيار اعتماد تحديث السعر.
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($order->items as $item) { ?>
                <?php
                    $baseCost = (float) ($item->product?->cost_price ?? $item->cost_price_at_order ?? 0);
                    $receiveUnits = ['unit' => ['label' => 'افتراضي', 'cost' => $baseCost]];

                    if ($item->product && ((($item->product->product_type ?? null) === 'fractional') || (float) $item->product->roll_length > 0)) {
                        $rollLength = (float) $item->product->roll_length;
                        $receiveUnits = [
                            'roll' => ['label' => 'رول', 'cost' => $baseCost],
                            'meter' => ['label' => 'متر', 'cost' => $rollLength > 0 ? $baseCost / $rollLength : 0],
                        ];
                    } elseif ($item->product && $item->product->is_splittable) {
                        $itemsPerUnit = (int) $item->product->items_per_unit;
                        $receiveUnits = [
                            'kit' => ['label' => 'طقم', 'cost' => $baseCost],
                            'piece' => ['label' => 'حبة', 'cost' => $itemsPerUnit > 0 ? $baseCost / $itemsPerUnit : 0],
                        ];
                    }
                    $hasUnitChoices = count($receiveUnits) > 1;
                    $variance = (float) ($item->price_variance ?? 0);
                    $varianceText = $variance > 0
                        ? 'أكثر من النظام بـ: ' . number_format($variance, 2) . ' ر.س'
                        : ($variance < 0 ? 'أقل من النظام بـ: ' . number_format(abs($variance), 2) . ' ر.س' : 'لا يوجد فرق سعر');
                ?>
                <div class="rounded-2xl border {{ $variance > 0 ? 'border-red-500/80 bg-red-950/30' : ($variance < 0 ? 'border-emerald-500/70 bg-emerald-950/20' : 'border-gray-800 bg-gray-900') }} p-5 space-y-4 text-gray-200 flex flex-col justify-between shadow-sm hover:border-gray-700 transition relative">
                    <div class="space-y-3">
                        <div class="space-y-2">
                            <div class="font-black text-sm text-white leading-tight">{{ $item->productName() }}</div>
                            <div class="flex justify-between items-start gap-2">
                                @if($item->receipt_notes)
                                    <div class="text-xs font-normal text-amber-400 bg-amber-950/40 border border-amber-900/50 px-2 py-0.5 rounded-md inline-block">{{ $item->receipt_notes }}</div>
                                @else
                                    <span></span>
                                @endif
                                <div id="variance-{{ $item->id }}" class="text-xs font-black px-2 py-1 rounded-lg whitespace-nowrap {{ $variance > 0 ? 'bg-red-600 text-white' : ($variance < 0 ? 'bg-emerald-700 text-white' : 'bg-gray-950/60 text-gray-400') }}">
                                    {{ $varianceText }}
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2 text-xs text-gray-400 bg-gray-950/50 p-2.5 rounded-xl border border-gray-800">
                            <div>كمية الطلب: <span class="text-white font-bold">{{ (float) $item->quantity_requested > 0 ? number_format($item->quantity_requested, 2) : 'غير محدد' }}</span></div>
                            <div>تكلفة النظام: <span class="text-white font-bold">{{ number_format((float) $item->cost_price_at_order, 2) }} ر.س</span></div>
                        </div>

                        <div class="grid grid-cols-1 {{ $hasUnitChoices ? 'sm:grid-cols-2' : '' }} gap-2">
                            <div class="space-y-1">
                                <label class="block text-[11px] font-bold text-gray-400">الكمية المستلمة</label>
                                <input name="items[{{ $item->id }}][quantity_received]" type="number" step="0.01" min="0" value="{{ old('items.'.$item->id.'.quantity_received', $item->quantity_received) }}" placeholder="فارغ = كمية الطلب كاملة" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white px-3 py-1.5 text-sm focus:border-gray-600 focus:outline-none">
                            </div>
                            @if($hasUnitChoices)
                                <div class="space-y-1">
                                    <label class="block text-[11px] font-bold text-gray-400">الوحدة الواصلة</label>
                                    <select name="items[{{ $item->id }}][unit_type]" class="js-receipt-unit w-full rounded-lg bg-gray-800 border border-gray-700 text-white px-2 py-1.5 text-xs focus:border-gray-600 focus:outline-none">
                                        <?php foreach ($receiveUnits as $unitValue => $unit) { ?>
                                            <option value="{{ $unitValue }}" data-unit-cost="{{ (float) $unit['cost'] }}" {{ $item->unit_type === $unitValue ? 'selected' : '' }}>
                                                {{ $unit['label'] }} {{ $unit['cost'] > 0 ? '('.number_format($unit['cost'], 2).')' : '' }}
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            @else
                                <input type="hidden" name="items[{{ $item->id }}][unit_type]" value="{{ $item->unit_type ?: 'unit' }}" class="js-receipt-unit" data-unit-cost="{{ $baseCost }}">
                            @endif
                        </div>

                        <div class="space-y-1">
                            <label class="block text-[11px] font-bold text-gray-400">سعر الاستلام الفعلي (الإجمالي للمندوب)</label>
                            <input name="items[{{ $item->id }}][cost_price_at_receipt]" type="number" step="0.01" min="0" value="{{ old('items.'.$item->id.'.cost_price_at_receipt', $item->cost_price_at_receipt) }}" placeholder="فارغ = تكلفة النظام" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white px-3 py-1.5 text-sm js-receipt-price focus:border-gray-600 focus:outline-none" data-order-price="{{ (float) $item->cost_price_at_order }}" data-requested-qty="{{ (float) $item->quantity_requested }}" data-variance-target="variance-{{ $item->id }}">
                        </div>
                    </div>

                    <div class="pt-3 border-t border-gray-800 flex flex-col gap-2 mt-auto">
                        <div class="flex items-center justify-between text-xs">
                            <label class="flex items-center gap-2 cursor-pointer text-gray-400 select-none">
                                <input type="hidden" name="items[{{ $item->id }}][update_product_cost]" value="0">
                                <input type="checkbox" name="items[{{ $item->id }}][update_product_cost]" value="1" @checked($item->update_product_cost) class="rounded bg-gray-800 border-gray-700 text-emerald-600 focus:ring-0">
                                <span>اعتماد تحديث السعر</span>
                            </label>

                            @if(!$item->product_id)
                                <button type="button" data-product-store-url="{{ route('user.stores.products.store', $store->id) }}" class="js-open-product-modal text-amber-400 font-bold hover:text-amber-300 transition text-[11px]">+ إنشاء منتج سريع</button>
                            @endif
                        </div>

                        @if(!$item->product_id)
                            <div class="relative mt-1 js-dropdown-container">
                                <button type="button" class="js-dropdown-toggle w-full rounded-lg bg-gray-950 border border-gray-800 text-gray-300 px-3 py-1.5 text-right text-xs flex justify-between items-center hover:bg-gray-800 transition">
                                    <span class="js-selected-label">{{ $item->matchedProduct ? $item->matchedProduct->name : 'ربط بمنتج من المخزن...' }}</span>
                                    <svg class="w-3 h-3 text-gray-500 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </button>
                                <div class="js-dropdown-menu hidden absolute z-30 bottom-full mb-1 left-0 right-0 rounded-xl border border-gray-700 bg-gray-950 p-2 shadow-2xl space-y-2 max-h-48 overflow-y-auto">
                                    <input type="search" placeholder="اكتب 3 أحرف على الأقل للبحث..." class="js-match-filter w-full rounded-lg bg-gray-900 border border-gray-800 text-white px-2 py-1 text-xs focus:outline-none focus:border-gray-700">
                                    <div class="space-y-0.5 js-options-list">
                                        <div data-value="" class="js-option-item px-3 py-1.5 rounded-lg text-xs text-red-400 hover:bg-gray-900 cursor-pointer">إلغاء المواءمة</div>
                                        <?php foreach ($products as $product) { ?>
                                            <div data-value="{{ $product->id }}" data-search="{{ strtolower($product->name) }}" class="js-option-item px-3 py-1.5 rounded-lg text-xs text-gray-300 hover:bg-gray-900 hover:text-white cursor-pointer {{ $item->matched_product_id == $product->id ? 'bg-gray-900 text-white font-bold' : '' }}">
                                                {{ $product->name }}
                                            </div>
                                        <?php } ?>
                                    </div>
                                    <input type="hidden" name="items[{{ $item->id }}][matched_product_id]" value="{{ $item->matched_product_id }}" class="js-hidden-input">
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            <?php } ?>
        </div>

        @if($order->status === 'sent')
            <div class="pt-2"><button class="w-full rounded-xl bg-green-700 hover:bg-green-600 border border-green-400/50 shadow-lg shadow-green-950/30 text-white font-black py-3.5 text-sm transition">اعتماد الاستلام</button></div>
        @endif
    </form>
    @endif

    @if($order->status === 'received')
        <form id="approveOrderForm" method="POST" action="{{ route('user.stores.purchase-orders.approve', [$store->id, $order->id]) }}" class="bg-red-950/30 border border-red-700/70 rounded-2xl p-5 space-y-3 shadow-sm shadow-red-950/20">
            @csrf
            <h3 class="text-white font-black text-sm">اعتماد وإغلاق الطلبية</h3>
            <p class="text-gray-400 text-xs">دمج وإضافة المنتجات المستلمة إلى المخزون عبر مسار التوريد وسجل حركة المخزون، بعد التحقق من ربط المنتجات المخصصة ومنع فروقات التكلفة غير المؤكدة.</p>
            <button class="w-full rounded-xl bg-red-700 hover:bg-red-600 text-white font-black py-3.5 text-sm transition shadow-lg shadow-red-950/30">اعتماد وإضافة المستلم للمخزون وإغلاق الطلبية</button>
        </form>
    @endif
</div>

<div id="productCreateModal" class="hidden fixed inset-0 z-50 bg-black/80 p-4 overflow-y-auto" dir="rtl">
    <div class="mx-auto max-w-2xl rounded-2xl border border-gray-800 bg-gray-950 shadow-2xl my-8">
        <div class="flex items-center justify-between bg-gray-900 border-b border-gray-800 p-4 rounded-t-2xl">
            <div>
                <strong class="text-white text-sm">إنشاء منتج جديد سريع</strong>
                <p class="text-[11px] text-gray-400 mt-0.5">سيضاف لقائمة المواءمة مباشرة بعد الحفظ.</p>
            </div>
            <button type="button" id="closeProductCreateModal" class="rounded-lg bg-gray-800 text-gray-300 px-2.5 py-1 text-xs hover:bg-gray-700 transition">إغلاق</button>
        </div>
        <form id="quickProductForm" class="p-5 space-y-4 text-sm" method="POST">
            @csrf
            <div id="quickProductErrors" class="hidden rounded-xl border border-red-500/40 bg-red-950/40 p-3 text-xs text-red-200"></div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block sm:col-span-2"><span class="block text-xs font-bold text-gray-400 mb-1">اسم المنتج</span><input name="name" required class="w-full rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm focus:outline-none focus:border-gray-700" placeholder="مثال: رباط طيس بلاستيك مخصص"></label>
                <label class="block"><span class="block text-xs font-bold text-gray-400 mb-1">القسم</span><select name="category_id" required class="w-full rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm focus:outline-none focus:border-gray-700"><?php foreach ($categories as $category) { ?><option value="{{ $category->id }}">{{ $category->name }}</option><?php } ?></select></label>
                <label class="block"><span class="block text-xs font-bold text-gray-400 mb-1">نوع المنتج</span><select name="product_type" id="quickProductType" class="w-full rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm focus:outline-none focus:border-gray-700"><option value="standard">عادي / حبة أو طقم</option><option value="fractional">رول / متر</option></select></label>
                <label class="block"><span class="block text-xs font-bold text-gray-400 mb-1">سعر البيع</span><input name="price" type="number" step="0.01" min="0" required class="w-full rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm focus:outline-none focus:border-gray-700"></label>
                <label class="block"><span class="block text-xs font-bold text-gray-400 mb-1">تكلفة الشراء</span><input name="cost_price" type="number" step="0.01" min="0" class="w-full rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm focus:outline-none focus:border-gray-700"></label>
                <label class="block quick-standard-field"><span class="block text-xs font-bold text-gray-400 mb-1">الكمية الحالية</span><input name="quantity" type="number" step="0.01" min="0" value="0" class="w-full rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm focus:outline-none focus:border-gray-700"></label>
                <label class="block"><span class="block text-xs font-bold text-gray-400 mb-1">الحد الأدنى</span><input name="min_stock" type="number" step="0.01" min="0" value="1" class="w-full rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm focus:outline-none focus:border-gray-700"></label>
                <label class="quick-roll-field hidden"><span class="block text-xs font-bold text-gray-400 mb-1">عدد الرولات</span><input name="num_rolls" type="number" step="0.01" min="0" value="0" class="w-full rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm focus:outline-none focus:border-gray-700"></label>
                <label class="quick-roll-field hidden"><span class="block text-xs font-bold text-gray-400 mb-1">طول الرول بالمتر</span><input name="roll_length" type="number" step="0.01" min="0" value="30" class="w-full rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm focus:outline-none focus:border-gray-700"></label>
                <label class="block sm:col-span-2 quick-roll-field hidden"><span class="block text-xs font-bold text-gray-400 mb-1">خيار قص افتراضي</span><div class="grid grid-cols-1 sm:grid-cols-3 gap-2"><input name="fractions[0][option_label]" value="متر" class="rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm"><input name="fractions[0][deduction_value]" type="number" step="0.01" min="0" value="1" class="rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm"><input name="fractions[0][price]" type="number" step="0.01" min="0" placeholder="سعر المتر" class="rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm"></div></label>
                <label class="flex items-center gap-2 rounded-xl border border-gray-800 bg-gray-900 p-3 quick-standard-field sm:col-span-2"><input type="checkbox" name="is_splittable" value="1" class="rounded bg-gray-800 border-gray-700 text-emerald-600 focus:ring-0"><span class="text-xs text-gray-300">يباع كطقم ويمكن بيعه بالحبة</span></label>
                <label class="block quick-standard-field"><span class="block text-xs font-bold text-gray-400 mb-1">عدد الحبات في الطقم</span><input name="items_per_unit" type="number" min="1" value="1" class="w-full rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm focus:outline-none focus:border-gray-700"></label>
                <label class="block quick-standard-field"><span class="block text-xs font-bold text-gray-400 mb-1">سعر الحبة</span><input name="piece_price" type="number" step="0.01" min="0" class="w-full rounded-lg bg-gray-900 border border-gray-800 text-white px-3 py-2 text-sm focus:outline-none focus:border-gray-700"></label>
            </div>
            <input type="hidden" name="status" value="active">
            <div class="flex flex-col sm:flex-row gap-2 sm:justify-end border-t border-gray-800 pt-4"><button type="button" id="cancelQuickProduct" class="rounded-xl bg-gray-800 text-gray-300 px-4 py-2 font-bold text-xs hover:bg-gray-700 transition">إلغاء</button><button class="rounded-xl bg-emerald-700 hover:bg-emerald-600 text-white font-bold px-5 py-2 text-xs shadow-sm transition">حفظ وإضافة للمواءمة</button></div>
        </form>
    </div>
</div>

<?php
    $stockApprovalCostChanges = $order->status === 'received'
        ? $order->items->filter(fn ($item) => $item->update_product_cost)->map(function ($item) {
            $product = $item->product ?: $item->matchedProduct;
            $currentCost = (float) ($product?->cost_price ?? 0);
            $quantity = (float) ($item->quantity_received ?? 0);
            $receiptCost = (float) ($item->cost_price_at_receipt ?? 0);
            $newCost = $currentCost;

            if ($product && $quantity > 0 && $receiptCost > 0) {
                $unitReceiptCost = $receiptCost / $quantity;
                if (in_array($item->unit_type, ['meter', 'meters'], true) && (float) ($product->roll_length ?? 0) > 0) {
                    $newCost = round($unitReceiptCost * (float) $product->roll_length, 2);
                } elseif ($item->unit_type === 'piece' && (int) ($product->items_per_unit ?? 0) > 0) {
                    $newCost = round($unitReceiptCost * (int) $product->items_per_unit, 2);
                } else {
                    $newCost = round($unitReceiptCost, 2);
                }
            }

            return [
                'name' => $item->productName(),
                'current_cost' => $currentCost,
                'new_cost' => $newCost,
            ];
        })->values()
        : collect();
?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const stockApprovalCostChanges = @json($stockApprovalCostChanges);
    document.querySelectorAll('.js-dropdown-toggle').forEach((toggle) => {
        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const container = toggle.closest('.js-dropdown-container');
            const menu = container.querySelector('.js-dropdown-menu');
            const filterInput = menu.querySelector('.js-match-filter');
            const optionsList = menu.querySelector('.js-options-list');

            document.querySelectorAll('.js-dropdown-menu').forEach((m) => {
                if (m !== menu) m.classList.add('hidden');
            });
            document.querySelectorAll('.js-dropdown-toggle svg').forEach((svg) => {
                if (svg !== toggle.querySelector('svg')) svg.classList.remove('rotate-180');
            });

            menu.classList.toggle('hidden');
            toggle.querySelector('svg').classList.toggle('rotate-180');

            if (!menu.classList.contains('hidden')) {
                if (filterInput) {
                    filterInput.value = '';
                    filterInput.focus();
                }

                optionsList.querySelectorAll('.js-option-item').forEach((item) => {
                    item.style.display = item.dataset.value === '' ? 'block' : 'none';
                });
            }
        });
    });

    document.querySelectorAll('.js-match-filter').forEach((input) => {
        input.addEventListener('input', () => {
            const term = input.value.trim().toLowerCase();
            const list = input.parentElement.querySelector('.js-options-list');

            list.querySelectorAll('.js-option-item').forEach((item) => {
                if (!item.dataset.value) {
                    item.style.display = 'block';
                    return;
                }

                item.style.display = term.length >= 3 && item.dataset.search.includes(term) ? 'block' : 'none';
            });
        });
    });

    document.querySelectorAll('.js-option-item').forEach((item) => {
        item.addEventListener('click', () => {
            const container = item.closest('.js-dropdown-container');
            const toggle = container.querySelector('.js-dropdown-toggle');
            const hiddenInput = container.querySelector('.js-hidden-input');
            const label = toggle.querySelector('.js-selected-label');

            hiddenInput.value = item.dataset.value;
            label.textContent = item.textContent.trim();

            container.querySelector('.js-dropdown-menu').classList.add('hidden');
            toggle.querySelector('svg').classList.remove('rotate-180');

            const rowInput = container.closest('.rounded-2xl').querySelector('.js-receipt-price');
            rowInput?.dispatchEvent(new Event('input'));
        });
    });

    document.addEventListener('click', () => {
        document.querySelectorAll('.js-dropdown-menu').forEach((m) => m.classList.add('hidden'));
        document.querySelectorAll('.js-dropdown-toggle svg').forEach((s) => s.classList.remove('rotate-180'));
    });

    const modal = document.getElementById('productCreateModal');
    const quickProductForm = document.getElementById('quickProductForm');
    const quickProductType = document.getElementById('quickProductType');
    const quickProductErrors = document.getElementById('quickProductErrors');
    let activeContainer = null;

    const closeQuickProductModal = () => {
        modal?.classList.add('hidden');
        quickProductErrors?.classList.add('hidden');
    };
    const toggleQuickProductFields = () => {
        const isRoll = quickProductType?.value === 'fractional';
        document.querySelectorAll('.quick-roll-field').forEach((el) => el.classList.toggle('hidden', !isRoll));
        document.querySelectorAll('.quick-standard-field').forEach((el) => el.classList.toggle('hidden', isRoll));
    };
    quickProductType?.addEventListener('change', toggleQuickProductFields);
    toggleQuickProductFields();

    document.querySelectorAll('.js-open-product-modal').forEach((button) => {
        button.addEventListener('click', () => {
            activeContainer = button.closest('.js-dropdown-container') || button.closest('.rounded-2xl').querySelector('.js-dropdown-container');
            quickProductForm?.setAttribute('action', button.dataset.productStoreUrl);
            quickProductForm?.reset();
            toggleQuickProductFields();
            modal?.classList.remove('hidden');
        });
    });
    document.getElementById('closeProductCreateModal')?.addEventListener('click', closeQuickProductModal);
    document.getElementById('cancelQuickProduct')?.addEventListener('click', closeQuickProductModal);

    quickProductForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        quickProductErrors?.classList.add('hidden');
        const submitButton = quickProductForm.querySelector('button[type="submit"], button:not([type])');
        submitButton?.setAttribute('disabled', 'disabled');
        try {
            const response = await fetch(quickProductForm.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(quickProductForm),
            });
            const data = await response.json();
            if (!response.ok) {
                const errors = data.errors ? Object.values(data.errors).flat() : [data.message || 'تعذر إنشاء المنتج.'];
                quickProductErrors.innerHTML = errors.map((error) => `<div>${error}</div>`).join('');
                quickProductErrors.classList.remove('hidden');
                return;
            }
            if (activeContainer && data.product) {
                const hiddenInput = activeContainer.querySelector('.js-hidden-input');
                const label = activeContainer.querySelector('.js-selected-label');
                const optionsList = activeContainer.querySelector('.js-options-list');

                hiddenInput.value = data.product.id;
                label.textContent = data.product.name;

                const newOpt = document.createElement('div');
                newOpt.dataset.value = data.product.id;
                newOpt.dataset.search = data.product.name.toLowerCase();
                newOpt.className = 'js-option-item px-3 py-1.5 rounded-lg text-xs text-gray-300 hover:bg-gray-900 hover:text-white cursor-pointer bg-gray-900 text-white font-bold';
                newOpt.textContent = data.product.name;
                optionsList.appendChild(newOpt);
            }
            closeQuickProductModal();
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'تم إنشاء المنتج', text: 'تم ربطه بالبطاقة بنجاح.', timer: 1850, showConfirmButton: false });
            }
        } catch (error) {
            quickProductErrors.textContent = 'تعذر الاتصال بالخادم، حاول مرة أخرى.';
            quickProductErrors.classList.remove('hidden');
        } finally {
            submitButton?.removeAttribute('disabled');
        }
    });

    document.querySelectorAll('.js-receipt-price').forEach((input) => {
        const updateVariance = () => {
            const receiptInputBlank = input.value === null || input.value === '';
            const orderPrice = parseFloat(input.dataset.orderPrice || '0');
            const requested = parseFloat(input.dataset.requestedQty || '0');
            const card = input.closest('.rounded-2xl');
            const receivedInput = card?.querySelector('input[name$="[quantity_received]"]');
            const received = receivedInput?.value ? parseFloat(receivedInput.value) : requested;
            const unitElement = card?.querySelector('.js-receipt-unit');
            const selectedUnit = unitElement?.tagName === 'SELECT' ? unitElement.querySelector('option:checked') : unitElement;
            const unitCost = parseFloat(selectedUnit?.dataset.unitCost || '0');

            const expected = unitCost > 0
                ? unitCost * received
                : (requested > 0 ? orderPrice / requested : orderPrice) * received;
            const receipt = receiptInputBlank ? expected : parseFloat(input.value || '0');
            const target = document.getElementById(input.dataset.varianceTarget);
            const variance = receipt > 0 ? receipt - expected : 0;

            if (target) {
                target.textContent = variance > 0
                    ? 'أكثر من النظام بـ: ' + variance.toFixed(2) + ' ر.س'
                    : (variance < 0 ? 'أقل من النظام بـ: ' + Math.abs(variance).toFixed(2) + ' ر.س' : 'لا يوجد فرق سعر');
                target.className = 'text-xs font-black px-2 py-1 rounded-lg whitespace-nowrap';
                if (variance > 0) target.classList.add('bg-red-600', 'text-white');
                else if (variance < 0) target.classList.add('bg-emerald-700', 'text-white');
                else target.classList.add('bg-gray-950/60', 'text-gray-400');
            }

            const costCheckbox = card?.querySelector('input[name$="[update_product_cost]"][type="checkbox"]');
            if (costCheckbox && variance > 0) {
                costCheckbox.checked = true;
            }
        };
        input.addEventListener('input', updateVariance);
        input.closest('.rounded-2xl')?.querySelector('input[name$="[quantity_received]"]')?.addEventListener('input', updateVariance);
        input.closest('.rounded-2xl')?.querySelector('.js-receipt-unit')?.addEventListener('change', updateVariance);
    });

    document.getElementById('approveOrderForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (typeof Swal === 'undefined') {
            if (window.confirm('سيتم اعتماد وإغلاق الطلبية وإضافة الكميات المستلمة للمخزون. هل أنت متأكد؟')) {
                event.target.submit();
            }
            return;
        }
        const costChangesHtml = stockApprovalCostChanges.length
            ? '<div class="mt-3 text-right"><strong>تأكيد تحديث التكلفة:</strong><div class="mt-2 space-y-1">' + stockApprovalCostChanges.map((item) => (
                '<div class="rounded-lg bg-red-950/40 border border-red-700/50 px-3 py-2 text-xs">' +
                item.name + ': الحالية ' + Number(item.current_cost).toFixed(2) + ' ر.س ← الجديدة ' + Number(item.new_cost).toFixed(2) + ' ر.س' +
                '</div>'
            )).join('') + '</div></div>'
            : '<div class="mt-3 text-xs text-gray-300">لا توجد منتجات محددة لتحديث تكلفة المنتج.</div>';
        const result = await Swal.fire({
            title: 'تأكيد اعتماد الطلبية',
            html: 'سيتم إضافة الكميات المستلمة فقط إلى المخزون عبر سجل الحركة، بعدها إغلاق الطلبية كمعتمدة.' + costChangesHtml,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، اعتمد وأغلق',
            cancelButtonText: 'رجوع للمراجعة'
        });
        if (result.isConfirmed) {
            event.target.submit();
        }
    });
});
</script>

@if(session('open_whatsapp') || session('download_pdf'))
<script>
document.addEventListener('DOMContentLoaded', () => {
    @if(session('open_whatsapp'))
        document.getElementById('whatsappLink')?.click();
    @endif
    @if(session('download_pdf'))
        const pdfLink = document.getElementById('purchaseOrderPdfLink');
        if (pdfLink) {
            const frame = document.createElement('iframe');
            frame.src = pdfLink.href;
            frame.className = 'hidden';
            document.body.appendChild(frame);
        }
    @endif
});
</script>
@endif
@endsection
