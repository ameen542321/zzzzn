<?php

namespace App\Http\Controllers;

use App\Services\LogService;
use App\Models\Store;
use App\Models\DailyBalance;
use App\Models\Expense;
use App\Models\Withdrawal;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\Accountant;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\ArabicPdf as PDF;
use App\Services\ShiftLifecycleService;
use App\Services\Stores\StoreAccessService;
use App\Services\Stores\ActiveAccountantService;
use App\Services\Stores\StoreDetailsService;
use App\Services\Stores\StoreDashboardService;
use App\Services\Shifts\ShiftGapInfoService;
use App\Services\Shifts\ShiftGapRequestService;
use App\Services\Shifts\ShiftGapOverviewService;
use App\Services\Shifts\ShiftSettingsHistoryService;
use App\Services\Reports\MonthlyStoreReportService;
use App\Services\Reports\ComprehensiveStoreSearchReportService;
use App\Services\Reports\RecentReportFilesService;

/**
 * ===================================================================
 * StoreController - إدارة المتاجر
 * ===================================================================
 *
 * هذا الكنترولر مسؤول عن جميع عمليات المتاجر:
 * - إنشاء، تعديل، عرض، حذف المتاجر
 * - إحصائيات المتاجر (المخزون، المبيعات، الموظفين)
 * - إدارة حالة المتاجر (نشط/معطل)
 * - سلة المهملات واستعادة المتاجر المحذوفة
 *
 * جميع الدوال تتحقق من ملكية المستخدم للمتجر قبل التنفيذ
 * -------------------------------------------------------------------
 */

class StoreController extends Controller
{
    /**
     * =================================================================
     * دوال التحقق من الصلاحية والخطة
     * =================================================================
     */

    /**
     * التحقق من صلاحية إنشاء متجر جديد حسب الخطة
     *
     * @return bool
     */
    protected function canUserAddStore()
    {
        $user = auth()->user();
        if (!$user->plan_id && !$user->allowed_stores) return false;

        $allowed = $user->plan_id ? $user->plan->allowed_stores : $user->allowed_stores;

        // استخدام withTrashed() لحساب المحذوف أيضاً
        return Store::withTrashed()->where('user_id', $user->id)->count() < $allowed;
    }

    /**
     * =================================================================
     * دوال CRUD الأساسية (إنشاء، عرض، تعديل، حذف)
     * =================================================================
     */

    /**
     * عرض قائمة المتاجر
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $user = auth()->user();

        // المتاجر الحالية (التي لم تحذف)
        $stores = $user->stores()->latest()->get();

        // إجمالي المتاجر (نشطة + في السلة) لغرض التحقق من الخطة
        $totalCountWithTrashed = Store::withTrashed()
            ->where('user_id', $user->id)
            ->count();

        // عدد المحذوفات فقط للعرض في الأيقونة
        $trashedCount = $user->stores()->onlyTrashed()->count();

        return view('user.stores.index', compact('stores', 'trashedCount', 'totalCountWithTrashed'));
    }

    /**
     * عرض صفحة إنشاء متجر جديد
     *
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function create()
    {
        $user = auth()->user();
        $totalUsed = $user->stores()->withTrashed()->count();
        $allowed = $user->plan->allowed_stores ?? $user->allowed_stores ?? 1;

        if ($totalUsed >= $allowed) {
            return redirect()->route('user.stores.index')
                ->with('error', 'لقد استنفدت الحد الأقصى للمتاجر المسموح بها في خطتك.');
        }

        return view('user.stores.create');
    }

    /**
     * حفظ متجر جديد في قاعدة البيانات
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        // التحقق من البيانات
        $request->validate([
            'name'                => 'required|string|max:255',
            'phone'               => 'nullable|string|max:255',
            'address'             => 'nullable|string|max:255',
            'commercial_registration' => 'nullable|string|max:255',
            'tax_number'          => 'nullable|string|max:255',
            'description'         => 'nullable|string',
            'number_of_shifts'     => 'required|integer|in:1,2',
        ]);

        $user = auth()->user();

        // التحقق من الحصة (النشط + المحذوف)
        $totalUsed = $user->stores()->withTrashed()->count();
        $allowed   = $user->allowed_stores ?? ($user->plan->allowed_stores ?? 1);

        if ($totalUsed >= $allowed) {
            return redirect()->back()->with('error', 'لقد وصلت للحد الأقصى المسموح به في خطتك.');
        }

        // الحفظ الفعلي
        $user->stores()->create([
            'user_id'             => $user->id,
            'name'                => $request->name,
            'phone'               => $request->phone,
            'address'             => $request->address,
            // [تعديل آمن] توحيد اسم الحقل مع نماذج الإنشاء/التعديل والبطاقات.
            'commercial_registration' => $request->commercial_registration,
            'tax_number'          => $request->tax_number,
            'description'         => $request->description,
            'number_of_shifts'     => (int) $request->number_of_shifts,
            'logo'                => null,
            'status'              => 'active',
            'slug'                => Str::slug($request->name) . '-' . uniqid(),
            'expires_at'          => null,
        ]);

        return redirect()->route('user.stores.index')->with('success', 'تم إنشاء المتجر بنجاح مع كافة البيانات الضريبية.');
    }

    /**
     * عرض صفحة تعديل المتجر
     *
     * @param Store $store
     * @return \Illuminate\View\View
     */
    public function edit(Store $store)
    {
        // التأكد أن المالك هو من يحاول التعديل
        if ($store->user_id !== auth()->id()) {
            abort(403);
        }

        return view('user.stores.edit', compact('store'));
    }

    /**
     * تحديث بيانات المتجر
     *
     * @param Request $request
     * @param Store $store
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Store $store)
    {
        // التحقق من الملكية
        if ($store->user_id !== auth()->id()) {
            abort(403);
        }

        // التحقق من البيانات
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'tax_number' => 'nullable|string',
            'commercial_registration' => 'nullable|string',
            'bank_accounts' => 'nullable|string',
            'number_of_shifts' => 'required|integer|in:1,2',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $validated['number_of_shifts'] = (int) $validated['number_of_shifts'];
        $previousNumberOfShifts = (int) $store->number_of_shifts;

        // معالجة رفع الشعار
        if ($request->hasFile('logo')) {
            // حذف الشعار القديم
            if ($store->logo && file_exists(public_path('storage/' . $store->logo))) {
                @unlink(public_path('storage/' . $store->logo));
            }

            // تخزين الشعار الجديد
            $path = $request->file('logo')->store('stores/logos', 'public');
            $validated['logo'] = $path;
        }

        // تحديث البيانات
        $store->update($validated);

        app(ShiftSettingsHistoryService::class)->recordShiftCountChange(
            $store->fresh(),
            $previousNumberOfShifts,
            (int) $validated['number_of_shifts'],
            (int) auth()->id()
        );

        $this->normalizeLatestShiftDecisionAfterShiftCountChange(
            $store->fresh(),
            $previousNumberOfShifts,
            (int) $validated['number_of_shifts']
        );

        $redirectRoute = $request->input('return_to') === 'show'
            ? redirect()->route('user.stores.show', $store)
            : redirect()->route('user.stores.index');

        return $redirectRoute->with('success', 'تم تحديث بيانات المتجر بنجاح');
    }

    /**
     * =================================================================
     * دوال العرض والصفحات (show, details)
     * =================================================================
     */

    /**
     * عرض الصفحة الرئيسية للمتجر (إحصائيات سريعة)
     *
     * @param Store $store
     * @return \Illuminate\View\View
     */
    public function show(Store $store)
    {
        // التحقق من ملكية المتجر
        if ($store->user_id !== auth()->id()) {
            abort(403);
        }

        $dashboardSummary = app(StoreDashboardService::class)->summary($store);
        $secondShiftRestoreCandidate = $this->secondShiftRestoreCandidate($store);
        $secondShiftRestoreBlocked = $secondShiftRestoreCandidate
            ? $this->hasOperationsAfterShiftClose($store->id, $secondShiftRestoreCandidate)
            : false;

        return view('user.stores.show', array_merge($dashboardSummary, [
            'store' => $store,
            'user' => auth()->user(),
            'secondShiftRestoreCandidate' => $secondShiftRestoreCandidate,
            'secondShiftRestoreBlocked' => $secondShiftRestoreBlocked,
        ]));
    }

    private function normalizeLatestShiftDecisionAfterShiftCountChange(Store $store, int $previousNumberOfShifts, int $newNumberOfShifts): void
    {
        if ($previousNumberOfShifts === $newNumberOfShifts) {
            return;
        }

        $lastBalance = DailyBalance::query()
            ->where('store_id', $store->id)
            ->whereNotNull('end_time')
            ->latest('end_time')
            ->first();

        if (! $lastBalance) {
            return;
        }

        $businessDate = $lastBalance->business_date
            ? $lastBalance->business_date->toDateString()
            : $lastBalance->created_at->toDateString();

        if ($newNumberOfShifts === 1 || ($previousNumberOfShifts < 2 && $newNumberOfShifts === 2)) {
            $lastBalance->update([
                'next_shift_business_date' => \Carbon\Carbon::parse($businessDate)->addDay()->toDateString(),
                'next_shift_decision' => 'next_business_date',
                'next_shift_decided_by' => null,
            ]);
        }
    }

    public function restoreSecondShift(Store $store)
    {
        if ($store->user_id !== auth()->id()) {
            abort(403);
        }

        $candidate = $this->secondShiftRestoreCandidate($store);

        if (! $candidate) {
            return redirect()->route('user.stores.show', $store)
                ->with('error', 'لا يوجد شفت ثاني قابل لإعادة التفعيل لهذا المتجر.');
        }

        if ($this->hasOperationsAfterShiftClose($store->id, $candidate)) {
            return redirect()->route('user.stores.show', $store)
                ->with('error', 'لا يمكن إعادة تفعيل الشفت الثاني بعد تسجيل عمليات لاحقة. راجع البيانات أولاً.');
        }

        $businessDate = $candidate->business_date
            ? $candidate->business_date->toDateString()
            : $candidate->created_at->toDateString();

        $candidate->update([
            'next_shift_business_date' => $businessDate,
            'next_shift_decision' => 'same_business_date',
            'next_shift_decided_by' => null,
            'notes' => trim(($candidate->notes ? $candidate->notes . "\n" : '') . 'تمت إعادة تفعيل الشفت الثاني بواسطة المالك: ' . auth()->user()->name),
        ]);

        return redirect()->route('user.stores.show', $store)
            ->with('success', 'تمت إعادة تفعيل الشفت الثاني لنفس التاريخ المحاسبي بنجاح.');
    }

    private function secondShiftRestoreCandidate(Store $store): ?DailyBalance
    {
        if ((int) $store->number_of_shifts < 2) {
            return null;
        }

        $lastBalance = DailyBalance::query()
            ->where('store_id', $store->id)
            ->whereNotNull('end_time')
            ->latest('end_time')
            ->first();

        if (! $lastBalance || $lastBalance->next_shift_decision !== 'next_business_date' || ! $lastBalance->next_shift_business_date) {
            return null;
        }

        return $lastBalance;
    }

    private function hasOperationsAfterShiftClose(int $storeId, DailyBalance $balance): bool
    {
        $closedAt = $balance->end_time;

        if (! $closedAt) {
            return true;
        }

        return Sale::where('store_id', $storeId)->where('created_at', '>', $closedAt)->exists()
            || Expense::where('store_id', $storeId)->where('created_at', '>', $closedAt)->exists()
            || Withdrawal::where('store_id', $storeId)->where('created_at', '>', $closedAt)->exists();
    }

    public function requestAccountantShiftInput(Request $request, Store $store)
    {
        $this->authorizeStoreAccess($store);

        if (! $this->storeAccessService()->isUsableForShiftWorkflow($store)) {
            return back()->with('error', 'المتجر غير مفعل ولا يدخل ضمن خدمات الشفتات.');
        }

        $validated = $request->validate([
            'business_date' => 'required|date',
            'accountant_id' => 'required|integer',
            'missing_shift_number' => 'nullable|integer|min:1|max:3',
        ]);

        $date = \Carbon\Carbon::parse($validated['business_date'])->toDateString();
        $accountant = app(ActiveAccountantService::class)->findActiveAccountantForStore(
            $store,
            auth()->user(),
            (int) $validated['accountant_id']
        );

        if (! $accountant) {
            return back()->with('error', 'يرجى اختيار محاسب فعال مرتبط بهذا المتجر.');
        }

        if (! in_array($date, app(ShiftLifecycleService::class)->missingBusinessDates($store->id), true)) {
            return back()->with('error', 'هذا التاريخ لم يعد ضمن قائمة الشفتات الناقصة.');
        }

        $shiftInfo = app(ShiftGapInfoService::class)->shiftInfo($store, $date);
        $requestedShiftNumber = (int) ($validated['missing_shift_number'] ?? $shiftInfo['missing_shift_number']);
        if ($requestedShiftNumber !== (int) $shiftInfo['missing_shift_number']) {
            return back()->with('error', 'رقم الشفت المطلوب لم يعد مطابقًا لحالة اليوم الحالية. يرجى تحديث الصفحة.');
        }

        if (app(ShiftGapRequestService::class)->activeStatus($store->id, $date, $requestedShiftNumber)) {
            return back()->with('info', 'تم إرسال هذا الشفت للمحاسب سابقًا، وهو بانتظار المعالجة.');
        }

        app(ShiftGapRequestService::class)->createOwnerRequest(
            $store,
            auth()->user(),
            $accountant,
            $date,
            $shiftInfo
        );

        return back()->with('success', 'تم تسجيل طلب إعادة اليوم للمحاسب في سجل العمليات.');
    }

    public function cancelAccountantShiftInputRequest(Request $request, Store $store)
    {
        $this->authorizeStoreAccess($store);

        if (! $this->storeAccessService()->isUsableForShiftWorkflow($store)) {
            return back()->with('error', 'المتجر غير مفعل ولا يدخل ضمن خدمات الشفتات.');
        }

        $validated = $request->validate([
            'business_date' => 'required|date',
            'missing_shift_number' => 'required|integer|min:1|max:3',
        ]);

        $businessDate = \Carbon\Carbon::parse($validated['business_date'])->toDateString();
        $missingShiftNumber = (int) $validated['missing_shift_number'];

        $wasCanceled = app(ShiftGapRequestService::class)->cancelOwnerRequest(
            $store,
            auth()->user(),
            $businessDate,
            $missingShiftNumber
        );

        if (! $wasCanceled) {
            return back()->with('info', 'لا يوجد طلب نشط لهذا الشفت حتى يتم إلغاؤه.');
        }

        return back()->with('success', 'تم إلغاء طلب المحاسب ويمكنك الآن إعادة إرساله لمحاسب آخر.');
    }

    public function reassignAccountantShiftInputRequest(Request $request, Store $store)
    {
        $this->authorizeStoreAccess($store);

        if (! $this->storeAccessService()->isUsableForShiftWorkflow($store)) {
            return back()->with('error', 'المتجر غير مفعل ولا يدخل ضمن خدمات الشفتات.');
        }

        $validated = $request->validate([
            'business_date' => 'required|date',
            'missing_shift_number' => 'required|integer|min:1|max:3',
            'accountant_id' => 'required|integer',
        ]);

        $businessDate = \Carbon\Carbon::parse($validated['business_date'])->toDateString();
        $missingShiftNumber = (int) $validated['missing_shift_number'];
        $newAccountant = app(ActiveAccountantService::class)->findActiveAccountantForStore(
            $store,
            auth()->user(),
            (int) $validated['accountant_id']
        );

        if (! $newAccountant) {
            return back()->with('error', 'يرجى اختيار محاسب فعال مرتبط بهذا المتجر.');
        }

        $wasReassigned = app(ShiftGapRequestService::class)->reassignOwnerRequest(
            $store,
            auth()->user(),
            $newAccountant,
            $businessDate,
            $missingShiftNumber
        );

        if (! $wasReassigned) {
            return back()->with('info', 'لا يوجد طلب نشط لهذا الشفت حتى تتم إعادة تعيينه.');
        }

        return back()->with('success', 'تمت إعادة تعيين طلب الشفت للمحاسب المختار.');
    }

    public function zeroCloseShiftGap(Request $request, Store $store)
    {
        $this->authorizeStoreAccess($store);

        if (! $this->storeAccessService()->isUsableForShiftWorkflow($store)) {
            return back()->with('error', 'المتجر غير مفعل ولا يدخل ضمن خدمات الشفتات.');
        }

        $validated = $request->validate([
            'business_date' => 'nullable|required_without:business_dates|date',
            'business_dates' => 'nullable|array',
            'business_dates.*' => 'date',
        ]);

        $dates = collect($validated['business_dates'] ?? [$validated['business_date']])
            ->filter()
            ->map(fn ($date) => \Carbon\Carbon::parse($date)->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return back()->with('error', 'لم يتم تحديد أي يوم للإغلاق الصفري.');
        }

        $missingDates = app(ShiftLifecycleService::class)->missingBusinessDates($store->id);

        $accountantId = Accountant::where('store_id', $store->id)->value('id');
        if (! $accountantId) {
            return back()->with('error', 'لا يمكن إنشاء إغلاق صفري بدون وجود محاسب مرتبط بالمتجر.');
        }

        $closedDates = [];
        $operationClosedDates = [];
        $blockedDates = [];
        $ignoredDates = [];
        foreach ($dates as $date) {
            if (! in_array($date, $missingDates, true)) {
                $ignoredDates[] = $date;
                continue;
            }

            $operationCounts = app(ShiftGapOverviewService::class)->operationCounts($store, $date);
            if (($operationCounts['sales_count'] + $operationCounts['expenses_count'] + $operationCounts['withdrawals_count']) > 0) {
                // إغلاق المالك يعتمد بيانات اليوم المحدد سواء كان فارغًا أو يحتوي عمليات، ولا يوجد إغلاق آلي بدون طلب المالك.
                $salesQuery = Sale::where('store_id', $store->id)
                    ->where(function ($query) use ($date) {
                        $query->whereDate('business_date', $date)
                            ->orWhere(function ($legacyQuery) use ($date) {
                                $legacyQuery->whereNull('business_date')
                                    ->whereDate('created_at', $date);
                            });
                    })
                    ->whereNull('daily_balance_id')
                    ->where(function ($query) {
                        $query->whereNull('description')
                            ->orWhere('description', '!=', 'manual_invoice_entry');
                    });

                $totalSales = (float) (clone $salesQuery)->sum('paid_amount');
                $cashSales = (float) (clone $salesQuery)->where('sale_type', 'cash')->sum('paid_amount')
                    + (float) (clone $salesQuery)->where('sale_type', 'mixed')->sum('cash_amount');
                $expenses = (float) Expense::where('store_id', $store->id)
                    ->whereDate('business_date', $date)
                    ->whereNull('daily_balance_id')
                    ->sum('amount');
                $withdrawals = (float) Withdrawal::where('store_id', $store->id)
                    ->whereDate('business_date', $date)
                    ->whereNull('daily_balance_id')
                    ->sum('amount');
                $expectedCash = $cashSales - $expenses - $withdrawals;

                $dailyBalance = DailyBalance::create([
                    'store_id' => $store->id,
                    'accountant_id' => $accountantId,
                    'system_sales_total' => $totalSales,
                    'system_cash_expected' => $expectedCash,
                    'actual_cash_submitted' => $expectedCash,
                    'difference' => 0,
                    'start_time' => \Carbon\Carbon::parse($date)->startOfDay(),
                    'end_time' => \Carbon\Carbon::parse($date)->endOfDay(),
                    'business_date' => $date,
                    'closed_at' => now(),
                    'next_shift_business_date' => \Carbon\Carbon::parse($date)->addDay()->toDateString(),
                    'next_shift_decision' => 'next_business_date',
                    'next_shift_decided_by' => null,
                    'notes' => 'إغلاق مالك لشفت يحتوي عمليات بدون تقرير PDF: ' . auth()->user()->name,
                ]);

                Sale::where('store_id', $store->id)
                    ->where(function ($query) use ($date) {
                        $query->whereDate('business_date', $date)
                            ->orWhere(function ($legacyQuery) use ($date) {
                                $legacyQuery->whereNull('business_date')
                                    ->whereDate('created_at', $date);
                            });
                    })
                    ->whereNull('daily_balance_id')
                    ->update(['business_date' => $date, 'daily_balance_id' => $dailyBalance->id]);

                Expense::where('store_id', $store->id)
                    ->where(function ($query) use ($date) {
                        $query->whereDate('business_date', $date)
                            ->orWhere(function ($legacyQuery) use ($date) {
                                $legacyQuery->whereNull('business_date')
                                    ->whereDate('created_at', $date);
                            });
                    })
                    ->whereNull('daily_balance_id')
                    ->update(['business_date' => $date, 'daily_balance_id' => $dailyBalance->id]);

                Withdrawal::where('store_id', $store->id)
                    ->where(function ($query) use ($date) {
                        $query->whereDate('business_date', $date)
                            ->orWhere(function ($legacyQuery) use ($date) {
                                $legacyQuery->whereNull('business_date')
                                    ->whereDate('created_at', $date);
                            });
                    })
                    ->whereNull('daily_balance_id')
                    ->update(['business_date' => $date, 'daily_balance_id' => $dailyBalance->id]);

                app(LogService::class)->add(
                    'shift_gap_owner_closed_with_operations',
                    'أغلق المالك شفتًا يحتوي عمليات بتاريخ ' . $date,
                    $dailyBalance,
                    ['business_date' => $date, 'store_id' => $store->id, 'operation_counts' => $operationCounts]
                );

                $closedDates[] = $date;
                $operationClosedDates[] = $date;
                continue;
            }

            $dailyBalance = DailyBalance::create([
                'store_id' => $store->id,
                'accountant_id' => $accountantId,
                'system_sales_total' => 0,
                'system_cash_expected' => 0,
                'actual_cash_submitted' => 0,
                'difference' => 0,
                'start_time' => \Carbon\Carbon::parse($date)->startOfDay(),
                'end_time' => \Carbon\Carbon::parse($date)->endOfDay(),
                'business_date' => $date,
                'closed_at' => now(),
                'next_shift_business_date' => \Carbon\Carbon::parse($date)->addDay()->toDateString(),
                'next_shift_decision' => 'next_business_date',
                'next_shift_decided_by' => null,
                'notes' => 'إغلاق صفري / إجازة بواسطة المالك: ' . auth()->user()->name,
            ]);

            app(LogService::class)->add(
                'shift_gap_zero_closed',
                'تم تحديد يوم كشف صفري / إجازة بتاريخ ' . $date,
                $dailyBalance,
                ['business_date' => $date, 'store_id' => $store->id]
            );

            $closedDates[] = $date;
        }

        if (empty($closedDates) && ! empty($blockedDates)) {
            return back()->with('error', 'لم يتم الإغلاق الصفري لأن الأيام المحددة تحتوي عمليات: ' . implode('، ', $blockedDates));
        }

        if (empty($closedDates)) {
            return back()->with('error', 'لم يتم إنشاء أي إغلاق صفري. الأيام المحددة لم تعد ضمن قائمة الشفتات الناقصة: ' . implode('، ', $ignoredDates));
        }

        $zeroClosedDates = array_values(array_diff($closedDates, $operationClosedDates));
        $messageParts = [];
        if (! empty($zeroClosedDates)) {
            $messageParts[] = 'تم إنشاء إغلاق صفري للأيام: ' . implode('، ', $zeroClosedDates);
        }
        if (! empty($operationClosedDates)) {
            $messageParts[] = 'تم إغلاق شفتات تحتوي عمليات واعتماد بياناتها للأيام: ' . implode('، ', $operationClosedDates);
        }
        $message = implode('، ', $messageParts);
        if (! empty($blockedDates)) {
            $message .= '، وتم تجاهل أيام تحتوي عمليات وتحتاج مراجعة: ' . implode('، ', $blockedDates);
        }

        return back()->with('success', $message);
    }

    public function moveShiftBalanceDate(Request $request, Store $store)
    {
        $this->authorizeStoreAccess($store);

        if (! $this->storeAccessService()->isUsableForShiftWorkflow($store)) {
            return back()->with('error', 'المتجر غير مفعل ولا يمكن نقل شفتاته قبل تفعيله.');
        }

        $validated = $request->validate([
            'daily_balance_id' => 'required|integer',
            'target_business_date' => 'required|date',
            'reason' => 'nullable|string|max:500',
        ]);

        $balance = DailyBalance::where('store_id', $store->id)
            ->whereKey($validated['daily_balance_id'])
            ->firstOrFail();

        $targetDate = \Carbon\Carbon::parse($validated['target_business_date'])->toDateString();
        $sourceDate = $balance->business_date
            ? $balance->business_date->toDateString()
            : ($balance->start_time ? \Carbon\Carbon::parse($balance->start_time)->toDateString() : null);

        if ($sourceDate === $targetDate) {
            return back()->with('error', 'الشفت موجود بالفعل على نفس التاريخ المطلوب.');
        }

        $maxShifts = app(ShiftLifecycleService::class)->maxShiftsPerBusinessDate($store);
        $targetClosedCount = DailyBalance::where('store_id', $store->id)
            ->where('id', '!=', $balance->id)
            ->whereNotNull('end_time')
            ->whereDate('business_date', $targetDate)
            ->count();

        if ($targetClosedCount >= $maxShifts) {
            return back()->with('error', 'لا يمكن النقل؛ التاريخ الهدف وصل إلى عدد الشفتات المسموح لهذا المتجر.');
        }

        DB::transaction(function () use ($balance, $targetDate, $sourceDate, $validated) {
            $balance->update([
                'business_date' => $targetDate,
                'notes' => trim(($balance->notes ? $balance->notes . "\n" : '') . 'نقل تاريخ الشفت من ' . ($sourceDate ?: 'غير محدد') . ' إلى ' . $targetDate . ' بواسطة المالك: ' . auth()->user()->name),
            ]);

            Sale::where('daily_balance_id', $balance->id)->update(['business_date' => $targetDate]);
            Expense::where('daily_balance_id', $balance->id)->update(['business_date' => $targetDate]);
            Withdrawal::where('daily_balance_id', $balance->id)->update([
                'business_date' => $targetDate,
                'date' => $targetDate,
                'month' => \Carbon\Carbon::parse($targetDate)->format('Y-m'),
            ]);

            $start = $balance->start_time ? \Carbon\Carbon::parse($balance->start_time) : null;
            $end = $balance->end_time ? \Carbon\Carbon::parse($balance->end_time) : null;

            if ($start && $end) {
                Sale::where('store_id', $balance->store_id)
                    ->whereNull('daily_balance_id')
                    ->whereBetween('created_at', [$start, $end])
                    ->update(['business_date' => $targetDate, 'daily_balance_id' => $balance->id]);

                Expense::where('store_id', $balance->store_id)
                    ->whereNull('daily_balance_id')
                    ->whereBetween('created_at', [$start, $end])
                    ->update(['business_date' => $targetDate, 'daily_balance_id' => $balance->id]);

                Withdrawal::where('store_id', $balance->store_id)
                    ->whereNull('daily_balance_id')
                    ->where(function ($query) use ($start, $end, $sourceDate) {
                        $query->whereBetween('created_at', [$start, $end]);

                        if ($sourceDate) {
                            $query->orWhereDate('date', $sourceDate);
                        }
                    })
                    ->update([
                        'business_date' => $targetDate,
                        'daily_balance_id' => $balance->id,
                        'date' => $targetDate,
                        'month' => \Carbon\Carbon::parse($targetDate)->format('Y-m'),
                    ]);
            }

            app(LogService::class)->add(
                'shift_business_date_moved',
                'نقل المالك تاريخ شفت من ' . ($sourceDate ?: 'غير محدد') . ' إلى ' . $targetDate,
                $balance,
                [
                    'from_business_date' => $sourceDate,
                    'to_business_date' => $targetDate,
                    'reason' => $validated['reason'] ?? null,
                ]
            );
        });

        return back()->with('success', 'تم نقل تاريخ الشفت وربط عملياته بالتاريخ الجديد بنجاح.');
    }

    public function shiftGaps(Store $store)
    {
        $this->authorizeStoreAccess($store);

        $overviewData = app(ShiftGapOverviewService::class)->ownerOverview($store, auth()->user());

        return view('user.stores.shift-gaps', array_merge(['store' => $store], $overviewData));
    }

    /**
     * عرض صفحة التفاصيل المتقدمة للمتجر
     * (إحصائيات شاملة: مخزون، موظفين، مبيعات، أرباح)
     *
     * @param int $storeId
     * @return \Illuminate\View\View
     */
    public function details($storeId)
    {
        $store = auth()->user()->stores()->findOrFail($storeId);
        $detailsData = app(StoreDetailsService::class)->build($store);

        return view('user.stores.details', $detailsData);
    }

    /**
     * =================================================================
     * دوال إدارة الحالة والإعدادات
     * =================================================================
     */

    /**
     * تعيين متجر كمتجر حالي للمستخدم
     *
     * @param Store $store
     * @return \Illuminate\Http\RedirectResponse
     */
    public function setCurrentStore(Store $store)
    {
        $this->authorizeStoreAccess($store);

        if (! $this->storeAccessService()->isActive($store)) {
            return back()->with('error', 'لا يمكن تعيين متجر معطل كمتجر حالي');
        }

        auth()->user()->update(['current_store_id' => $store->id]);

        // تسجيل العملية
        app(LogService::class)->add(
            action: 'set_current',
            description: 'تم تعيين المتجر كمتجر حالي',
            model: $store,
            details: ['name' => $store->name],
        );

        return back()->with('success', 'تم تعيين ' . $store->name . ' كمتجر حالي');
    }

    /**
     * تغيير حالة المتجر (تفعيل/تعطيل)
     *
     * @param Store $store
     * @return \Illuminate\Http\RedirectResponse
     */
    public function toggleStatus(Store $store)
    {
        $this->authorizeStoreAccess($store);

        // تبديل الحالة تلقائياً
        $oldStatus = $store->status;
        $newStatus = ($oldStatus === 'active') ? 'suspended' : 'active';

        $store->update([
            'status' => $newStatus,
            'suspension_reason' => $newStatus == 'suspended' ? 'تم الإيقاف بواسطة المالك' : null
        ]);

        // تسجيل العملية
        app(LogService::class)->add(
            action: 'status_change',
            description: 'تم تغيير حالة المتجر إلى ' . ($newStatus == 'active' ? 'نشط' : 'معطل'),
            model: $store,
            details: ['old_status' => $oldStatus, 'new_status' => $newStatus],
        );

        $message = $newStatus == 'active' ? 'تم تفعيل المتجر بنجاح' : 'تم إيقاف المتجر بنجاح';
        return back()->with('success', $message);
    }

    /**
     * =================================================================
     * دوال سلة المهملات والحذف
     * =================================================================
     */

    /**
     * حذف المتجر (نقل للسلة)
     *
     * @param Store $store
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Store $store)
    {
        $this->authorizeStoreAccess($store);

        DB::beginTransaction();
        try {
            // تسجيل العملية قبل الحذف
            app(LogService::class)->add(
                action: 'delete',
                description: 'تم نقل المتجر إلى سلة المهملات',
                model: $store,
                details: ['name' => $store->name],
            );

            // حذف المتجر (Soft Delete)
            $store->delete();

            DB::commit();

            return redirect()->route('user.stores.index')
                ->with('success', 'تم نقل المتجر إلى سلة المهملات بنجاح');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->route('user.stores.show', $store)
                ->with('error', 'حدث خطأ أثناء حذف المتجر: ' . $e->getMessage());
        }
    }

    /**
     * عرض سلة المهملات (المتاجر المحذوفة)
     *
     * @return \Illuminate\View\View
     */
    public function trash()
    {
        $stores = Store::onlyTrashed()
            ->where('user_id', auth()->id())
            ->latest()
            ->get();

        return view('user.stores.trash', compact('stores'));
    }

    /**
     * استعادة متجر محذوف
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function restore($id)
    {
        $user = auth()->user();
        $store = Store::onlyTrashed()
            ->where('user_id', $user->id)
            ->findOrFail($id);

        // التحقق من الخطة
        $allowed = $user->plan_id ? $user->plan->allowed_stores : ($user->allowed_stores ?? 1);
        $activeCount = $user->stores()->count();

        if ($activeCount >= $allowed) {
            return redirect()->route('user.stores.trash')
                ->with('error', 'لا يمكنك استعادة المتجر لأنك وصلت للحد الأقصى المسموح به في خطتك (' . $allowed . ') متجر.');
        }

        $store->restore();

        return redirect()->route('user.stores.trash')
            ->with('success', 'تم استعادة المتجر بنجاح');
    }

    /**
     * حذف المتجر نهائياً من قاعدة البيانات
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function forceDelete($id)
    {
        $store = Store::onlyTrashed()
            ->where('user_id', auth()->id())
            ->findOrFail($id);

        $store->forceDelete();

        return redirect()->route('user.stores.trash')
            ->with('success', 'تم حذف المتجر نهائياً');
    }

    /**
     * صفحة مركز التقارير للمتجر
     */
    public function reportsIndex(Store $store)
    {
        $this->authorizeStoreAccess($store);

        return view('user.stores.reports.index', compact('store'));
    }


    /**
     * تقرير بحث شامل للمتجر يجمع المبيعات والاستهلاك الداخلي ومشتريات المالك.
     */
    public function reportsComprehensiveSearch(Store $store, Request $request)
    {
        $this->authorizeStoreAccess($store);

        $validated = $request->validate([
            'q' => 'nullable|string|max:255',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'scope' => 'nullable|in:all,sales,internal,purchases',
        ]);

        $reportData = app(ComprehensiveStoreSearchReportService::class)->build($store, $validated);

        return view('user.stores.reports.comprehensive-search', $reportData);
    }

    /**
     * تقارير مبيعات آخر 10 أيام (ملفات PDF المولدة للإقفال)
     */
    public function reportsLastTenDays(Store $store)
    {
        $this->authorizeStoreAccess($store);

        $reportFilesData = app(RecentReportFilesService::class)->recentForStore($store, 10);

        return view('user.stores.reports.last-ten-days', array_merge(['store' => $store], $reportFilesData));
    }

    /**
     * التقرير الشهري للمتجر (واجهة)
     */
    public function reportsMonthly(Store $store, Request $request)
    {
        $this->authorizeStoreAccess($store);

        $month = $request->get('month', now()->format('Y-m'));
        $start = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $data = app(MonthlyStoreReportService::class)->buildMonthlyReportData($store, $month, $start, $end, false);

        return view('user.stores.reports.monthly', $data);
    }

    /**
     * تصدير PDF للتقرير الشهري
     */
    public function reportsMonthlyPdf(Store $store, Request $request)
    {
        $this->authorizeStoreAccess($store);

        $month = $request->get('month', now()->format('Y-m'));
        $start = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $includeSalesDetails = $request->boolean('include_sales_details');
        $data = app(MonthlyStoreReportService::class)->buildMonthlyReportData($store, $month, $start, $end, $includeSalesDetails);
        $data['includeSalesDetails'] = $includeSalesDetails;

        $reportTitle = app(MonthlyStoreReportService::class)->buildMonthlyReportTitle($store->name, $month, $includeSalesDetails);
        $data['reportTitle'] = $reportTitle;

        $pdf = PDF::loadView('pdf.store-monthly-report', $data)
            ->setOption('encoding', 'utf-8');

        return $pdf->download(app(MonthlyStoreReportService::class)->buildSafeReportFileName($reportTitle, $store->id));
    }

    /**
     * =================================================================
     * دوال مساعدة و API
     * =================================================================
     */

    /**
     * واجهة توافقية داخلية للتحقق من أن المتجر المطلوب تابع للمالك الحالي.
     *
     * مكانها الأساسي الآن: StoreAccessService::ensureOwnerCanAccess.
     * خطة الحذف: عند نقل صلاحيات المتاجر إلى Policy/StoreAccessService في كل الكنترولرات،
     * تُحذف هذه الدالة وتستدعى الخدمة أو الـ Policy مباشرة من نقاط الدخول الجديدة.
     */
    private function authorizeStoreAccess(Store $store)
    {
        $this->storeAccessService()->ensureOwnerCanAccess(auth()->user(), $store);
    }

    /**
     * نقطة وصول موحدة لخدمة صلاحيات واستخدام المتاجر حتى لا تتكرر شروط الملكية والحالة داخل الكنترولر.
     */
    private function storeAccessService(): StoreAccessService
    {
        return app(StoreAccessService::class);
    }

    /**
     * الحصول على إحصائيات متقدمة للمتجر (API)
     *
     * @param Store $store
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAdvancedStats(Store $store)
    {
        $this->authorizeStoreAccess($store);

        return response()->json(app(StoreDashboardService::class)->advancedStats($store));
    }
}
