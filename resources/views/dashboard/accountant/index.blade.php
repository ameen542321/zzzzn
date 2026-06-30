@extends('dashboard.app')

@section('title', 'لوحة المحاسب')

@section('content')

{{-- العنوان --}}
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">لوحة المحاسب</h1>
        <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">نظرة عامة على العمليات المالية لليوم</p>
        <div class="flex items-center gap-2 mt-2">
            <span class="px-2 py-1 bg-indigo-500/20 text-indigo-300 text-xs rounded">المتجر: {{ auth('accountant')->user()->store->name ?? 'غير محدد' }}</span>
        </div>
    </div>
    <div class="text-right">
        <span class="text-xs text-gray-500 block">التاريخ والوقت</span>
        <span class="text-gray-900 dark:text-white font-mono">{{ now()->format('Y-m-d H:i') }}</span>
        <div class="mt-2">
            <span class="text-xs text-gray-400">آخر تحديث: {{ now()->format('h:i A') }}</span>
        </div>
    </div>
</div>
@if(($pendingIncomingTransfersCount ?? 0) > 0 || ($pendingOutgoingTransfersCount ?? 0) > 0)
    <a href="{{ route('accountant.transfers.index') }}" class="mb-6 block rounded-xl border border-blue-700 bg-blue-900/30 p-4 text-blue-100 hover:bg-blue-900/50 transition">
        📦 النقل المخزني: لديك {{ $pendingIncomingTransfersCount ?? 0 }} بضاعة واردة بحاجة لمعالجة و {{ $pendingOutgoingTransfersCount ?? 0 }} بضاعة صادرة قيد الانتظار.
        <span class="block text-xs text-blue-100/80 mt-1">اضغط هنا لفتح طلبات النقل والموافقة أو الرفض.</span>
    </a>
@endif


@if(($pendingShiftGapRequests ?? collect())->isNotEmpty())
<a href="#owner-shift-requests" class="mb-4 block rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-amber-100 hover:bg-amber-500/20 transition">
    <span class="font-black">تنبيه:</span> لديك طلبات من {{ auth('accountant')->user()->store?->user?->name ?? 'صاحب المتجر' }} — اضغط لفتح الطلبات
</a>
@endif

{{-- البطاقات الإحصائية --}}
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

    {{-- مبيعات الوردية مع التولتيب --}}
   <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow hover:border-blue-500 transition-all">
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2">
                <p class="text-gray-400 text-sm">💰 مبيعات</p>
                {{-- ✅ تولتيب المبيعات (يظهر لليسار) --}}
                <div class="relative group">
                    <span class="text-gray-500 text-xs cursor-help border border-gray-700 rounded-full w-4 h-4 flex items-center justify-center">?</span>
                    <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[10px] p-2 rounded-lg w-48 shadow-xl z-50">
                        <span class="font-bold text-blue-400">إجمالي   عمليات البيع المسجله</span><br>

                    </div>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-white mt-1">{{ number_format($totalSinceBalance, 2) }} <span class="text-xs text-gray-400">ريال</span></h3>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mt-2 mb-1">
                {{-- نقداً مع التولتيب --}}
                <div class="flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
                    <span class="text-[11px] text-gray-400">نقداً:</span>
                    <span class="text-[11px] text-white font-bold">{{ number_format($cashSales ?? 0, 2) }}</span>
                    {{-- ✅ تولتيب النقد (يظهر لليسار) --}}
                    <div class="relative group">
                        <span class="text-gray-500 text-[8px] cursor-help border border-gray-700 rounded-full w-3 h-3 flex items-center justify-center">?</span>
                        <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[8px] p-1 rounded-lg w-36 shadow-xl z-50">
                            المبلغ المقبوض نقداً (paid_amount)
                        </div>
                    </div>
                </div>

                {{-- شبكة مع التولتيب --}}
                <div class="flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 bg-blue-400 rounded-full"></span>
                    <span class="text-[11px] text-gray-400">شبكة:</span>
                    <span class="text-[11px] text-white font-bold">{{ number_format($cardSales ?? 0, 2) }}</span>
                    {{-- ✅ تولتيب الشبكة (يظهر لليسار) --}}
                    <div class="relative group">
                        <span class="text-gray-500 text-[8px] cursor-help border border-gray-700 rounded-full w-3 h-3 flex items-center justify-center">?</span>
                        <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[8px] p-1 rounded-lg w-36 shadow-xl z-50">
                            المبلغ المقبوض عبر الشبكة (card)
                        </div>
                    </div>
                </div>

                {{-- آجل مع التولتيب --}}
                <div class="flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 bg-orange-500 rounded-full"></span>
                    <span class="text-[11px] text-gray-400">آجل:</span>
                    <span class="text-[11px] text-white font-bold">{{ number_format($pendingCreditTotal ?? 0, 2) }}</span>
                    {{-- ✅ تولتيب الآجل (يظهر لليسار) --}}
                    <div class="relative group">
                        <span class="text-gray-500 text-[8px] cursor-help border border-gray-700 rounded-full w-3 h-3 flex items-center justify-center">?</span>
                        <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[8px] p-1 rounded-lg w-36 shadow-xl z-50">
                            المبالغ المتبقية (ديون)
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2 mt-2">
                <span class="text-xs text-gray-500">منذ {{ $startTime->format('h:i A') }}</span>
                @if($salesEfficiency != 0)
                <span class="text-xs px-2 py-1 rounded {{ $salesEfficiency >= 0 ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                    {{ $salesEfficiency >= 0 ? '+' : '' }}{{ number_format($salesEfficiency, 1) }}%
                </span>
                @endif
            </div>
        </div>
        <div class="bg-blue-500/15 text-blue-300 p-3 rounded-lg">
            <i class="fa-solid fa-cart-shopping text-xl"></i>
        </div>
    </div>
</div>

    {{-- مصاريف الوردية مع التولتيب --}}
    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow hover:border-red-500 transition-all">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <p class="text-gray-400 text-sm">💸 مصاريف اليوم</p>
                    {{-- ✅ تولتيب المصاريف (يظهر لليسار) --}}
                    <div class="relative group">
                        <span class="text-gray-500 text-xs cursor-help border border-gray-700 rounded-full w-4 h-4 flex items-center justify-center">?</span>
                        <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[10px] p-2 rounded-lg w-48 shadow-xl z-50">
                            إجمالي المصروفات المسجلة خلال الشفت
                        </div>
                    </div>
                </div>
                <h3 class="text-2xl font-bold text-red-400 mt-1">{{ number_format($currentShiftExpenses, 2) }} <span class="text-xs text-gray-400">ريال</span></h3>
                <div class="mt-2">
                    <span class="text-xs text-gray-500">
                        {{ $stats['monthly_expenses'] > 0 ? round(($currentShiftExpenses / $stats['monthly_expenses']) * 100, 1) : 0 }}% من إجمالي الشهر
                    </span>
                </div>
            </div>
            <div class="bg-red-500/15 text-red-300 p-3 rounded-lg">
                <i class="fa-solid fa-receipt text-xl"></i>
            </div>
        </div>
    </div>

    {{-- سحوبات الوردية مع التولتيب --}}
    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow hover:border-yellow-500 transition-all">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <p class="text-gray-400 text-sm">🏦 سحوبات اليوم</p>
                    {{-- ✅ تولتيب السحوبات (يظهر لليسار) --}}
                    <div class="relative group">
                        <span class="text-gray-500 text-xs cursor-help border border-gray-700 rounded-full w-4 h-4 flex items-center justify-center">?</span>
                        <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[10px] p-2 rounded-lg w-48 shadow-xl z-50">
                            السحوبات النقدية من الصندوق
                        </div>
                    </div>
                </div>
                <h3 class="text-2xl font-bold text-yellow-400 mt-1">{{ number_format($currentShiftWithdrawals, 2) }} <span class="text-xs text-gray-400">ريال</span></h3>
                <div class="mt-2">
                    <span class="text-xs text-gray-500">
                        {{ $stats['monthly_withdrawals'] > 0 ? round(($currentShiftWithdrawals / $stats['monthly_withdrawals']) * 100, 1) : 0 }}% من إجمالي الشهر
                    </span>
                </div>
            </div>
            <div class="bg-yellow-500/15 text-yellow-300 p-3 rounded-lg">
                <i class="fa-solid fa-hand-holding-usd text-xl"></i>
            </div>
        </div>
    </div>

    {{-- تحصيلات الآجل أو إحصائيات الشهر مع التولتيب --}}
    @if($cashFromCollections > 0)
    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow hover:border-green-500 transition-all">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <p class="text-gray-400 text-sm">💳 تحصيلات الآجل</p>
                    {{-- ✅ تولتيب التحصيلات (يظهر لليسار) --}}
                    <div class="relative group">
                        <span class="text-gray-500 text-xs cursor-help border border-gray-700 rounded-full w-4 h-4 flex items-center justify-center">?</span>
                        <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[10px] p-2 rounded-lg w-48 shadow-xl z-50">
                            تحصيلات نقدية من ديون سابقة
                        </div>
                    </div>
                </div>
                <h3 class="text-2xl font-bold text-green-400 mt-1">{{ number_format($cashFromCollections, 2) }} <span class="text-xs text-gray-400">ريال</span></h3>
                <div class="mt-2">
                    <span class="text-xs text-gray-500">{{ $creditCollections['count'] ?? 0 }} عملية تحصيل</span>
                </div>
            </div>
            <div class="bg-green-500/15 text-green-300 p-3 rounded-lg">
                <i class="fa-solid fa-money-check-dollar text-xl"></i>
            </div>
        </div>
    </div>
    @else
    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow hover:border-indigo-500 transition-all">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <p class="text-gray-400 text-sm">📅 إحصائيات الشهر</p>
                    {{-- ✅ تولتيب إحصائيات الشهر (يظهر لليسار) --}}
                    <div class="relative group">
                        <span class="text-gray-500 text-xs cursor-help border border-gray-700 rounded-full w-4 h-4 flex items-center justify-center">?</span>
                        <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[10px] p-2 rounded-lg w-48 shadow-xl z-50">
                            عدد أيام العمل والمتوسط اليومي للمبيعات
                        </div>
                    </div>
                </div>
                <h3 class="text-2xl font-bold text-indigo-400 mt-1">{{ $workingDays }} <span class="text-xs text-gray-400">يوم عمل</span></h3>
                <div class="mt-2">
                    <span class="text-xs text-gray-500">متوسط يومي: {{ number_format($dailyAverage, 2) }} ريال</span>
                </div>
            </div>
            <div class="bg-indigo-500/15 text-indigo-300 p-3 rounded-lg">
                <i class="fa-solid fa-chart-line text-xl"></i>
            </div>
        </div>
    </div>
    @endif
</div>

{{-- بطاقة تفاصيل العمليات + مودال الشفت --}}
<div x-data="{ openOperationsModal: false }" class="mt-6">
    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow hover:border-cyan-500 transition-all cursor-pointer"
         @click="openOperationsModal = true">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">📋 تفاصيل العمليات</p>
                <h3 class="text-2xl font-bold text-cyan-400 mt-1">{{ number_format($shiftOperationDetails['count'] ?? 0) }} <span class="text-xs text-gray-400">عملية</span></h3>
                <p class="text-xs text-gray-500 mt-2">المعروض فقط خلال وقت الشفت الحالي (من {{ $startTime->format('h:i A') }})</p>
            </div>
            <div class="bg-cyan-500/15 text-cyan-300 p-3 rounded-lg">
                <i class="fa-solid fa-list-check text-xl"></i>
            </div>
        </div>
    </div>

    <div x-show="openOperationsModal" x-transition.opacity class="fixed inset-0 z-50 bg-black/70 flex items-start justify-center overflow-y-auto p-4" style="display:none;">
        <div @click.away="openOperationsModal = false" class="my-4 w-full max-w-6xl max-h-[calc(100vh-2rem)] min-h-0 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden flex flex-col">
            <div class="min-h-0 flex-1 overflow-y-auto overflow-x-auto p-4">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-gray-300">
                        @if(($shiftOperationDetails['sales_total'] ?? 0) > 0)<span title="المبيعات" class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-1 text-emerald-300"><i class="fa-solid fa-cart-shopping"></i>{{ number_format($shiftOperationDetails['sales_total'], 2) }}</span>@endif
                        @if(($shiftOperationDetails['cash_total'] ?? 0) > 0)<span title="كاش" class="inline-flex items-center gap-1 rounded-full bg-green-500/10 px-2 py-1 text-green-300"><i class="fa-solid fa-money-bill-wave"></i>{{ number_format($shiftOperationDetails['cash_total'], 2) }}</span>@endif
                        @if(($shiftOperationDetails['card_total'] ?? 0) > 0)<span title="شبكة" class="inline-flex items-center gap-1 rounded-full bg-sky-500/10 px-2 py-1 text-sky-300"><i class="fa-solid fa-credit-card"></i>{{ number_format($shiftOperationDetails['card_total'], 2) }}</span>@endif
                        @if((($shiftOperationDetails['collections_total'] ?? 0) + ($shiftOperationDetails['credit_total'] ?? 0)) > 0)<span title="آجل/تحصيل" class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-1 text-amber-300"><i class="fa-solid fa-clock"></i>{{ number_format(($shiftOperationDetails['collections_total'] ?? 0) + ($shiftOperationDetails['credit_total'] ?? 0), 2) }}</span>@endif
                        @if(($shiftOperationDetails['expenses_total'] ?? 0) > 0)<span title="مصروف" class="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2 py-1 text-red-300"><i class="fa-solid fa-receipt"></i>{{ number_format($shiftOperationDetails['expenses_total'], 2) }}</span>@endif
                        @if(($shiftOperationDetails['withdrawals_total'] ?? 0) > 0)<span title="سحب" class="inline-flex items-center gap-1 rounded-full bg-orange-500/10 px-2 py-1 text-orange-300"><i class="fa-solid fa-arrow-up-from-bracket"></i>{{ number_format($shiftOperationDetails['withdrawals_total'], 2) }}</span>@endif
                    </div>
                    <button @click="openOperationsModal = false" class="shrink-0 rounded-full bg-gray-800 px-3 py-1 text-gray-300 hover:text-white">&times;</button>
                </div>
                <table class="w-full text-right text-sm">
                    <thead class="bg-gray-800 text-gray-400">
                        <tr>
                            <th class="p-3">الوقت</th>
                            <th class="p-3">نوع العملية</th>
                            <th class="p-3">المنتج / البيان</th>
                            <th class="p-3">نوع الدفع</th>
                            <th class="p-3 text-left">المبلغ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @forelse(($shiftOperationDetails['rows'] ?? collect()) as $row)
                            <tr class="hover:bg-white/5">
                                <td class="p-3 text-gray-300">{{ \Carbon\Carbon::parse($row['time'])->format('h:i A') }}</td>
                                <td class="p-3 text-white">{{ $row['operation_type'] }}</td>
                                <td class="p-3 text-gray-300">{{ $row['product'] ?: ($row['actor'] ?? '-') }}</td>
                                <td class="p-3 text-gray-300">{{ $row['payment_type'] }}</td>
                                <td class="p-3 text-left font-bold {{ in_array($row['operation_type'], ['مصروف', 'سحب', 'مديونية']) ? 'text-red-400' : 'text-green-400' }}">
                                    {{ number_format($row['amount'], 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-8 text-center text-gray-500">لا توجد عمليات مرتبطة بالشفت الحالي.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>


{{-- بطاقة إقفال الشفت (مع منطق الحساب اللحظي) --}}
<div x-data="{
    openConfirm: false,
    actualCash: {{ $cashInSafe }},
    expectedCash: {{ $cashInSafe }},
    notes: '',
    get difference() {
        let diff = this.actualCash - this.expectedCash;
        return diff;
    },
    get isShortage() {
        // عجز: الفرق سالب (الفعلي أقل من المتوقع)
        return this.difference < 0;
    },
    get isSurplus() {
        // زيادة: الفرق موجب (الفعلي أكثر من المتوقع)
        return this.difference > 0;
    },
    get isBalanced() {
        // مطابق: الفرق صفر
        return this.difference == 0;
    },
    get shortageAmount() {
        return this.isShortage ? Math.abs(this.difference) : 0;
    },
    get surplusAmount() {
        return this.isSurplus ? this.difference : 0;
    },
    get differenceDisplay() {
        let diff = this.difference;
        if (diff > 0) return '+' + diff.toFixed(2);
        if (diff < 0) return diff.toFixed(2);
        return '0.00';
    }
}" class="relative mt-6">
    <div @click="openConfirm = true" class="bg-gradient-to-r from-indigo-900/40 to-purple-900/40 border border-indigo-500/50 rounded-xl p-5 shadow cursor-pointer hover:from-indigo-800/50 hover:to-purple-800/50 transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <p class="text-indigo-200 text-sm">⏰ إصدار الموازنة اليومية</p>
                    {{-- ✅ تولتيب الموازنة (يظهر لليسار) --}}
                    <div class="relative group">
                        <span class="text-gray-500 text-xs cursor-help border border-gray-700 rounded-full w-4 h-4 flex items-center justify-center">?</span>
                        <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[10px] p-2 rounded-lg w-48 shadow-xl z-50">
                            إقفال الشفت ومطابقة الصندوق
                        </div>
                    </div>
                </div>
                <h3 class="text-2xl font-bold text-white mt-1">{{ number_format($totalSinceBalance, 2) }} <span class="text-xs text-gray-300">المبيعات</span></h3>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-indigo-100/90">
                    @if(($cashSales ?? 0) > 0)<span title="كاش" class="inline-flex items-center gap-1 rounded-full bg-green-500/10 px-2 py-1 text-green-300"><i class="fa-solid fa-money-bill-wave"></i>{{ number_format($cashSales, 2) }}</span>@endif
                    @if(($cardSales ?? 0) > 0)<span title="شبكة" class="inline-flex items-center gap-1 rounded-full bg-sky-500/10 px-2 py-1 text-sky-300"><i class="fa-solid fa-credit-card"></i>{{ number_format($cardSales, 2) }}</span>@endif
                    @if(($mixedSales ?? 0) > 0)<span title="مكس" class="inline-flex items-center gap-1 rounded-full bg-purple-500/10 px-2 py-1 text-purple-300"><i class="fa-solid fa-shuffle"></i>{{ number_format($mixedSales, 2) }}</span>@endif
                    @if(($cashFromCollections ?? 0) > 0)<span title="آجل/تحصيل" class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-1 text-amber-300"><i class="fa-solid fa-clock"></i>{{ number_format($cashFromCollections, 2) }}</span>@endif
                    @if(($currentShiftExpenses ?? 0) > 0)<span title="مصروف" class="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2 py-1 text-red-300"><i class="fa-solid fa-receipt"></i>{{ number_format($currentShiftExpenses, 2) }}</span>@endif
                    @if(($currentShiftWithdrawals ?? 0) > 0)<span title="سحب" class="inline-flex items-center gap-1 rounded-full bg-orange-500/10 px-2 py-1 text-orange-300"><i class="fa-solid fa-arrow-up-from-bracket"></i>{{ number_format($currentShiftWithdrawals, 2) }}</span>@endif
                </div>
                <div class="mt-2 text-[11px] leading-5 text-indigo-100/80">
                    التكلفة: {{ number_format($totalCost ?? 0, 2) }}<br>
                    المتوقع في الصندوق: {{ number_format($cashInSafe, 2) }} ريال
                </div>
            </div>
            <div class="relative">
                <div class="bg-white/10 text-white p-3 rounded-lg animate-pulse">
                    <i class="fa-solid fa-scale-balanced text-xl"></i>
                </div>
                @if($shiftDuration > 8)
                <div class="absolute -top-2 -right-2 w-4 h-4 bg-yellow-500 rounded-full animate-ping"></div>
                @endif
            </div>
        </div>
        <div class="mt-4 pt-4 border-t border-gray-700/50">
            <div class="flex justify-between text-xs">
                <div>
                    <span class="text-gray-400">المتوقع في الصندوق:</span>
                    <span class="text-white font-bold ml-2">{{ number_format($cashInSafe, 2) }} ريال</span>
                </div>
                <div class="text-right">
                    <span class="text-gray-400">آخر اصدار:</span>
                    <span class="text-gray-300 ml-2">{{ $lastBalanceTime }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- نافذة التأكيد المحدثة مع سكرول --}}
    <div x-show="openConfirm"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 scale-90"
         x-transition:enter-end="opacity-100 scale-100"
         class="fixed inset-0 bg-black/80 flex items-start justify-center z-50 p-2 overflow-y-auto"
         x-cloak
         style="padding-top: 5vh; padding-bottom: 5vh;">

        <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 max-w-md w-full shadow-2xl my-auto"
             @click.away="openConfirm = false"
             style="max-height: 90vh; overflow-y: auto;">

            <div class="text-center mb-4 bg-gray-900 pt-0 pb-2">
                <h2 class="text-xl font-bold text-white">
                    {{ !empty($activeShiftGapBusinessDate) ? 'تأكيد إصدار موازنة يوم مرجع' : 'تأكيد إصدار الموازنة اليومية' }}
                </h2>
                <p class="text-gray-400 text-sm mt-1 uppercase tracking-wider">
                    @if(!empty($activeShiftGapBusinessDate))
                        هذه الموازنة خاصة بالتاريخ المرجع {{ $activeShiftGapBusinessDate }} وليست إقفالًا للشفت الحالي.
                    @else
                        ملخص الحساب النقدي
                    @endif
                </p>
            </div>

            {{-- المحتوى القابل للسكرول --}}
            <div class="space-y-4">
                {{-- تفاصيل الحساب النقدي للوردية --}}
                <div class="bg-gray-800/50 border border-gray-700 rounded-xl p-4 space-y-3">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 bg-blue-500 rounded-full"></span>
                            <span class="text-white font-bold text-base">إجمالي المبيعات:</span>
                            <div class="relative group">
                                <span class="text-gray-500 text-[8px] cursor-help border border-gray-700 rounded-full w-3 h-3 flex items-center justify-center">?</span>
                                <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[8px] p-1 rounded-lg w-32 shadow-xl z-50">
                                    مجموع المبلغ المحصل فعلياً (paid_amount)
                                </div>
                            </div>
                        </div>
                        <span class="text-white font-black text-lg">{{ number_format($totalSinceBalance, 2) }} ريال</span>
                    </div>
                    <div class="text-[10px] text-gray-500 pr-5">
                        قيمة الفواتير: {{ number_format($totalInvoicedSinceBalance ?? 0, 2) }} ريال
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mt-2 mb-1">
                        {{-- نقداً --}}
                        <div class="flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
                            <span class="text-[11px] text-gray-400">نقداً:</span>
                            <span class="text-[11px] text-white font-bold">{{ number_format($cashSales ?? 0, 2) }}</span>
                            <div class="relative group">
                                <span class="text-gray-500 text-[6px] cursor-help border border-gray-700 rounded-full w-2 h-2 flex items-center justify-center">?</span>
                                <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[6px] p-1 rounded-lg w-28 shadow-xl z-50">
                                    المقبوض نقداً
                                </div>
                            </div>
                        </div>

                        {{-- شبكة --}}
                        <div class="flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 bg-blue-400 rounded-full"></span>
                            <span class="text-[11px] text-gray-400">شبكة:</span>
                            <span class="text-[11px] text-white font-bold">{{ number_format($cardSales ?? 0, 2) }}</span>
                            <div class="relative group">
                                <span class="text-gray-500 text-[6px] cursor-help border border-gray-700 rounded-full w-2 h-2 flex items-center justify-center">?</span>
                                <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[6px] p-1 rounded-lg w-28 shadow-xl z-50">
                                    المقبوض عبر الشبكة
                                </div>
                            </div>
                        </div>

                        {{-- آجل --}}
                        <div class="flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 bg-orange-500 rounded-full"></span>
                            <span class="text-[11px] text-gray-400">آجل:</span>
                            <span class="text-[11px] text-orange-200 font-bold">{{ number_format($officialCreditSales ?? 0, 2) }}</span>
                            <div class="relative group">
                                <span class="text-gray-500 text-[6px] cursor-help border border-gray-700 rounded-full w-2 h-2 flex items-center justify-center">?</span>
                                <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[6px] p-1 rounded-lg w-28 shadow-xl z-50">
                                    مبالغ لم تقبض بعد
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-gray-700/50 space-y-2">
                        @if(isset($officialCreditSales) && $officialCreditSales > 0)
                        <div class="flex justify-between items-center pr-5 text-blue-400">
                            <span class="text-[11px] font-medium italic">بيع آجل (موثق):</span>
                            <span class="text-xs font-bold">- {{ number_format($officialCreditSales, 2) }} ريال</span>
                        </div>
                        @endif

                        @if(isset($paymentGaps) && $paymentGaps > 0)
                        <div class="flex justify-between items-center pr-5 text-orange-400">
                            <span class="text-[11px] font-medium italic">فوارق تحصيل :</span>
                            <span class="text-xs font-bold">- {{ number_format($paymentGaps, 2) }} ريال</span>
                        </div>
                        @endif
                    </div>

                    @if($cashFromCollections > 0)
                    <div class="flex justify-between items-center border-t border-gray-700 pt-2 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 bg-green-500 rounded-full"></span>
                            <span class="text-gray-400">تحصيلات خارجية:</span>
                            <div class="relative group">
                                <span class="text-gray-500 text-[6px] cursor-help border border-gray-700 rounded-full w-2 h-2 flex items-center justify-center">?</span>
                                <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[6px] p-1 rounded-lg w-28 shadow-xl z-50">
                                    تحصيل ديون سابقة
                                </div>
                            </div>
                        </div>
                        <span class="text-green-400 font-medium">+ {{ number_format($cashFromCollections, 2) }} ريال</span>
                    </div>
                    @endif

                    <div class="flex justify-between items-center text-sm">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 bg-red-500 rounded-full"></span>
                            <span class="text-gray-400">المصاريف والسحوبات:</span>
                            <div class="relative group">
                                <span class="text-gray-500 text-[6px] cursor-help border border-gray-700 rounded-full w-2 h-2 flex items-center justify-center">?</span>
                                <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block bg-gray-800 border border-gray-700 text-white text-[6px] p-1 rounded-lg w-28 shadow-xl z-50">
                                    ما خرج من الصندوق
                                </div>
                            </div>
                        </div>
                        <span class="text-red-400 font-medium">- {{ number_format($currentShiftExpenses + $currentShiftWithdrawals, 2) }} ريال</span>
                    </div>

                    <div class="pt-2 border-t border-gray-700"></div>

                    <div class="flex justify-between items-center pt-1">
                        <span class="text-indigo-400 font-bold">صافي الكاش المتوقع بالدرج:</span>
                        <span class="text-white font-black text-xl">
                            {{ number_format($cashInSafe, 2) }} ريال
                        </span>
                    </div>
                </div>

                {{-- تنبيه العجز أو الزيادة المحسن --}}
                <template x-if="!isBalanced">
                    <div :class="isShortage ? 'bg-red-500/20 border-red-500/50' : 'bg-green-500/20 border-green-500/50'"
                         class="p-4 rounded-lg border">

                        {{-- رأس التنبيه --}}
                        <div class="flex justify-between items-center mb-2">
                            <div class="flex items-center gap-2">
                                <span :class="isShortage ? 'text-red-400' : 'text-green-400'" class="text-lg">
                                    <i :class="isShortage ? 'fa-solid fa-triangle-exclamation' : 'fa-solid fa-circle-exclamation'"></i>
                                </span>
                                <span class="text-sm font-bold text-white" x-text="isShortage ? '⚠️ يوجد عجز في الصندوق' : '💰 توجد زيادة في الصندوق'"></span>
                            </div>
                            <span class="text-2xl font-black" :class="isShortage ? 'text-red-400' : 'text-green-400'" x-text="Math.abs(difference).toFixed(2) + ' ريال'"></span>
                        </div>

                        {{-- تفاصيل الفرق --}}
                        <div class="grid grid-cols-2 gap-2 mt-3 text-xs">
                            <div class="bg-gray-800/50 p-2 rounded-lg">
                                <span class="text-gray-400">المتوقع:</span>
                                <span class="text-white font-bold mr-2" x-text="Number(expectedCash).toFixed(2) + ' ريال'"></span>
                            </div>
                            <div class="bg-gray-800/50 p-2 rounded-lg">
                                <span class="text-gray-400">الفعلي:</span>
                                <span class="text-white font-bold mr-2" x-text="Number(actualCash).toFixed(2) + ' ريال'"></span>
                            </div>
                        </div>

                        {{-- رسالة توضيحية --}}
                        <p class="text-xs text-gray-300 mt-3 bg-gray-900/50 p-2 rounded-lg">
                            <i class="fa-solid fa-circle-info ml-1 text-blue-400"></i>
                            <span x-text="isShortage ?
                                'هذا العجز سيتم تسجيله في النظام' :
                                'هذه الزيادة سيتم تسجيلها في النظام'">
                            </span>
                        </p>
                    </div>
                </template>

                {{-- تنبيه المطابقة (عند عدم وجود فرق) --}}
                <template x-if="isBalanced">
                    <div class="bg-green-500/10 border border-green-500/30 p-4 rounded-lg">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                <span class="text-green-400 text-lg">✅</span>
                                <span class="text-sm font-bold text-white">الصندوق متطابق تماماً</span>
                            </div>
                            <span class="text-green-400 font-bold">0.00 ريال</span>
                        </div>
                    </div>
                </template>

                {{-- نموذج الإقفال --}}
                <form action="{{ route('accountant.balance.store') }}" method="POST" class="space-y-4" onsubmit="return confirmNextShiftDecision(event, this)">
                    @csrf

                    <div>
                        <label class="text-gray-400 text-xs mb-2 block text-center">أدخل المبلغ النقدي الفعلي الموجود معك الآن:</label>
                        <input type="number" step="0.01" name="actual_cash" required autofocus
                            x-model="actualCash"
                            class="w-full bg-gray-800 border-2 border-indigo-500/30 rounded-xl px-4 py-4 text-white text-2xl text-center focus:border-indigo-500 outline-none transition shadow-inner">
                        <p class="text-[10px] text-gray-500 text-center mt-2">
                            <i class="fa-solid fa-circle-info ml-1"></i>
                            هذا المبلغ يمثل النقد (الدرج) فقط، مبيعات الشبكة تُحسب تلقائياً.
                        </p>
                    </div>

                    <div>
                        <label class="text-gray-400 text-xs mb-1 block">ملاحظات (اختياري):</label>
                        <textarea name="notes" rows="2" x-model="notes"
                            class="w-full bg-gray-800 border border-gray-600 rounded-xl px-3 py-2 text-white text-sm outline-none focus:border-indigo-500"
                            placeholder="اكتب أي ملاحظة عن العجز أو الزيادة هنا..."></textarea>
                    </div>

                    @if($canChooseNextShiftBusinessDate)
                    {{-- توضيح: هذا القسم يظهر فقط عند إغلاق الشفت الأول في متجر متعدد الشفتات ليختار المحاسب فتح الشفت الثاني أو إنهاء اليوم المحاسبي. --}}
                    <div class="bg-indigo-950/40 border border-indigo-500/30 rounded-xl p-4 space-y-3">
                        <p class="text-white text-sm font-bold">اختر ما سيحدث بعد إغلاق الشفت الأول</p>
                        <label class="flex items-start gap-2 text-xs text-indigo-100 cursor-pointer">
                            <input type="radio" name="next_shift_decision" value="same_business_date" checked class="mt-1">
                            <span>تفعيل الشفت الثاني لنفس تاريخ {{ $currentBusinessDate }}</span>
                        </label>
                        <label class="flex items-start gap-2 text-xs text-amber-100 cursor-pointer">
                            <input type="radio" name="next_shift_decision" value="next_business_date" class="mt-1">
                            <span>إغلاق اليوم المحاسبي والانتقال إلى تاريخ {{ $nextBusinessDateAfterCurrent }}. سيحتاج الرجوع للشفت الثاني لاحقًا إلى المدير.</span>
                        </label>
                    </div>
                    @endif

                    <div class="flex gap-3 pt-2 sticky bottom-0 bg-gray-900 pb-0 pt-2">
                        <button type="button" @click="openConfirm = false"
                            class="flex-1 py-3 bg-gray-700 hover:bg-gray-600 text-white rounded-xl transition font-semibold">
                            إلغاء
                        </button>
                        <button type="submit"
                            class="flex-1 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white rounded-xl font-bold shadow-lg shadow-indigo-600/20 transition">
                            {{ !empty($activeShiftGapBusinessDate) ? 'إصدار موازنة اليوم المرجع' : 'تأكيد الإقفال' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>


@if(($pendingShiftGapRequests ?? collect())->isNotEmpty())
<div id="owner-shift-requests" class="mt-6 bg-amber-500/10 border border-amber-500/30 rounded-xl p-5 shadow-lg">
    <h2 class="text-white font-bold flex items-center gap-2 mb-4">
        <i class="fa-solid fa-inbox text-amber-300"></i>
        طلبات مراجعة من {{ auth('accountant')->user()->store?->user?->name ?? 'صاحب المتجر' }}
    </h2>
    <div class="space-y-3">
        @foreach($pendingShiftGapRequests as $gapRequest)
            @php
                $gapDate = \Carbon\Carbon::parse(data_get($gapRequest->details, 'business_date'))->toDateString();
                $isActiveGap = $activeShiftGapBusinessDate === $gapDate;
            @endphp
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 rounded-xl border border-amber-700/40 bg-black/20 p-4">
                <div>
                    <p class="text-white font-bold">الشفت الخاص بتاريخ <span class="font-mono">{{ $gapDate }}</span> غير مكتمل</p>
                    @if(data_get($gapRequest->details, 'shift_label'))
                        <p class="text-amber-100 text-xs mt-1 font-bold">{{ data_get($gapRequest->details, 'shift_label') }}</p>
                    @endif
                </div>
                @if($isActiveGap)
                    <span class="relative group rounded-lg bg-blue-600/20 border border-blue-400/30 text-blue-100 px-4 py-2 text-xs font-bold">
                        تم تفعيل الطلب <span class="inline-flex items-center justify-center w-4 h-4 rounded-full border border-blue-200 cursor-help">?</span>
                        <span class="absolute top-full left-0 mt-2 hidden group-hover:block w-72 rounded-lg border border-gray-700 bg-gray-900 p-3 text-xs leading-6 text-gray-100 shadow-xl z-50">عند تفعيل الطلب، ستأخذ العمليات الجديدة تاريخ اليوم المرسل بدل تاريخ اليوم الحالي.</span>
                    </span>
                @else
                    <form method="POST" action="{{ route('accountant.shift-gaps.activate', $gapRequest->id) }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-amber-600 hover:bg-amber-500 text-white px-4 py-2 text-xs font-bold">
                            فتح الطلب
                        </button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- التنبيهات والإشعارات --}}
@if($lowStockProductsCount > 0 || $pendingCreditCount > 0 || $lastBalanceTime != 'بداية اليوم' || $requiresSecondShiftConfirmation)
<div class="mt-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 shadow-lg">
    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6 flex items-center gap-2">
        <span class="text-yellow-400">⚠️</span>
        التنبيهات والإشعارات
    </h2>

    <div class="grid grid-cols-1 gap-4">
        @if($requiresSecondShiftConfirmation)
        <div class="bg-indigo-500/10 border-r-4 border-indigo-500 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <div class="bg-indigo-500/20 text-indigo-300 p-2 rounded-lg">
                    <i class="fa-solid fa-clock-rotate-left text-xl"></i>
                </div>
                <div>
                    <p class="text-white font-bold text-sm">الشفت الحالي محسوب كشفت رقم {{ $currentShiftNumber }} لتاريخ {{ $currentBusinessDate }}</p>
                    <p class="text-indigo-200 text-xs mt-1">هذا المتجر يسمح بأكثر من شفت في نفس التاريخ. في الخطوة القادمة سيظهر تأكيد صريح قبل اعتماد الشفت الثاني أو الانتقال لليوم التالي.</p>
                </div>
            </div>
        </div>
        @endif

        @if($paymentGaps > 0)
        <div class="bg-red-500/10 border-r-4 border-red-500 rounded-lg p-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="bg-red-500/20 text-red-400 p-2 rounded-lg">
                        <i class="fa-solid fa-file-circle-exclamation text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-white text-sm">فوارق فواتير معلقة</h3>
                        <p class="text-[11px] text-gray-400">هذه المبالغ لم تُدفع ولم تُسجل كآجل على موظف</p>
                    </div>
                </div>

                <div class="text-left">
                    <span class="block text-xl font-black text-red-400">{{ number_format($paymentGaps, 2) }} <small class="text-xs">ريال</small></span>
                    <span class="text-[10px] text-gray-500">مسجلة عـلى: </span>
                    <span class="text-[10px] text-blue-400 font-bold">{{ $accountant->name }}</span>
                </div>
            </div>
        </div>
        @endif

        {{-- تنبيه المبالغ المعلقة --}}
        @if($pendingCreditCount > 0)
        <div class="bg-orange-500/10 border border-orange-500/30 rounded-lg p-4 transition hover:bg-orange-500/15">
            <div class="flex items-start justify-between">
                <div class="flex items-start gap-3">
                    <div class="bg-orange-500/20 text-orange-400 p-2 rounded-lg">
                        <i class="fa-solid fa-hand-holding-dollar text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-white mb-1">مبالغ بانتظار السداد</h3>

                        {{-- تفصيل المبالغ --}}
                        <div class="flex flex-wrap gap-x-4 gap-y-1 mb-2">
                            <div class="flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 bg-blue-400 rounded-full"></span>
                                <span class="text-[11px] text-gray-400">بيع آجل:</span>
                                <span class="text-[11px] text-white font-bold">{{ number_format($officialCreditSales, 2) }}</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 bg-red-400 rounded-full"></span>
                                <span class="text-[11px] text-gray-400">فوارق فواتير:</span>
                                <span class="text-[11px] text-white font-bold">{{ number_format($paymentGaps, 2) }}</span>
                            </div>
                        </div>

                        <p class="text-gray-300 text-sm">
                            إجمالي <span class="font-bold text-orange-400">{{ $pendingCreditCount }}</span> فواتير بقيمة
                            <span class="font-bold text-orange-400">{{ number_format($pendingCreditTotal, 2) }}</span> ريال
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <a href="{{ route('accountant.pos.credit-sale.page') }}" class="bg-orange-500/20 text-orange-400 px-3 py-1.5 rounded-lg text-xs flex items-center justify-center gap-2 hover:bg-orange-500/30 transition">
                        <span>مراجعة</span>
                        <i class="fa-solid fa-chevron-left text-[10px]"></i>
                    </a>
                </div>
            </div>
        </div>
        @endif

        {{-- تنبيه المخزون المنخفض --}}
        @if($lowStockProductsCount > 0)
        <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4">
            <div class="flex items-start justify-between">
                <div class="flex items-start gap-3">
                    <div class="bg-red-500/20 text-red-400 p-2 rounded-lg">
                        <i class="fa-solid fa-box-open"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-white mb-1">تنبيه المخزون</h3>
                        <p class="text-gray-300 text-sm">هناك {{ $lowStockProductsCount }} منتجات وصلت للحد الأدنى</p>
                    </div>
                </div>
                <a href="{{ route('accountant.pos.searchProduct') }}" class="text-red-400 hover:underline text-xs">عرض المنتجات</a>
            </div>
        </div>
        @endif

        {{-- معلومات آخر إقفال --}}
        @if($lastBalanceTime != 'بداية اليوم')
        <div class="bg-blue-500/10 border border-blue-500/30 rounded-lg p-4">
            <div class="flex items-center gap-3">
                <div class="bg-blue-500/20 text-blue-400 p-2 rounded-lg text-xs">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div class="flex flex-1 justify-between items-center">
                    <span class="text-gray-300 text-sm">آخر إقفال يدوي تم بواسطة <strong>{{ $lastBalanceAccountant }}</strong></span>
                    <span class="text-blue-400 font-mono text-xs">{{ $lastBalanceTime }}</span>
                </div>
            </div>
        </div>
        @endif

    </div>
</div>
@endif

{{-- جدول آخر العمليات --}}
<div class="mt-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 shadow-lg">
    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6 flex items-center gap-2">
        <span class="text-yellow-400">🕘</span>
        آخر العمليات
    </h2>

    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="text-right border-b border-gray-700">
                    <th class="pb-3 text-gray-400 font-medium text-sm">الوقت</th>
                    <th class="pb-3 text-gray-400 font-medium text-sm">النوع</th>
                    <th class="pb-3 text-gray-400 font-medium text-sm">الموظف</th>
                    <th class="pb-3 text-gray-400 font-medium text-sm">الوصف</th>
                    <th class="pb-3 text-gray-400 font-medium text-sm text-left">المبلغ</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lastOperations as $op)
                <tr class="border-b border-gray-700/50 hover:bg-white/5 transition">
                    <td class="py-4">
                        <div class="text-gray-300 text-sm">{{ $op->created_at->format('h:i A') }}</div>
                        <div class="text-gray-500 text-xs">{{ $op->created_at->format('Y-m-d') }}</div>
                    </td>
                    <td class="py-4">
                        @if($op->type == 'sale')
                            <span class="px-3 py-1 bg-green-500/20 text-green-400 text-xs rounded-full">بيع</span>
                        @elseif($op->type == 'expense')
                            <span class="px-3 py-1 bg-red-500/20 text-red-400 text-xs rounded-full">مصروف</span>
                        @elseif($op->type == 'withdrawal')
                            <span class="px-3 py-1 bg-yellow-500/20 text-yellow-400 text-xs rounded-full">سحب</span>
                        @elseif($op->type == 'collection')
                            <span class="px-3 py-1 bg-blue-500/20 text-blue-400 text-xs rounded-full">تحصيل</span>
                        @endif
                    </td>
                    <td class="py-4">
                        <div class="text-gray-300 text-sm">{{ $op->employee }}</div>
                    </td>
                    <td class="py-4">
                        <div class="text-gray-400 text-sm truncate max-w-[150px]" title="{{ $op->description }}">
                            {{ $op->description }}
                        </div>
                    </td>
                    <td class="py-4 text-left">
                        <div class="font-bold {{ in_array($op->type, ['expense', 'withdrawal']) ? 'text-red-400' : 'text-green-400' }}">
                            {{ number_format($op->amount, 2) }} <span class="text-xs text-gray-400">ريال</span>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="py-8 text-center">
                        <div class="text-gray-500 text-sm">لا توجد عمليات مسجلة اليوم حتى الآن</div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if(method_exists($lastOperations, 'links'))
        <div class="mt-4">
            {{ $lastOperations->links() }}
        </div>
    @endif
</div>

@if(session('wa_url'))
<script>
    window.onload = function() {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: '✅ تم الإقفال بنجاح',
                html: `
                    <div class="text-center">
                        <i class="fa-solid fa-check-circle fa-4x text-green-400 mb-4"></i>
                        <p class="text-lg font-bold text-white mb-2">تم إغلاق الشفت بنجاح</p>
                        <div class="bg-gray-800 rounded-lg p-4 mb-4">
                            <p class="text-gray-300 mb-1">يمكنك الآن إرسال التقرير للمالك عبر الواتساب</p>
                        </div>
                        <p class="text-gray-400 text-sm">سيتم فتح تطبيق واتساب في نافذة جديدة</p>
                    </div>
                `,
                icon: 'success',
                showCancelButton: true,
                confirmButtonText: '<i class="fab fa-whatsapp ml-1"></i> إرسال عبر واتساب',
                cancelButtonText: 'لاحقاً',
                confirmButtonColor: '#25D366',
                cancelButtonColor: '#6c757d',
                allowOutsideClick: false
            }).then((result) => {
                if (result.isConfirmed) {
                    window.open("{{ session('wa_url') }}", '_blank');
                }
            });
        } else {
            if(confirm('تم الإقفال بنجاح، هل تريد فتح واتساب لإرسال التقرير؟')) {
                window.open("{!! session('wa_url') !!}", '_blank');
            }
        }
    };
</script>
@endif

<style>
table { min-width: 100%; }
.text-truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hover\:scale-110:hover { transform: scale(1.1); }
.transition-all { transition: all 0.3s ease; }
.animate-ping { animation: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite; }
@keyframes ping { 75%, 100% { transform: scale(2); opacity: 0; } }

/* تحسينات للسكرول */
.overflow-y-auto {
    scrollbar-width: thin;
    scrollbar-color: #4a5568 #1a202c;
}
.overflow-y-auto::-webkit-scrollbar {
    width: 6px;
}
.overflow-y-auto::-webkit-scrollbar-track {
    background: #1a202c;
    border-radius: 10px;
}
.overflow-y-auto::-webkit-scrollbar-thumb {
    background: #4a5568;
    border-radius: 10px;
}
.overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background: #718096;
}
</style>

<script>
function confirmNextShiftDecision(event, form) {
    if (form.dataset.confirmed === '1') {
        return true;
    }

    @if(!empty($activeShiftGapBusinessDate))
        event.preventDefault();
        if (typeof Swal === 'undefined') {
            form.dataset.confirmed = '1';
            form.submit();
            return false;
        }

        Swal.fire({
            icon: 'warning',
            title: 'إصدار موازنة {{ $activeShiftGapBusinessDate }}',
            text: 'سيتم إصدار موازنة التاريخ المرجع فقط، ولن يتم إغلاق شفت تاريخ اليوم الحالي.',
            showCancelButton: true,
            confirmButtonText: 'إصدار الموازنة',
            cancelButtonText: 'إلغاء',
        }).then((result) => {
            if (result.isConfirmed) {
                form.dataset.confirmed = '1';
                form.submit();
            }
        });
        return false;
    @endif

    const selected = form.querySelector('input[name="next_shift_decision"]:checked');
    if (!selected || selected.value !== 'next_business_date') {
        return true;
    }

    event.preventDefault();
    if (typeof Swal === 'undefined') {
        form.dataset.confirmed = '1';
        form.submit();
        return false;
    }

    Swal.fire({
        icon: 'warning',
        title: 'الانتقال لتاريخ العمل التالي',
        text: 'سيتم إغلاق اليوم المحاسبي الحالي ولن يتم فتح شفت ثانٍ لهذا التاريخ إلا بتدخل المالك.',
        showCancelButton: true,
        confirmButtonText: 'متابعة',
        cancelButtonText: 'إلغاء',
    }).then((result) => {
        if (result.isConfirmed) {
            form.dataset.confirmed = '1';
            form.submit();
        }
    });

    return false;
}
</script>

@endsection
