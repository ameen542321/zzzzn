@extends('dashboard.app')
@section('title', 'مراجعة الشفتات الناقصة - ' . $store->name)

@section('content')
@php
    $zeroCloseRows = $gapRows->filter(function ($row) {
        $hasOperations = ($row['sales_count'] + $row['expenses_count'] + $row['withdrawals_count']) > 0;
        $isRequested = in_array($row['request_status'] ?? null, ['pending', 'in_progress'], true);

        return ! $hasOperations && ! $isRequested;
    })->values();
@endphp
<div class="max-w-6xl mx-auto p-6 space-y-6">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-white">مراجعة الشفتات الناقصة</h1>
            <p class="text-gray-400 text-sm mt-1">{{ $store->name }} — آخر 15 يومًا مكتملًا فقط</p>
        </div>
        <a href="{{ route('user.stores.show', $store->id) }}" class="px-4 py-2 rounded-xl bg-gray-800 hover:bg-gray-700 text-white text-sm transition">رجوع للمتجر</a>
    </div>

    <div class="rounded-2xl border border-cyan-700/50 bg-cyan-950/20 p-5 text-sm leading-7">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="text-cyan-100">
                <p class="font-black text-white flex items-center gap-2">
                    <i class="fa-solid fa-circle-info text-cyan-300"></i>
                    كيف تتعامل مع الأيام الناقصة؟
                </p>
                <p class="mt-1 text-cyan-100/80">
                    اليوم الذي يحتوي عمليات يُعاد للمحاسب ليُدخل/يربط الشفت الصحيح. اليوم الذي لا يحتوي أي عملية يمكن إغلاقه صفريًا كإجازة مع سجل تدقيق.
                </p>
            </div>
            @if($zeroCloseRows->isNotEmpty())
                <form method="POST" action="{{ route('user.stores.shift-gaps.zero-close', $store->id) }}"
                      class="shrink-0 js-shift-gap-confirm"
                      data-confirm-title="إغلاق الأيام الصفرية"
                      data-confirm-text="سيتم إغلاق الأيام الصفرية الظاهرة فقط، ولن يغلق النظام أي يوم يحتوي عمليات."
                      data-confirm-icon="question">
                    @csrf
                    @foreach($zeroCloseRows as $zeroRow)
                        <input type="hidden" name="business_dates[]" value="{{ $zeroRow['date'] }}">
                    @endforeach
                    <button type="submit" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 text-xs font-black shadow-lg shadow-emerald-950/30">
                        إغلاق {{ $zeroCloseRows->count() }} يوم صفري ظاهر
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- توضيح: أعيد بناء صفحة مراجعة الشفتات كبطاقات حتى تظهر قرارات المالك بوضوح بدل جدول مزدحم. --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @forelse($gapRows as $row)
            @php
                $hasOperations = ($row['sales_count'] + $row['expenses_count'] + $row['withdrawals_count']) > 0;
                $requestStatus = $row['request_status'] ?? null;
                $isRequested = in_array($requestStatus, ['pending', 'in_progress'], true);
                // توضيح: رقم الشفت لا يظهر للمالك إلا للمتاجر متعددة الشفتات؛ متجر شفت واحد يعامل كتاريخ محاسبي عادي.
                $shouldShowShiftLabel = (int) ($row['max_shifts'] ?? 1) > 1;
            @endphp
            <div class="rounded-3xl border border-gray-800 bg-gray-900/70 p-5 shadow-xl shadow-black/20 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                    <div>
                        <p class="text-xs text-gray-500 font-bold">التاريخ الناقص</p>
                        <h2 class="text-2xl font-black text-white font-mono mt-1">{{ $row['date'] }}</h2>
                        @if($shouldShowShiftLabel)
                            <p class="text-amber-100 text-sm font-black mt-2">{{ $row['shift_label'] }}</p>
                            @if(($row['closed_shifts_count'] ?? 0) > 0)
                                <p class="text-xs text-gray-400 mt-1">تم إغلاق {{ $row['closed_shifts_count'] }} شفت، والمتبقي هو الشفت {{ $row['missing_shift_number'] }}</p>
                            @endif
                        @endif
                    </div>
                    <div>
                        @if($requestStatus === 'in_progress')
                            <span class="rounded-full bg-blue-900/50 text-blue-100 px-3 py-1 text-xs font-bold">قيد المعالجة لدى المحاسب</span>
                        @elseif($requestStatus === 'pending')
                            <span class="rounded-full bg-blue-900/50 text-blue-100 px-3 py-1 text-xs font-bold">تم إرساله للمحاسب</span>
                        @elseif($hasOperations)
                            <span class="rounded-full bg-red-900/50 text-red-200 px-3 py-1 text-xs font-bold">به عمليات — يحتاج مراجعة</span>
                        @else
                            <span class="rounded-full bg-emerald-900/50 text-emerald-200 px-3 py-1 text-xs font-bold">لا توجد عمليات — مرشح للإغلاق الصفري</span>
                        @endif
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-2xl bg-black/20 border border-gray-800 p-3">
                        <p class="text-xs text-gray-500">مبيعات</p>
                        <p class="text-lg font-black text-white">{{ $row['sales_count'] }}</p>
                    </div>
                    <div class="rounded-2xl bg-black/20 border border-gray-800 p-3">
                        <p class="text-xs text-gray-500">مصروفات</p>
                        <p class="text-lg font-black text-white">{{ $row['expenses_count'] }}</p>
                    </div>
                    <div class="rounded-2xl bg-black/20 border border-gray-800 p-3">
                        <p class="text-xs text-gray-500">سحوبات</p>
                        <p class="text-lg font-black text-white">{{ $row['withdrawals_count'] }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 border-t border-gray-800 pt-4">
                    @if($isRequested)
                        <span class="rounded-xl bg-blue-950/40 border border-blue-700/30 px-3 py-2 text-blue-100 text-xs font-bold">بانتظار المحاسب</span>
                        <form method="POST" action="{{ route('user.stores.shift-gaps.request-accountant.cancel', $store->id) }}"
                              class="js-shift-gap-confirm"
                              data-confirm-title="إلغاء طلب المحاسب"
                              data-confirm-text="سيتم إلغاء الطلب الحالي لهذا الشفت، وبعدها يمكنك إرساله لمحاسب آخر."
                              data-confirm-icon="warning">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="business_date" value="{{ $row['date'] }}">
                            <input type="hidden" name="missing_shift_number" value="{{ $row['missing_shift_number'] }}">
                            <button type="submit" class="rounded-xl bg-red-600/20 border border-red-500/30 px-3 py-2 text-red-100 hover:bg-red-600/30 text-xs font-bold">إلغاء الطلب</button>
                        </form>
                        @if($activeAccountants->count() > 1)
                            <form method="POST" action="{{ route('user.stores.shift-gaps.request-accountant.reassign', $store->id) }}"
                                  class="flex flex-col sm:flex-row gap-2 sm:items-center js-shift-gap-confirm"
                                  data-confirm-title="إعادة تعيين الطلب"
                                  data-confirm-text="سيتم تحويل هذا الطلب إلى المحاسب المختار وإشعاره مباشرة."
                                  data-confirm-icon="question">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="business_date" value="{{ $row['date'] }}">
                                <input type="hidden" name="missing_shift_number" value="{{ $row['missing_shift_number'] }}">
                                <select name="accountant_id" required class="rounded-xl bg-gray-950 border border-gray-700 text-white px-3 py-2 text-xs">
                                    <option value="">اختر محاسبًا آخر</option>
                                    @foreach($activeAccountants as $accountantOption)
                                        <option value="{{ $accountantOption->id }}">{{ $accountantOption->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="rounded-xl bg-amber-600/20 border border-amber-500/30 px-3 py-2 text-amber-100 hover:bg-amber-600/30 text-xs font-bold">إعادة تعيين</button>
                            </form>
                        @endif
                    @else
                        <form method="POST" action="{{ route('user.stores.shift-gaps.request-accountant', $store->id) }}" class="flex flex-col sm:flex-row gap-2 sm:items-center">
                            @csrf
                            <input type="hidden" name="business_date" value="{{ $row['date'] }}">
                            <input type="hidden" name="missing_shift_number" value="{{ $row['missing_shift_number'] }}">
                            @if($activeAccountants->count() === 1)
                                @php
                                    $onlyAccountant = $activeAccountants->first();
                                @endphp
                                <input type="hidden" name="accountant_id" value="{{ $onlyAccountant->id }}">
                                {{-- توضيح: عند وجود محاسب واحد فقط لا نعرض قائمة اختيار؛ يظهر اسمه مباشرة منعًا لالتباس المالك. --}}
                                <span class="rounded-xl bg-gray-950 border border-gray-700 text-white px-3 py-2 text-xs">{{ $onlyAccountant->name }}</span>
                            @else
                                <select name="accountant_id" required class="rounded-xl bg-gray-950 border border-gray-700 text-white px-3 py-2 text-xs">
                                    <option value="">اختر محاسبًا فعالًا</option>
                                    @foreach($activeAccountants as $accountantOption)
                                        <option value="{{ $accountantOption->id }}">{{ $accountantOption->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <button type="submit" class="rounded-xl bg-blue-600/20 border border-blue-500/30 px-3 py-2 text-blue-100 hover:bg-blue-600/30 text-xs font-bold disabled:opacity-50" @disabled($activeAccountants->isEmpty())>إعادة للمحاسب</button>
                            @if($activeAccountants->isEmpty())
                                <span class="text-red-300 text-[11px]">لا يوجد محاسب فعال في هذا المتجر.</span>
                            @endif
                        </form>
                        @if(! $hasOperations)
                            <form method="POST" action="{{ route('user.stores.shift-gaps.zero-close', $store->id) }}"
                                  class="js-shift-gap-confirm"
                                  data-confirm-title="إغلاق صفري / إجازة"
                                  data-confirm-text="سيتم إنشاء إغلاق صفري لهذا اليوم لأنه لا يحتوي عمليات."
                                  data-confirm-icon="question">
                                @csrf
                                <input type="hidden" name="business_date" value="{{ $row['date'] }}">
                                <button type="submit" class="rounded-xl bg-emerald-600/20 border border-emerald-500/30 px-3 py-2 text-emerald-100 hover:bg-emerald-600/30 text-xs font-bold">إغلاق صفري</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('user.stores.shift-gaps.zero-close', $store->id) }}"
                                  class="js-shift-gap-confirm"
                                  data-confirm-title="إغلاق الشفت واعتماد عملياته"
                                  data-confirm-text="سيتم إغلاق هذا الشفت بواسطة المالك اعتمادًا على العمليات الموجودة، بدون إصدار PDF أو رسالة واتساب."
                                  data-confirm-icon="warning">
                                @csrf
                                <input type="hidden" name="business_date" value="{{ $row['date'] }}">
                                <input type="hidden" name="close_with_operations" value="1">
                                <button type="submit" class="rounded-xl bg-amber-600/20 border border-amber-500/30 px-3 py-2 text-amber-100 hover:bg-amber-600/30 text-xs font-bold">إغلاق من المالك</button>
                            </form>
                        @endif
                    @endif
                    <a href="{{ route('user.stores.daily', ['store' => $store->id, 'date' => $row['date']]) }}" class="rounded-xl bg-cyan-600/20 border border-cyan-500/30 px-3 py-2 text-cyan-100 hover:bg-cyan-600/30 text-xs font-bold">فتح المبيعات</a>
                </div>
            </div>
        @empty
            <div class="lg:col-span-2 rounded-3xl border border-gray-800 bg-gray-900/70 p-8 text-center text-gray-400">
                لا توجد شفتات ناقصة ضمن آخر 15 يومًا مكتملًا.
            </div>
        @endforelse
    </div>

    <div class="rounded-2xl border border-amber-800/60 bg-amber-950/20 p-5">
        <div class="mb-4">
            <h2 class="text-lg font-black text-white flex items-center gap-2">
                <i class="fa-solid fa-arrows-left-right-to-line text-amber-300"></i>
                نقل تاريخ شفت مغلق
            </h2>
            <p class="text-sm text-amber-100/80 mt-1">
                استخدم هذا الإجراء فقط لتصحيح شفت أُغلق على تاريخ محاسبي خاطئ. سيتم نقل تاريخ الشفت والعمليات المرتبطة به إلى التاريخ الجديد مع تسجيل القرار.
            </p>
        </div>

        <form method="POST" action="{{ route('user.stores.shift-gaps.move-balance', $store->id) }}"
              class="grid grid-cols-1 md:grid-cols-4 gap-3 js-shift-gap-confirm"
              data-confirm-title="تأكيد نقل تاريخ الشفت"
              data-confirm-text="سيتم تغيير التاريخ المحاسبي للشفت المختار وكل المبيعات والمصروفات والسحوبات المرتبطة به. هل تريد المتابعة؟"
              data-confirm-icon="warning">
            @csrf
            <div>
                <label class="block text-xs font-bold text-gray-300 mb-1">الشفت المراد نقله</label>
                <select name="daily_balance_id" required class="w-full rounded-xl bg-gray-950 border border-gray-700 text-white px-3 py-2 text-sm">
                    <option value="">اختر شفتًا مغلقًا</option>
                    @foreach($recentBalances as $balance)
                        @php
                            $balanceDate = $balance->business_date?->toDateString() ?: optional($balance->start_time)->toDateString();
                        @endphp
                        <option value="{{ $balance->id }}">
                            #{{ $balance->id }} — {{ $balanceDate }} — {{ optional($balance->end_time)->format('Y-m-d H:i') }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-300 mb-1">التاريخ الجديد</label>
                <input type="date" name="target_business_date" required class="w-full rounded-xl bg-gray-950 border border-gray-700 text-white px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-300 mb-1">سبب النقل</label>
                <input type="text" name="reason" maxlength="500" class="w-full rounded-xl bg-gray-950 border border-gray-700 text-white px-3 py-2 text-sm" placeholder="مثال: تصحيح إدخال يوم مرجع">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-xl bg-amber-600 hover:bg-amber-500 text-white px-4 py-2 text-sm font-black">
                    نقل الشفت
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.js-shift-gap-confirm').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();

            const title = form.dataset.confirmTitle || 'تأكيد الإجراء';
            const text = form.dataset.confirmText || 'هل تريد المتابعة؟';
            const icon = form.dataset.confirmIcon || 'question';

            if (typeof Swal === 'undefined') {
                if (window.confirm(`${title}\n\n${text}`)) {
                    form.dataset.confirmed = '1';
                    form.submit();
                }

                return;
            }

            const result = await Swal.fire({
                title,
                text,
                icon,
                showCancelButton: true,
                confirmButtonText: 'نعم، متابعة',
                cancelButtonText: 'إلغاء',
                reverseButtons: true,
            });

            if (result.isConfirmed) {
                form.dataset.confirmed = '1';
                form.submit();
            }
        });
    });
});
</script>
@endsection
