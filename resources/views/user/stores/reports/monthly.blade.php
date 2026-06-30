@extends('dashboard.app')

@section('title', 'التقرير الشهري - ' . $store->name)

@section('content')
<div class="max-w-7xl mx-auto px-4 py-6 text-right" dir="rtl">
    <div class="mb-6 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-white">التقرير الشهري للمتجر</h1>
            <p class="text-gray-400 text-sm mt-1">{{ $store->name }}</p>
        </div>
        <a href="{{ route('user.stores.reports.index', $store->id) }}" class="text-sm bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
            العودة لمركز التقارير
        </a>
    </div>

    <form method="GET" class="mb-4 flex items-end gap-3 flex-wrap">
        <div>
            <label class="block text-xs text-gray-400 mb-1">الشهر</label>
            <input type="month" name="month" value="{{ $month }}" class="bg-gray-900 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
        </div>
        <button class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-lg text-sm">عرض</button>
    </form>

    <div class="mb-4 bg-gray-900/40 border border-gray-700 rounded-2xl p-4">
        <div class="mb-4">
            <p class="text-white font-semibold text-base">إصدار تقرير PDF</p>
            <p class="text-gray-400 text-xs mt-1">اختر نوع التقرير المناسب قبل التحميل. التقرير المختصر خلاصة مالية فقط، والتقرير التفصيلي يضيف تفاصيل الأيام والاستهلاك والمصروفات والعمال.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <a href="{{ route('user.stores.reports.monthly.pdf', ['store' => $store->id, 'month' => $month]) }}" class="group block rounded-xl border border-emerald-700/60 bg-emerald-600/10 hover:bg-emerald-600/20 p-4 transition">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-emerald-300 font-bold">تقرير مختصر</span>
                    <span class="text-[11px] text-emerald-100 bg-emerald-700/60 px-2 py-1 rounded-full">الأسرع</span>
                </div>
                <p class="text-gray-300 text-xs mt-2">يشمل الملخص المالي، المؤشرات، المصروفات، وصافي النتيجة بدون جدول تفاصيل المبيعات.</p>
                <span class="inline-block mt-3 text-sm text-white bg-emerald-600 group-hover:bg-emerald-500 px-4 py-2 rounded-lg">تحميل المختصر</span>
            </a>
            <a href="{{ route('user.stores.reports.monthly.pdf', ['store' => $store->id, 'month' => $month, 'include_sales_details' => 1]) }}" class="group block rounded-xl border border-cyan-700/60 bg-cyan-600/10 hover:bg-cyan-600/20 p-4 transition">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-cyan-300 font-bold">تقرير تفصيلي</span>
                    <span class="text-[11px] text-cyan-100 bg-cyan-700/60 px-2 py-1 rounded-full">مع التفاصيل</span>
                </div>
                <p class="text-gray-300 text-xs mt-2">يشمل كل بيانات التقرير المختصر مع تفاصيل الأيام والاستهلاك والمصروفات والعمال.</p>
                <span class="inline-block mt-3 text-sm text-white bg-cyan-600 group-hover:bg-cyan-500 px-4 py-2 rounded-lg">تحميل التفصيلي</span>
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-4 text-sm">
        <div class="bg-gray-900/40 border border-gray-700 rounded-xl p-3"><p class="text-gray-400">إجمالي المبيعات</p><p class="text-green-400 font-bold">{{ number_format($totalSales, 2) }} ر.س</p></div>
        <div class="bg-gray-900/40 border border-gray-700 rounded-xl p-3"><p class="text-gray-400">عمليات / كاش / شبكة</p><p class="text-cyan-300 font-bold">{{ number_format($operationsCount) }} / {{ number_format($cashSales, 2) }} / {{ number_format($cardSales, 2) }}</p></div>
        <div class="bg-gray-900/40 border border-gray-700 rounded-xl p-3"><p class="text-gray-400">الاستهلاك الداخلي (المحاسب)</p><p class="text-yellow-300 font-bold">{{ number_format($internalUseSales, 2) }} ر.س</p></div>
        <div class="bg-gray-900/40 border border-gray-700 rounded-xl p-3"><p class="text-gray-400">تكلفة المنتجات المباعة (تخصم من الربح)</p><p class="text-rose-300 font-bold">{{ number_format($profitDeductionTotal ?? $monthlySoldProductsCost ?? 0, 2) }} ر.س</p><p class="text-gray-500 text-xs mt-1">هي تكلفة البضاعة التي تم بيعها خلال الشهر، وليست خصماً منفصلاً.</p></div>
        <div class="bg-gray-900/40 border border-gray-700 rounded-xl p-3"><p class="text-gray-400">مصروفات + مشتريات المالك للاستهلاك</p><p class="text-orange-300 font-bold">{{ number_format($expensesTotal, 2) }} + {{ number_format($ownerPurchases, 2) }} ر.س</p></div>
        <div class="bg-gray-900/40 border border-blue-700/50 rounded-xl p-3"><p class="text-gray-400">الرواتب والسحبيات (للتوضيح فقط)</p><p class="text-blue-200 font-bold">رواتب مستحقة: {{ number_format($monthlySalaries, 2) }} ر.س</p><p class="text-blue-300 font-bold">سحبيات: {{ number_format($withdrawalsTotal, 2) }} ر.س</p><p class="text-gray-500 text-xs mt-1">لا تدخل هذه القيم في معادلة صافي الربح.</p></div>
    </div>

    <div class="mb-4 bg-emerald-500/10 border border-emerald-500/30 rounded-xl p-3 text-emerald-200 text-sm">
        صافي النتيجة بعد التكاليف: <span class="font-bold">{{ number_format($netAfterCosts, 2) }} ر.س</span>
    </div>
    <p class="mb-4 text-xs text-gray-400">
        طريقة الحساب: صافي النتيجة = المحصل - (تكلفة المنتجات المباعة + الاستهلاك الداخلي + مشتريات المالك للاستهلاك + المصروفات). الرواتب وسحبيات الموظفين تظهر للتوضيح فقط ولا تدخل في معادلة الربح.
    </p>



    @php
        $transferSummary = $transferSummary ?? [
            'outgoing_count' => 0,
            'incoming_count' => 0,
            'outgoing_cost' => 0,
            'incoming_cost' => 0,
            'difference' => 0,
            'difference_abs' => 0,
            'difference_type' => 'balanced',
            'difference_label' => 'متعادل',
            'formula_note' => 'الفارق = إجمالي الوارد بسعر التكلفة - إجمالي الصادر بسعر التكلفة',
        ];
    @endphp
    <div class="mb-4 bg-gray-900/40 border border-indigo-700/50 rounded-2xl overflow-hidden">
        <div class="bg-indigo-900/40 px-4 py-3 border-b border-indigo-700/50">
            <h2 class="text-white font-bold">ملخص النقل المخزني للشهر</h2>
            <p class="text-indigo-200 text-xs mt-1">هذا الجدول يظهر إجمالي قيمة البضاعة الصادرة والمستلمة بسعر التكلفة ضمن نفس التقرير الشهري، وليس كتقرير منفصل.</p>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-800/80 text-gray-300">
                <tr>
                    <th class="p-3 text-right">الصادر بسعر التكلفة</th>
                    <th class="p-3 text-right">عدد الصادر</th>
                    <th class="p-3 text-right">المستلم بسعر التكلفة</th>
                    <th class="p-3 text-right">عدد المستلم</th>
                    <th class="p-3 text-right">الفارق الحسابي</th>
                    <th class="p-3 text-right">التصنيف</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-t border-gray-700/70 text-gray-200">
                    <td class="p-3 text-rose-300">{{ number_format($transferSummary['outgoing_cost'] ?? 0, 2) }} ر.س</td>
                    <td class="p-3">{{ number_format($transferSummary['outgoing_count'] ?? 0) }}</td>
                    <td class="p-3 text-emerald-300">{{ number_format($transferSummary['incoming_cost'] ?? 0, 2) }} ر.س</td>
                    <td class="p-3">{{ number_format($transferSummary['incoming_count'] ?? 0) }}</td>
                    <td class="p-3 font-bold {{ ($transferSummary['difference'] ?? 0) >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">{{ number_format($transferSummary['difference_abs'] ?? abs($transferSummary['difference'] ?? 0), 2) }} ر.س</td>
                    <td class="p-3 font-bold {{ ($transferSummary['difference_type'] ?? 'balanced') === 'profit' ? 'text-emerald-300' : (($transferSummary['difference_type'] ?? 'balanced') === 'loss' ? 'text-rose-300' : 'text-gray-300') }}">{{ $transferSummary['difference_label'] ?? 'متعادل' }}</td>
                </tr>
            </tbody>
        </table>
        <p class="px-4 pb-3 text-xs text-gray-400">{{ $transferSummary['formula_note'] ?? 'الفارق = إجمالي الوارد بسعر التكلفة - إجمالي الصادر بسعر التكلفة' }}. إذا كان الصادر أعلى من الوارد يظهر كخسارة، وإذا كان الوارد أعلى من الصادر يظهر كربح.</p>
    </div>

    <div class="bg-gray-900/40 border border-gray-700 rounded-2xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-800/80 text-gray-300">
                <tr>
                    <th class="p-3 text-right">#</th>
                    <th class="p-3 text-right">اليوم</th>
                    <th class="p-3 text-right">عدد العمليات</th>
                    <th class="p-3 text-right">كاش</th>
                    <th class="p-3 text-right">شبكة</th>
                    <th class="p-3 text-right">الإجمالي العام</th>
                </tr>
            </thead>
            <tbody>
                @forelse($dailyRows as $index => $row)
                    <tr class="border-t border-gray-700/70 text-gray-200">
                        <td class="p-3">{{ $index + 1 }}</td>
                        <td class="p-3">{{ $row->day }}</td>
                        <td class="p-3">{{ number_format($row->ops_count) }}</td>
                        <td class="p-3 text-emerald-300">{{ number_format($row->cash_total ?? 0, 2) }} ر.س</td>
                        <td class="p-3 text-cyan-300">{{ number_format($row->card_total ?? 0, 2) }} ر.س</td>
                        <td class="p-3 font-bold text-green-400">{{ number_format($row->sales_total, 2) }} ر.س</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-6 text-center text-gray-400">لا توجد بيانات في هذا الشهر.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
