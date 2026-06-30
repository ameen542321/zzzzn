<?php

namespace App\Http\Controllers\Accountant;

use Carbon\Carbon;
use App\Models\Log;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Expense;
use App\Models\Accountant;
use App\Models\Withdrawal;
use App\Models\DailyBalance;
use App\Models\Notification;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use App\Support\ArabicPdf as PDF;
use App\Support\PaymentTypeLabel;
use App\Services\ShiftLifecycleService;
use App\Services\Accounting\AccountingOperationFeedService;
use App\Services\Shifts\ShiftGapInfoService;

class DashboardController extends Controller
{
    public function index()
    {
        $accountant = auth('accountant')->user();
        $storeId = $accountant->store_id;
        $pendingIncomingTransfersCount = \App\Models\StoreTransfer::where('receiver_store_id', $storeId)->where('status', 'pending')->count();
        $pendingOutgoingTransfersCount = \App\Models\StoreTransfer::where('sender_store_id', $storeId)->where('status', 'pending')->count();
        $lastBalance = null;

        try {
            // 1. البحث عن آخر إقفال يدوي مسجل
            $lastBalance = DailyBalance::where('store_id', $storeId)
                ->with(['accountant'])
                ->latest()
                ->first();

            if ($lastBalance) {
                $startTime = $lastBalance->end_time ?? $lastBalance->created_at;
                $lastBalanceTime = $lastBalance->end_time
                    ? $lastBalance->end_time->format('Y-m-d h:i A')
                    : $lastBalance->created_at->format('Y-m-d h:i A');
                $lastBalanceAmount = $lastBalance->system_sales_total;
                $lastBalanceAccountant = optional($lastBalance->accountant)->name ?? 'غير معروف';
            } else {
                $startTime = Carbon::parse('2024-01-01');
                $lastBalanceTime = 'بانتظار أول إقفال';
                $lastBalanceAmount = 0;
                $lastBalanceAccountant = '--';
            }

            $shiftLifecycleContext = app(ShiftLifecycleService::class)->currentShiftContext($accountant->store ?: $storeId, now(), true);
            $currentBusinessDate = $shiftLifecycleContext['business_date'];
            $currentShiftNumber = $shiftLifecycleContext['shift_number'];
            $requiresSecondShiftConfirmation = $shiftLifecycleContext['requires_second_shift_confirmation'];
            $canChooseNextShiftBusinessDate = $shiftLifecycleContext['can_choose_next_shift_business_date'];
            $nextBusinessDateAfterCurrent = Carbon::parse($currentBusinessDate)->addDay()->toDateString();
            $missingBusinessDates = $shiftLifecycleContext['missing_business_dates'];
            $this->ensurePreviousDayShiftRequest($storeId, (int) $accountant->id, (string) $accountant->name, $missingBusinessDates);
            $pendingShiftGapRequests = $this->pendingShiftGapRequests($storeId, $missingBusinessDates, (int) $accountant->id);
            $activeShiftGapBusinessDate = session('accountant_shift_gap_business_date');
            $activeBusinessDate = $activeShiftGapBusinessDate ?: $currentBusinessDate;

            $shiftDuration = $startTime->diffInHours(now());
            $shiftDurationText = $this->formatShiftDuration($shiftDuration);

            // تحسين: استعلام واحد لجمع إحصائيات المبيعات
            $salesStats = $this->getSalesStatistics($storeId, $startTime, $activeBusinessDate);

            $totalSinceBalance = $salesStats['total_sales'];
            $totalCost = (float) ($salesStats['total_cost'] ?? 0);
            $cashSales = $salesStats['cash_sales'];
            $mixedSales = (float) ($salesStats['mixed_sales'] ?? 0);
            $cardSales = $salesStats['card_sales'];
            $officialCreditSales = $salesStats['official_credit_sales'];
            $paymentGaps = $salesStats['payment_gaps'];
            $pendingCreditTotal = $officialCreditSales + $paymentGaps;

            $currentShiftExpenses = Expense::where('store_id', $storeId)
                ->forOpenAccountingShift($activeBusinessDate, $startTime)
                ->sum('amount');

            $currentShiftWithdrawals = Withdrawal::where('store_id', $storeId)
                ->forOpenAccountingShift($activeBusinessDate, $startTime)
                ->sum('amount');

            $cashFromCollectionsResult = $this->getCreditCollections($storeId, $startTime, now());
            $cashFromCollections = $cashFromCollectionsResult['total'] ?? 0;
            $collectedFromCurrentPeriod = $cashFromCollectionsResult['from_current_period'] ?? 0;
            $collectedFromOldPeriod = $cashFromCollectionsResult['from_old_period'] ?? 0;

            // ✅ التصحيح: جميع التحصيلات تضاف للكاش
            $cashInSafe = ($cashSales + $cashFromCollections) - ($currentShiftExpenses + $currentShiftWithdrawals);
            $totalCashInShift = $cashSales + $cashFromCollections;

            // إحصائيات الشهر
            $startOfMonth = $activeBusinessDate
                ? Carbon::parse($activeBusinessDate)->startOfMonth()
                : now()->startOfMonth();
            $stats = [
                'monthly_withdrawals' => Withdrawal::where('store_id', $storeId)
                    ->when($activeBusinessDate,
                        fn ($query) => $query->whereBetween('business_date', [$startOfMonth->toDateString(), $startOfMonth->copy()->endOfMonth()->toDateString()]),
                        fn ($query) => $query->where('created_at', '>=', $startOfMonth)
                    )
                    ->sum('amount'),
                'monthly_expenses' => Expense::where('store_id', $storeId)
                    ->when($activeBusinessDate,
                        fn ($query) => $query->whereBetween('business_date', [$startOfMonth->toDateString(), $startOfMonth->copy()->endOfMonth()->toDateString()]),
                        fn ($query) => $query->where('created_at', '>=', $startOfMonth)
                    )
                    ->sum('amount'),
            ];

            $workingDays = DailyBalance::where('store_id', $storeId)
                ->whereMonth('created_at', now()->month)
                ->count();
            $dailyAverage = $workingDays > 0 ? ($totalSinceBalance / $workingDays) : 0;

            $lowStockProducts = \App\Models\Product::where('store_id', $storeId)
                ->whereColumn('quantity', '<=', 'min_stock')
                ->take(5)
                ->get();
            $lowStockProductsCount = $lowStockProducts->count();

            $pendingCollections = DB::table('employee_credit_sales')
                ->where('store_id', $storeId)
                ->where('remaining_amount', '>', 0)
                ->where('status', 'pending')
                ->count();

            $lastOperations = app(AccountingOperationFeedService::class)->latestForStore(
                $storeId,
                10,
                max(1, (int) request('operations_page', 1)),
                request()->url(),
                request()->query()
            );

            $shiftStatus = ($shiftDuration > 15) ? 'warning' : 'normal';
            $shiftStatusClass = ($shiftDuration > 15) ? 'warning' : 'success';
            $shiftStatusMessage = ($shiftDuration > 15)
                ? 'لم يتم إغلاق الحسابات منذ فترة طويلة'
                : '';

            $salesEfficiency = $lastBalanceAmount > 0
                ? (($totalSinceBalance - $lastBalanceAmount) / $lastBalanceAmount) * 100
                : 0;

            $quickStats = $this->getDashboardQuickStats($storeId, $startTime, $activeBusinessDate);

            $pendingCreditCount = Sale::where('store_id', $storeId)
                ->forOpenAccountingShift($activeBusinessDate, $startTime)
                ->where('remaining_amount', '>', 0)
                ->count();

            // تحصيلات البيع الآجل
            $creditCollections = $this->getCreditCollections($storeId, $startTime, now());
            $shiftOperationDetails = app(AccountingOperationFeedService::class)->shiftDetails($storeId, $startTime, $creditCollections, $activeBusinessDate);

            // تنظيف المتغيرات الكبيرة بعد استخدامها
            unset($salesStats);

            return view('dashboard.accountant.index', compact(
                'totalSinceBalance', 'currentShiftExpenses', 'currentShiftWithdrawals', 'stats',
                'lastOperations', 'startTime', 'lastBalanceTime', 'lastBalanceAmount', 'lastBalanceAccountant',
                'shiftDuration', 'shiftDurationText', 'workingDays', 'dailyAverage', 'cashInSafe',
                'lowStockProducts', 'lowStockProductsCount', 'pendingCreditTotal', 'officialCreditSales',
                'paymentGaps', 'pendingCollections', 'shiftStatus', 'shiftStatusClass',
                'shiftStatusMessage', 'salesEfficiency', 'cashFromCollections', 'cashSales', 'mixedSales', 'totalCost',
                'cardSales', 'totalCashInShift', 'quickStats', 'pendingCreditCount', 'lastBalance', 'accountant',
                'creditCollections', 'collectedFromCurrentPeriod', 'collectedFromOldPeriod',
                'shiftOperationDetails', 'pendingIncomingTransfersCount', 'pendingOutgoingTransfersCount',
                'shiftLifecycleContext', 'currentBusinessDate', 'currentShiftNumber',
                'requiresSecondShiftConfirmation', 'canChooseNextShiftBusinessDate',
                'nextBusinessDateAfterCurrent', 'missingBusinessDates',
                'pendingShiftGapRequests', 'activeShiftGapBusinessDate'
            ));

        } catch (\Exception $e) {
            \Log::error('Dashboard error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return redirect()->back()->with('error', 'حدث خطأ في تحميل البيانات');
        }
    }


    public function activateShiftGap(Log $log)
    {
        $accountant = auth('accountant')->user();

        if ((int) $log->store_id !== (int) $accountant->store_id || $log->action !== 'shift_gap_accountant_request') {
            abort(403);
        }

        $assignedAccountantId = data_get($log->details, 'accountant_id');
        if ($assignedAccountantId && (int) $assignedAccountantId !== (int) $accountant->id) {
            abort(403);
        }

        $businessDate = data_get($log->details, 'business_date');
        if (! $businessDate) {
            return back()->with('error', 'طلب الشفت لا يحتوي تاريخًا صالحًا.');
        }

        $businessDate = Carbon::parse($businessDate)->toDateString();
        $missingDates = app(ShiftLifecycleService::class)->missingBusinessDates((int) $accountant->store_id);

        if (! in_array($businessDate, $missingDates, true)) {
            return back()->with('error', 'هذا اليوم لم يعد ضمن الشفتات الناقصة.');
        }

        session([
            'accountant_shift_gap_store_id' => (int) $accountant->store_id,
            'accountant_shift_gap_business_date' => $businessDate,
            'accountant_shift_gap_log_id' => (int) $log->id,
        ]);

        $details = is_array($log->details) ? $log->details : [];
        $details['status'] = 'in_progress';
        $details['accountant_id'] = (int) $accountant->id;
        $details['accountant_started_at'] = now()->toDateTimeString();
        $log->update(['details' => $details]);

        return redirect()->route('accountant.dashboard')
            ->with('success', 'تم تفعيل يوم '.$businessDate.' للإدخال المحاسبي. العمليات الجديدة ستسجل على هذا التاريخ حتى إنهاء وضع المعالجة.');
    }


    public function closeActiveShiftGap(Request $request)
    {
        $accountant = auth('accountant')->user();
        $store = $accountant->store;

        if (! $store) {
            return back()->with('error', 'المحاسب غير مرتبط بأي متجر.');
        }

        $businessDate = session('accountant_shift_gap_business_date');
        if (! $businessDate || (int) session('accountant_shift_gap_store_id') !== (int) $store->id) {
            return back()->with('error', 'لا يوجد يوم مرجع مفعل للإغلاق.');
        }

        $validated = $request->validate([
            'actual_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $businessDate = Carbon::parse($businessDate)->toDateString();

        if (DailyBalance::where('store_id', $store->id)
            ->whereDate('business_date', $businessDate)
            ->whereNotNull('end_time')
            ->exists()) {
            $this->clearShiftGapSession();

            return redirect()->route('accountant.dashboard')
                ->with('error', 'هذا اليوم لديه إغلاق شفت بالفعل. تم إنهاء وضع المعالجة.');
        }

        DB::beginTransaction();

        try {
            $salesQuery = Sale::query()
                ->where('store_id', $store->id)
                ->forOpenAccountingShift($businessDate)
                ->where(function ($query) {
                    $query->whereNull('description')
                        ->orWhere('description', '!=', 'manual_invoice_entry');
                });

            $gapSales = (clone $salesQuery)
                ->withCount('items')
                ->orderBy('created_at')
                ->get();

            $totalSales = (float) $gapSales->sum('paid_amount');
            $cashSales = (float) $gapSales->where('sale_type', 'cash')->sum('paid_amount');
            $mixedCash = (float) $gapSales->where('sale_type', 'mixed')->sum('cash_amount');
            $cardSales = (float) $gapSales->where('sale_type', 'card')->sum('paid_amount')
                + (float) $gapSales->where('sale_type', 'mixed')->sum('card_amount');
            $productsSalesValue = (float) $gapSales->sum('products_total');
            $laborTotal = (float) $gapSales->sum('labor_total');
            $legacyCostValue = (float) $gapSales->sum(fn (Sale $sale) => max((float) (($sale->products_total + $sale->labor_total) - $sale->profit), 0));
            $expenses = (float) Expense::where('store_id', $store->id)
                ->forOpenAccountingShift($businessDate)
                ->sum('amount');
            $withdrawals = (float) Withdrawal::where('store_id', $store->id)
                ->forOpenAccountingShift($businessDate)
                ->sum('amount');

            $expectedCash = max(0, $cashSales + $mixedCash - $expenses - $withdrawals);
            $actualCash = (float) $validated['actual_cash'];
            $difference = $actualCash - $expectedCash;
            $closedAt = now();
            $startTime = Carbon::parse($businessDate)->startOfDay();
            $notes = trim((string) ($validated['notes'] ?? '')) ?: 'إغلاق يوم مرجع من طلب المالك';

            $dailyBalance = DailyBalance::create([
                'store_id' => $store->id,
                'accountant_id' => $accountant->id,
                'system_sales_total' => $totalSales,
                'system_cash_expected' => $expectedCash,
                'actual_cash_submitted' => $actualCash,
                'difference' => $difference,
                'start_time' => $startTime,
                'end_time' => $closedAt,
                'business_date' => $businessDate,
                'closed_at' => $closedAt,
                'notes' => $notes,
            ]);

            $reportData = $this->buildShiftGapReportData(
                $businessDate,
                $startTime,
                $closedAt,
                $gapSales,
                $totalSales,
                $cashSales + $mixedCash,
                $cardSales,
                $productsSalesValue,
                $legacyCostValue,
                $laborTotal,
                $expenses,
                $withdrawals,
                $expectedCash,
                $actualCash,
                $difference,
                $notes
            );

            $this->attachOperationsToClosedShiftByBusinessDate($store->id, $dailyBalance, $businessDate);
            $this->markActiveShiftGapResolved($dailyBalance, $businessDate);
            $waUrl = $this->generateReportAndWhatsApp($store, $accountant, $reportData);
            $this->clearShiftGapSession();

            DB::commit();

            return redirect()->route('accountant.dashboard')->with([
                'success' => 'تم إصدار موازنة اليوم المرجع '.$businessDate.' وربط عملياته بالشفت.',
                'balance_id' => $dailyBalance->id,
                'wa_url' => $waUrl,
            ]);
        } catch (\Throwable $exception) {
            DB::rollBack();
            \Log::error('Failed to close active shift gap: '.$exception->getMessage(), [
                'store_id' => $store->id,
                'business_date' => $businessDate,
            ]);

            return back()->with('error', 'تعذر إغلاق اليوم المرجع: '.$exception->getMessage());
        }
    }

    public function clearShiftGap()
    {
        if ($logId = session('accountant_shift_gap_log_id')) {
            $log = Log::where('id', $logId)
                ->where('action', 'shift_gap_accountant_request')
                ->first();

            if ($log) {
                $details = is_array($log->details) ? $log->details : [];
                $details['status'] = 'pending';
                $details['accountant_paused_at'] = now()->toDateTimeString();
                $log->update(['details' => $details]);
            }
        }

        $this->clearShiftGapSession();

        return redirect()->route('accountant.dashboard')
            ->with('success', 'تم تأجيل معالجة اليوم المرجع والعودة للشفت الحالي. سيبقى الطلب ظاهرًا للمحاسب لاحقًا.');
    }

    private function ensurePreviousDayShiftRequest(int $storeId, int $accountantId, string $accountantName, array $missingBusinessDates): void
    {
        // طلب اليوم السابق ينشأ تلقائيًا للمحاسب حتى يظهر كطلب مراجعة بدون تدخل المالك عند بقاء شفت الأمس غير مغلق.
        $previousDate = now()->subDay()->toDateString();

        if (! in_array($previousDate, $missingBusinessDates, true)) {
            return;
        }

        $store = Store::find($storeId);
        if (! $store) {
            return;
        }

        $systemAccountantId = (int) Accountant::query()
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->orderBy('id')
            ->value('id');

        if ($systemAccountantId && $systemAccountantId !== $accountantId) {
            return;
        }

        $shiftInfo = app(ShiftGapInfoService::class)->shiftInfo($store, $previousDate);
        if ($shiftInfo['closed_shifts_count'] >= $shiftInfo['max_shifts']) {
            return;
        }

        $missingShiftNumber = (int) $shiftInfo['missing_shift_number'];
        $alreadyExists = Log::query()
            ->where('store_id', $storeId)
            ->where('action', 'shift_gap_accountant_request')
            ->latest()
            ->limit(30)
            ->get()
            ->contains(function (Log $log) use ($previousDate, $missingShiftNumber) {
                $businessDate = data_get($log->details, 'business_date');
                $status = data_get($log->details, 'status', 'pending');

                return $businessDate
                    && Carbon::parse($businessDate)->toDateString() === $previousDate
                    && (int) data_get($log->details, 'missing_shift_number', 1) === $missingShiftNumber
                    && in_array($status, ['pending', 'in_progress'], true);
            });

        if ($alreadyExists) {
            return;
        }

        $shiftLabel = 'الشفت ' . $missingShiftNumber . ' من ' . $shiftInfo['max_shifts'];

        Log::create([
            'store_id' => $storeId,
            'user_id' => $store->user_id,
            'action' => 'shift_gap_accountant_request',
            'description' => 'طلب نظامي لمراجعة ' . $shiftLabel . ' لليوم السابق بتاريخ ' . $previousDate,
            'model_type' => Store::class,
            'model_id' => $storeId,
            'details' => [
                'business_date' => $previousDate,
                'status' => 'pending',
                'requested_at' => now()->toDateTimeString(),
                'accountant_id' => $accountantId,
                'accountant_name' => $accountantName,
                'closed_shifts_count' => $shiftInfo['closed_shifts_count'],
                'missing_shift_number' => $missingShiftNumber,
                'max_shifts' => $shiftInfo['max_shifts'],
                'shift_label' => $shiftLabel,
                'shift_key' => $previousDate . '#' . $missingShiftNumber,
                'source' => 'system_previous_day',
            ],
        ]);
    }

    private function pendingShiftGapRequests(int $storeId, array $missingBusinessDates, int $accountantId)
    {
        if (empty($missingBusinessDates)) {
            return collect();
        }

        return Log::query()
            ->where('store_id', $storeId)
            ->where('action', 'shift_gap_accountant_request')
            ->latest()
            ->limit(30)
            ->get()
            ->filter(function (Log $log) use ($missingBusinessDates, $accountantId) {
                $assignedAccountantId = data_get($log->details, 'accountant_id');
                if ($assignedAccountantId && (int) $assignedAccountantId !== $accountantId) {
                    return false;
                }

                $businessDate = data_get($log->details, 'business_date');
                if (! $businessDate) {
                    return false;
                }

                $businessDate = Carbon::parse($businessDate)->toDateString();
                $status = data_get($log->details, 'status', 'pending');

                return in_array($businessDate, $missingBusinessDates, true)
                    && $status === 'pending';
            })
            ->unique(fn (Log $log) => Carbon::parse(data_get($log->details, 'business_date'))->toDateString().'#'.data_get($log->details, 'missing_shift_number', 1))
            ->values();
    }

    private function getSalesStatistics($storeId, $startTime, ?string $businessDate = null)
    {
        return Sale::where('store_id', $storeId)
            ->forOpenAccountingShift($businessDate, $startTime)
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->selectRaw('
                COALESCE(SUM(paid_amount), 0) as total_sales,
                COALESCE(SUM((products_total + labor_total) - profit), 0) as total_cost,

                -- المبالغ النقدية: من مبيعات كاش + الجزء النقدي من المختلط
                COALESCE(SUM(CASE WHEN sale_type = "cash" THEN paid_amount ELSE 0 END), 0) +
                COALESCE(SUM(CASE WHEN sale_type = "mixed" THEN cash_amount ELSE 0 END), 0) as cash_sales,

                -- مبالغ الشبكة: من مبيعات شبكة + الجزء الشبكي من المختلط
                COALESCE(SUM(CASE WHEN sale_type = "card" THEN paid_amount ELSE 0 END), 0) +
                COALESCE(SUM(CASE WHEN sale_type = "mixed" THEN card_amount ELSE 0 END), 0) as card_sales,

                COALESCE(SUM(CASE WHEN sale_type = "mixed" THEN paid_amount ELSE 0 END), 0) as mixed_sales,

                -- مدفوعات الآجل (ما تم دفعه من أصل آجل)
                COALESCE(SUM(CASE WHEN sale_type = "credit" THEN paid_amount ELSE 0 END), 0) as credit_payments,

                -- الآجل الرسمي (مع موظف)
                COALESCE(SUM(CASE
                    WHEN (sale_type = "credit" OR has_partial_credit = 1)
                    AND employee_id IS NOT NULL
                    AND remaining_amount > 0
                    THEN remaining_amount
                    ELSE 0
                END), 0) as official_credit_sales,

                -- فروقات الدفع (بدون موظف)
                COALESCE(SUM(CASE
                    WHEN (sale_type = "credit" OR has_partial_credit = 1)
                    AND employee_id IS NULL
                    AND remaining_amount > 0
                    THEN remaining_amount
                    ELSE 0
                END), 0) as payment_gaps
            ')
            ->first()
            ->toArray();
    }

    private function formatShiftDuration($hours)
    {
        if ($hours < 1) {
            $minutes = $hours * 60;
            return round($minutes) . ' دقيقة';
        } elseif ($hours < 24) {
            $hoursInt = floor($hours);
            $minutes = round(($hours - $hoursInt) * 60);

            if ($minutes > 0) {
                return $hoursInt . ' ساعة و ' . $minutes . ' دقيقة';
            }
            return $hoursInt . ' ساعة';
        } else {
            $days = floor($hours / 24);
            $remainingHours = floor($hours % 24);

            if ($remainingHours > 0) {
                return $days . ' يوم و ' . $remainingHours . ' ساعة';
            }
            return $days . ' يوم';
        }
    }

    private function getDashboardQuickStats($storeId, $startTime, ?string $businessDate = null)
    {
        $topEmployee = Sale::where('store_id', $storeId)
            ->forOpenAccountingShift($businessDate, $startTime)
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->whereNotNull('employee_id')
            ->select('employee_id', DB::raw('SUM(final_total) as total_sales'))
            ->groupBy('employee_id')
            ->orderBy('total_sales', 'desc')
            ->first();

        return [
            'avg_sale_amount' => Sale::where('store_id', $storeId)
                ->forOpenAccountingShift($businessDate, $startTime)
                ->where(function ($query) {
                    $query->whereNull('description')
                        ->orWhere('description', '!=', 'manual_invoice_entry');
                })
                ->avg('final_total') ?? 0,

            'invoice_count' => Sale::where('store_id', $storeId)
                ->forOpenAccountingShift($businessDate, $startTime)
                ->where(function ($query) {
                    $query->whereNull('description')
                        ->orWhere('description', '!=', 'manual_invoice_entry');
                })
                ->count(),

            'highest_sale' => Sale::where('store_id', $storeId)
                ->forOpenAccountingShift($businessDate, $startTime)
                ->where(function ($query) {
                    $query->whereNull('description')
                        ->orWhere('description', '!=', 'manual_invoice_entry');
                })
                ->max('final_total') ?? 0,

            'top_employee' => $topEmployee ? [
                'employee_id' => $topEmployee->employee_id,
                'total_sales' => $topEmployee->total_sales
            ] : null,
        ];
    }

    public function viewReport($filename)
    {
        // البحث في المسار الموحد للتخزين
        $path = storage_path('app/public/reports/' . $filename);

        if (!file_exists($path)) {
            \Log::error("التقرير غير موجود في المسار: " . $path);
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"'
        ]);
    }

    private function sendReportToOwner($phone, $fileName)
    {
        // الرابط المباشر للملف (يجب أن يكون موقعك مرفوعاً على سيرفر حقيقي ليعمل)
        $fileUrl = route('report.view', ['filename' => $fileName]);

        // إعدادات API الواتساب (مثال UltraMsg)
        $params = [
            'token' => 'YOUR_ULTRAMSG_TOKEN',
            'to'    => $phone, // رقم المالك
            'filename' => $fileName,
            'document' => $fileUrl,
            'caption'  => "تقرير إقفال المتجر ليوم " . now()->format('Y-m-d')
        ];

        // إرسال الطلب (Curl أو Guzzle)
        $curl = curl_init();
        curl_setopt_array($curl, [
          CURLOPT_URL => "https://api.ultramsg.com/YOUR_INSTANCE_ID/messages/document",
          CURLOPT_POST => true,
          CURLOPT_POSTFIELDS => http_build_query($params),
          CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
    }

    private function getCreditCollections($storeId, $startTime, $endTime)
    {
        try {
            $collections = DB::table('employee_credit_sales')
                ->where('store_id', $storeId)
                ->where('updated_at', '>=', $startTime)
                ->where('updated_at', '<=', $endTime)
                ->whereColumn('remaining_amount', '<', 'amount')
                ->select('id', 'person_id', 'amount', 'remaining_amount',
                         'partial_payments', 'status', 'updated_at', 'created_at', 'description')
                ->get();

            $totalCollected = 0;
            $collectedFromCurrentPeriod = 0;
            $collectedFromOldPeriod = 0;
            $collectionDetails = [];

            foreach ($collections as $collection) {
                $collectedInShift = $this->calculateCollectionInPeriod(
                    $collection,
                    $startTime,
                    $endTime
                );

                if ($collectedInShift > 0) {
                    $totalCollected += $collectedInShift;

                    // ⚠️ الافتراض: المديونيات التي أنشئت في نفس الشفت تعتبر "من هذا الشفت"
                    $isFromCurrentPeriod = $collection->created_at >= $startTime;

                    if ($isFromCurrentPeriod) {
                        $collectedFromCurrentPeriod += $collectedInShift;
                    } else {
                        $collectedFromOldPeriod += $collectedInShift;
                    }

                    $employeeName = $this->getEmployeeName($collection->person_id);

                    $collectionDetails[] = [
                        'id' => $collection->id,
                        'employee_id' => $collection->person_id,
                        'employee_name' => $employeeName,
                        'original_amount' => (float) $collection->amount,
                        'collected_in_shift' => $collectedInShift,
                        'remaining_amount' => (float) $collection->remaining_amount,
                        'is_full_payment' => $collection->remaining_amount == 0,
                        'is_partial_payment' => $collection->remaining_amount > 0,
                        'collection_date' => $collection->updated_at,
                        'credit_created_at' => $collection->created_at,
                        'is_from_current_period' => $isFromCurrentPeriod,
                        'type' => $isFromCurrentPeriod ? 'current' : 'old',
                        'description' => $collection->description,
                        'note' => $isFromCurrentPeriod
                            ? 'افتراض: مديونية من هذا الشفت'
                            : 'افتراض: مديونية قديمة',
                    ];
                }
            }

            return [
                'total' => $totalCollected,
                'from_current_period' => $collectedFromCurrentPeriod,
                'from_old_period' => $collectedFromOldPeriod,
                'details' => $collectionDetails,
                'count' => count($collectionDetails),
                'warning' => !empty($collectionDetails) ? 'يتم الاعتماد على تاريخ إنشاء المديونية فقط' : null,
            ];

        } catch (\Exception $e) {
            \Log::error('Error getting credit collections: ' . $e->getMessage());
            return [
                'total' => 0,
                'from_current_period' => 0,
                'from_old_period' => 0,
                'details' => [],
                'count' => 0,
            ];
        }
    }

    private function calculateCollectionInPeriod($collection, $startTime, $endTime)
    {
        $collectedAmount = 0;

        try {
            if ($collection->partial_payments && $collection->partial_payments != '[]' && $collection->partial_payments != 'null') {
                $payments = json_decode($collection->partial_payments, true);

                if (is_array($payments)) {
                    foreach ($payments as $payment) {
                        $paymentDate = isset($payment['date']) ? Carbon::parse($payment['date']) : null;
                        if ($paymentDate && $paymentDate >= $startTime && $paymentDate <= $endTime) {
                            $collectedAmount += $payment['amount'] ?? 0;
                        }
                    }
                }
            } else {
                if ($collection->updated_at >= $startTime && $collection->updated_at <= $endTime) {
                    $collectedAmount = (float) $collection->amount - (float) $collection->remaining_amount;
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error calculating collection: ' . $e->getMessage());
        }

        return $collectedAmount;
    }

    private function getEmployeeName($personId)
    {
        try {
            $employee = DB::table('employees')
                ->where('id', $personId)
                ->select('name')
                ->first();

            return $employee ? $employee->name : 'موظف #' . $personId;
        } catch (\Exception $e) {
            \Log::error('Error getting employee name: ' . $e->getMessage());
            return 'غير معروف';
        }
    }

  public function storeBalance(Request $request)
{

    $validator = Validator::make($request->all(), [
        'actual_cash' => 'required|numeric|min:0',
        'notes' => 'nullable|string|max:500',
        'next_shift_decision' => 'nullable|in:same_business_date,next_business_date'
    ]);

    if ($validator->fails()) {
        return redirect()->back()
            ->withErrors($validator)
            ->withInput();
    }

    if (session('accountant_shift_gap_business_date')) {
        return $this->closeActiveShiftGap($request);
    }

    DB::beginTransaction();

    try {
        $accountant = auth('accountant')->user();
        $store = $accountant->store;

        if (!$store) {
            throw new \Exception('المحاسب غير مرتبط بأي متجر');
        }

        // ✅ تحقق من وجود إقفال حديث
        $recentBalance = DailyBalance::where('store_id', $store->id)
            ->where('created_at', '>', now()->subMinutes(1))
            ->first();

        if ($recentBalance) {
            throw new \Exception('تم إصدار الموازنة مؤخراً. الرجاء الانتظار قليلاً.');
        }

        $endTime = now();
        $shiftContext = app(ShiftLifecycleService::class)->currentShiftContext($store, $endTime);
        $startTime = $shiftContext['shift_start'];
        $businessDate = $shiftContext['business_date'];
        $canChooseNextShiftBusinessDate = (bool) ($shiftContext['can_choose_next_shift_business_date'] ?? false);
        $nextShiftDecision = $canChooseNextShiftBusinessDate
            ? $request->input('next_shift_decision', 'same_business_date')
            : null;

        if ($canChooseNextShiftBusinessDate && ! in_array($nextShiftDecision, ['same_business_date', 'next_business_date'], true)) {
            throw new \Exception('قرار الشفت التالي غير صالح.');
        }

        $nextShiftBusinessDate = match ($nextShiftDecision) {
            'same_business_date' => $businessDate,
            'next_business_date' => Carbon::parse($businessDate)->addDay()->toDateString(),
            default => null,
        };

        \Log::info('Balance closure started', [
            'store_id' => $store->id,
            'accountant_id' => $accountant->id,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'actual_cash' => $request->actual_cash
        ]);

        $salesSummary = Sale::where('store_id', $store->id)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->selectRaw('
                COALESCE(SUM(CASE WHEN (description IS NULL OR description != "manual_invoice_entry") THEN paid_amount ELSE 0 END), 0) as total_sales,
                COALESCE(SUM(CASE WHEN (description IS NULL OR description != "manual_invoice_entry") THEN (products_total + labor_total) - profit ELSE 0 END), 0) as total_cost,
                COALESCE(SUM(CASE WHEN sale_type = "cash" THEN paid_amount ELSE 0 END), 0) +
                COALESCE(SUM(CASE WHEN sale_type = "mixed" THEN cash_amount ELSE 0 END), 0) as cash_sales,
                COALESCE(SUM(CASE WHEN sale_type = "card" THEN paid_amount ELSE 0 END), 0) +
                COALESCE(SUM(CASE WHEN sale_type = "mixed" THEN card_amount ELSE 0 END), 0) as card_sales,
                COALESCE(SUM(CASE WHEN sale_type = "credit" THEN paid_amount ELSE 0 END), 0) as credit_payments,
                COALESCE(SUM(CASE WHEN sale_type = "credit" THEN final_total ELSE 0 END), 0) as credit_sales,
                COALESCE(SUM(CASE WHEN sale_type = "internal_use" THEN final_total ELSE 0 END), 0) as internal_use_sales,
                COALESCE(SUM(CASE WHEN (sale_type = "credit" OR has_partial_credit = 1) AND employee_id IS NOT NULL AND remaining_amount > 0 THEN remaining_amount ELSE 0 END), 0) as official_credit_sales,
                COALESCE(SUM(CASE WHEN (sale_type = "credit" OR has_partial_credit = 1) AND employee_id IS NULL AND remaining_amount > 0 THEN remaining_amount ELSE 0 END), 0) as payment_gaps,
                COALESCE(SUM(CASE WHEN (description IS NULL OR description != "manual_invoice_entry") THEN labor_total ELSE 0 END), 0) as total_labor
            ')
            ->first();

        \Log::info('Sales summary calculated', ['total_sales' => $salesSummary->total_sales]);

        $totalSales = $salesSummary->total_sales ?? 0;
        $cashSales = $salesSummary->cash_sales ?? 0;
        $cardSales = $salesSummary->card_sales ?? 0;
        $creditSales = $salesSummary->credit_sales ?? 0;
        $officialCreditSales = $salesSummary->official_credit_sales ?? 0;
        $paymentGaps = $salesSummary->payment_gaps ?? 0;
        $laborTotal = $salesSummary->total_labor ?? 0;
        $internalUseSales = $salesSummary->internal_use_sales ?? 0;

        $creditCollections = $this->getCreditCollections($store->id, $startTime, $endTime);
        $cashFromCollections = $creditCollections['total'];
        $collectedFromCurrentPeriod = $creditCollections['from_current_period'];
        $collectedFromOldPeriod = $creditCollections['from_old_period'];

        $totalCashInShift = $cashSales + $cashFromCollections;

        $productsStats = $this->calculateProductsProfit($store->id, $startTime, $endTime);
        $productsProfit = $productsStats['profit'];
        $totalProductsSalesValue = $productsStats['sales_value'];
        $totalProductsCostValue = $productsStats['cost_value'];

        $netProfit = ($totalSales - $totalProductsCostValue) + $collectedFromCurrentPeriod;

        $expenses = Expense::where('store_id', $store->id)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->sum('amount') ?? 0;

        $withdrawals = Withdrawal::where('store_id', $store->id)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->sum('amount') ?? 0;

        $totalOutgoing = $expenses + $withdrawals;
        $remainingBalance = $netProfit - $totalOutgoing;
        $expectedCashInHand = $totalCashInShift - $totalOutgoing;
        $actualCash = (float) $request->actual_cash;
        $cashDifference = $actualCash - $expectedCashInHand;

        \Log::info('Cash calculation', [
            'expected' => $expectedCashInHand,
            'actual' => $actualCash,
            'difference' => $cashDifference
        ]);



        $detailedSales = Sale::where('store_id', $store->id)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->with(['employee', 'accountant', 'items.product'])
            // نرتب العمليات داخل التقرير حسب وقتها ثم رقمها، ثم نستعمل رقمًا تسلسليًا خاصًا بالملف
            // بدل رقم قاعدة البيانات حتى يكون ترقيم PDF واضحًا ومتتابعًا للقارئ.
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()->values()->map(function($s, $index) {
                 $productsList = [];
        foreach ($s->items as $item) {
            $productsList[] = [
                'name' => $item->product->name ?? 'منتج',
                'quantity' => $item->quantity,
                'price' => $item->price,
                'total' => $item->total
            ];
        }
                return [
                    'id' => $index + 1,
                    'database_id' => $s->id,
                    // قيمة عرض داخل التقرير فقط، ولا تعتمد على أي عمود إضافي في قاعدة البيانات.
                    'operation_name' => (mb_stripos((string) $s->description, 'تضليل') !== false
                        || mb_stripos((string) $s->description, 'تظليل') !== false)
                            ? $s->description
                            : null,
                    'time' => $s->created_at->format('h:i A'),
                    'type' => $s->sale_type,
                    'received' => $s->paid_amount,
                    'total' => $s->final_total,
                    'labor_total' => $s->labor_total,
                    'labor_desc' => $s->description,
                    // نحتفظ بنفس معادلة التكلفة القديمة، ونمنع العرض السالب فقط لعمليات شغل اليد أو المنتجات بلا تكلفة.
                    'cost' => max(0, $s->products_total - $s->profit),
                    'profit' => $s->profit,
                    'employee' => $s->employee->name ?? '',
                    'accountant' => $s->accountant->name ?? '---',
                      'products' => $productsList, // إضافة المنتجات
            'products_count' => count($productsList) // عدد المنتجات
                ];
            });

        $detailedExpenses = Expense::where('store_id', $store->id)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->get()->map(function($e) {
                return [
                    'time' => $e->created_at->format('h:i A'),
                    // نعتمد على الحقول الفعلية في جدول المصروفات (type / description)
                    'category' => $e->type ?? 'مصروف عام',
                    'reason' => $e->description ?? '—',
                    'amount' => $e->amount
                ];
            });

        $detailedWithdrawals = Withdrawal::where('store_id', $store->id)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->get()->map(function($w) {
                return [
                    'time' => $w->created_at->format('h:i A'),
                    'reason' => $w->reason ?? 'سحب نقدي',
                    'amount' => $w->amount
                ];
            });

        $reportData = [
            'store_id' => $store->id,
            'store_name' => $store->name,
            'accountant_id' => $accountant->id,
            'accountant_name' => $accountant->name,
            'business_date' => $businessDate,
            'start_time' => $startTime->format('Y-m-d H:i'),
            'end_time' => $endTime->format('Y-m-d H:i'),
            'report_date' => now()->format('Y-m-d H:i'),

            'total_sales' => $totalSales,
            'sales_breakdown' => [
                'cash_from_new_sales' => $cashSales,
                'card_from_new_sales' => $cardSales,
                'credit_sales' => $creditSales,
                'official_credit' => $officialCreditSales,
                'payment_gaps' => $paymentGaps,
                'internal_use' => $internalUseSales,
            ],

            'details_tables' => [
                'all_sales' => $detailedSales,
                'withdrawals_list' => $detailedWithdrawals,
                'expenses_list' => $detailedExpenses,
                'collections' => $creditCollections['details'] ?? [],
            ],

            'credit_collections' => [
                'total' => $creditCollections['total'],
                'from_current_period' => $collectedFromCurrentPeriod,
                'from_old_period' => $collectedFromOldPeriod,
                'details' => $creditCollections['details'],
                'count' => $creditCollections['count'],
            ],

            'products_details' => [
                'sales_value' => $totalProductsSalesValue,
                'cost_value' => $totalProductsCostValue,
                'profit' => $productsProfit,
            ],
            'labor_total' => $laborTotal,
            'net_profit' => $netProfit,

            'outgoing_today' => [
                'expenses' => $expenses,
                'withdrawals' => $withdrawals,
                'total' => $totalOutgoing,
            ],

            'remaining_balance' => $remainingBalance,

            'cash_details' => [
                'cash_from_new_sales' => $cashSales,
                'cash_from_current_collections' => $collectedFromCurrentPeriod,
                'cash_from_old_collections' => $collectedFromOldPeriod,
                'total_cash_collections' => $cashFromCollections,
                'total_cash_in_shift' => $totalCashInShift,
                'expected' => $expectedCashInHand,
                'actual' => $actualCash,
                'difference' => $cashDifference,
            ],

            'notes' => $request->notes,
        ];

        // \Log::info('Creating DailyBalance record...');

        $dailyBalance = DailyBalance::create([
            'store_id' => $store->id,
            'accountant_id' => $accountant->id,
            'system_sales_total' => $totalSales,
            'system_cash_expected' => $expectedCashInHand,
            'actual_cash_submitted' => $actualCash,
            'difference' => $cashDifference,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'business_date' => $businessDate,
            'closed_at' => $endTime,
            'next_shift_business_date' => $nextShiftBusinessDate,
            'next_shift_decision' => $nextShiftDecision,
            'next_shift_decided_by' => $nextShiftDecision ? $accountant->id : null,
            'notes' => $request->notes,
        ]);

        $this->attachOperationsToClosedShift($store->id, $dailyBalance, $startTime, $endTime, $businessDate);

        // \Log::info('DailyBalance created with ID: ' . $dailyBalance->id);

        // Log::create([
        //     'store_id' => $store->id,
        //     'user_id' => null,
        //     'actor_type' => 'accountant',
        //     'actor_id' => $accountant->id,
        //     'model_type' => 'DailyBalance',
        //     'model_id' => $dailyBalance->id,
        //     'action' => 'balance_done',
        //     'description' => 'تم إصدار الموازنة اليومية',
        //     'details' => json_encode($reportData, JSON_UNESCAPED_UNICODE),
        //     'ip' => $request->ip(),
        //     'user_agent' => $request->userAgent(),
        // ]);

        $waUrl = $this->generateReportAndWhatsApp($store, $accountant, $reportData);

        DB::commit();

        Cache::forget('shift_sales_' . $store->id . '_' . $startTime->timestamp);
        Cache::forget('shift_expenses_' . $store->id . '_' . $startTime->timestamp);
        Cache::forget('shift_withdrawals_' . $store->id . '_' . $startTime->timestamp);

        \Log::info('✅ Balance closed successfully', ['balance_id' => $dailyBalance->id]);

        return redirect()->route('accountant.dashboard')->with([
            'success' => 'تم اصدار الموازنة بنجاح',
            'balance_id' => $dailyBalance->id,
            'wa_url' => $waUrl
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('❌ Balance closure failed: ' . $e->getMessage());
        \Log::error('Stack trace: ' . $e->getTraceAsString());
        return redirect()->back()->with('error', 'فشل إصدار الموازنة: ' . $e->getMessage())->withInput();
    }
}



    private function buildShiftGapReportData(
        string $businessDate,
        Carbon $startTime,
        Carbon $closedAt,
        $gapSales,
        float $totalSales,
        float $cashSales,
        float $cardSales,
        float $productsSalesValue,
        float $productsCostValue,
        float $laborTotal,
        float $expenses,
        float $withdrawals,
        float $expectedCash,
        float $actualCash,
        float $difference,
        string $notes
    ): array {
        $salesRows = $gapSales->map(function (Sale $sale) {
            return [
                'id' => $sale->id,
                'time' => optional($sale->created_at)->format('h:i A'),
                'type' => $sale->sale_type,
                'products_count' => (int) ($sale->items_count ?? 0),
                'labor_total' => (float) $sale->labor_total,
                'total' => (float) $sale->final_total,
                'received' => (float) $sale->paid_amount,
            ];
        })->values()->all();

        $outgoingTotal = $expenses + $withdrawals;
        $productsProfit = max(0, $productsSalesValue - $productsCostValue);

        return [
            'start_time' => $startTime->format('Y-m-d h:i A'),
            'end_time' => $closedAt->format('Y-m-d h:i A'),
            'hide_period' => true,
            'business_date' => $businessDate,
            'report_date' => $closedAt->format('Y-m-d H:i'),
            'total_sales' => $totalSales,
            'sales_breakdown' => [
                'cash_from_new_sales' => $cashSales,
                'card_from_new_sales' => $cardSales,
            ],
            'credit_collections' => [
                'count' => 0,
                'total' => 0,
            ],
            'details_tables' => [
                'all_sales' => $salesRows,
            ],
            'outgoing_today' => [
                'expenses' => $expenses,
                'withdrawals' => $withdrawals,
                'total' => $outgoingTotal,
            ],
            'products_details' => [
                'sales_value' => $productsSalesValue,
                'cost_value' => $productsCostValue,
                'profit' => $productsProfit,
            ],
            'labor_total' => $laborTotal,
            'net_profit' => $totalSales - $productsCostValue - $outgoingTotal,
            'cash_details' => [
                'expected' => $expectedCash,
                'actual' => $actualCash,
                'difference' => $difference,
            ],
            'notes' => $notes,
        ];
    }

    private function clearShiftGapSession(): void
    {
        session()->forget([
            'accountant_shift_gap_store_id',
            'accountant_shift_gap_business_date',
            'accountant_shift_gap_log_id',
        ]);
    }

    private function markActiveShiftGapResolved(DailyBalance $dailyBalance, string $businessDate): void
    {
        $logId = session('accountant_shift_gap_log_id');

        if (! $logId) {
            return;
        }

        $log = Log::where('id', $logId)
            ->where('store_id', $dailyBalance->store_id)
            ->where('action', 'shift_gap_accountant_request')
            ->first();

        if (! $log) {
            return;
        }

        $details = is_array($log->details) ? $log->details : [];
        $details['status'] = 'resolved';
        $details['resolved_at'] = now()->toDateTimeString();
        $details['daily_balance_id'] = (int) $dailyBalance->id;
        $details['business_date'] = $businessDate;

        $log->update(['details' => $details]);
    }

    private function attachOperationsToClosedShiftByBusinessDate(int $storeId, DailyBalance $dailyBalance, string $businessDate): void
    {
        $updates = [
            'daily_balance_id' => $dailyBalance->id,
        ];

        Sale::where('store_id', $storeId)
            ->whereDate('business_date', $businessDate)
            ->whereNull('daily_balance_id')
            ->update($updates);

        Expense::where('store_id', $storeId)
            ->whereDate('business_date', $businessDate)
            ->whereNull('daily_balance_id')
            ->update($updates);

        Withdrawal::where('store_id', $storeId)
            ->whereDate('business_date', $businessDate)
            ->whereNull('daily_balance_id')
            ->update($updates);
    }

    private function attachOperationsToClosedShift($storeId, DailyBalance $dailyBalance, $startTime, $endTime, string $businessDate): void
    {
        $payload = [
            'business_date' => $businessDate,
            'daily_balance_id' => $dailyBalance->id,
        ];

        Sale::where('store_id', $storeId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->update($payload);

        Expense::where('store_id', $storeId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->update($payload);

        Withdrawal::where('store_id', $storeId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->update($payload);
    }

    private function calculateProductsProfit($storeId, $startTime, $endTime)
    {
        $summary = Sale::where('store_id', $storeId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->selectRaw('COALESCE(SUM(products_total), 0) as sales_value')
            // الربح يُحسب ويحفظ وقت البيع من تكلفة كل سطر. لذلك نستخرج التكلفة
            // من سجل العملية نفسه ولا نعيد حساب الرولات من جدول المنتجات الحالي.
            ->selectRaw('COALESCE(SUM((products_total + labor_total) - profit), 0) as cost_value')
            ->first();

        $totalSalesValue = (float) ($summary->sales_value ?? 0);
        $totalCostValue = max(0, (float) ($summary->cost_value ?? 0));

        return [
            'sales_value' => $totalSalesValue,
            'cost_value' => $totalCostValue,
            'profit' => $totalSalesValue - $totalCostValue,
        ];
    }
    private function generateReportAndWhatsApp($store, $accountant, $reportData)
    {
        // 1. التحقق من الحد اليومي
        $cacheKey = 'whatsapp_messages_' . $store->id . '_' . now()->format('Ymd');
        $todayMessages = Cache::get($cacheKey, 0);

        if ($todayMessages >= 10) {
            \Log::warning('WhatsApp rate limit exceeded for store: ' . $store->id);
            return null;
        }

        // تنظيف التقارير القديمة مرة واحدة يومياً لمنع تراكم ملفات PDF.
        // نستخدم Key عام لليوم حتى لا يتكرر التنظيف مع كل عملية إقفال.
        $cleanupKey = 'reports_cleanup_' . now()->format('Ymd');
        if (!Cache::has($cleanupKey)) {
            $this->cleanupOldReports();
            Cache::put($cleanupKey, true, now()->endOfDay());
        }

        // 2. تجهيز رقم الهاتف
        $storeOwner = $store->user;
        $managerPhone = $storeOwner->phone ?? $store->phone ?? null;

        if (!$managerPhone) {
            \Log::warning('No phone number found for store: ' . $store->id);
            return null;
        }

        $cleanPhone = preg_replace('/[^0-9]/', '', $managerPhone);
        if (!str_starts_with($cleanPhone, '966')) {
            $cleanPhone = '966' . ltrim($cleanPhone, '0');
        }

        // 3. ✅ إنشاء PDF
        $reportTitle = $this->buildShiftReportTitle($store->name, $reportData['notes'] ?? null);
        $safeReportTitle = preg_replace('/[^\p{Arabic}\p{L}\p{N}\-_ ]+/u', '', $reportTitle) ?: 'تقرير اغلاق متجر';
        $safeReportTitle = str_replace(' ', '_', trim(preg_replace('/\s+/u', ' ', $safeReportTitle)));
        $fileName = 'Report_' . $safeReportTitle . '_' . time() . '_' . $store->id . '.pdf';
        $filePath = public_path('reports/' . $fileName);

        try {
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0755, true);
            }

            $pdfData = [
                'store' => $store,
                'accountant' => $accountant,
                'data' => $reportData,
                'report_title' => $reportTitle,
            ];

            PDF::loadView('pdf.pdf_report', $pdfData)
               ->setOption('encoding', 'utf-8')
               ->setOption('enable-local-file-access', true)
               ->save($filePath);

            \Log::info('✅ PDF created successfully: ' . $fileName);

        } catch (\Exception $e) {
            \Log::error('❌ PDF creation failed: ' . $e->getMessage());
            $fileName = null;
        }

        $reportUrl = $fileName ? url('reports/' . $fileName) : 'غير متوفر';
        $message = $this->buildWhatsAppMessage($store, $accountant, $reportData, $reportUrl);

        $encodedMessage = rawurlencode($message);
        $waUrl = "https://wa.me/{$cleanPhone}?text={$encodedMessage}";

        Cache::put($cacheKey, $todayMessages + 1, now()->addDay());

        return $waUrl;
    }

    /**
     * يبني اسم تقرير الإغلاق حسب طلب المالك:
     * - إذا كانت الملاحظات تحتوي تاريخًا رقميًا مثل 15-6 أو 15/6 نستعمل هذا التاريخ.
     * - إذا كانت الملاحظات فارغة أو نصًا عاديًا بدون تاريخ رقمي نستعمل تاريخ اليوم.
     */
    private function buildShiftReportTitle(string $storeName, ?string $notes): string
    {
        $datePart = $this->extractReportDateFromNotes($notes) ?? now()->format('j-n');

        return "تقرير اغلاق متجر {$storeName} {$datePart}";
    }

    /**
     * استخراج أول تاريخ رقمي من حقل الملاحظات بدون الاعتماد على أي تغيير في قاعدة البيانات.
     */
    private function extractReportDateFromNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);
        if ($notes === '') {
            return null;
        }

        return preg_match('/[0-9٠-٩]{1,2}\s*[-\/]\s*[0-9٠-٩]{1,2}(?:\s*[-\/]\s*[0-9٠-٩]{2,4})?/u', $notes, $matches)
            ? preg_replace('/\s+/u', '', $matches[0])
            : null;
    }

    private function buildWhatsAppMessage($store, $accountant, $reportData, $reportUrl)
{
    $date = $reportData['business_date'] ?? now()->format('Y-m-d');
    $time = now()->format('h:i A');

    $cashSales = (float) ($reportData['sales_breakdown']['cash_from_new_sales'] ?? 0);
    $cardSales = (float) ($reportData['sales_breakdown']['card_from_new_sales'] ?? 0);
    $salesCount = isset($reportData['details_tables']['all_sales']) && is_countable($reportData['details_tables']['all_sales'])
        ? count($reportData['details_tables']['all_sales'])
        : 0;
    $collectionsCount = (int) ($reportData['credit_collections']['count'] ?? 0);
    $operationsCount = $salesCount + $collectionsCount;
    $totalSales = (float) ($reportData['total_sales'] ?? 0);
    $totalOutgoing = (float) ($reportData['outgoing_today']['total'] ?? 0);
    $productsSalesValue = (float) ($reportData['products_details']['sales_value'] ?? 0);
    $productsCostValue = (float) ($reportData['products_details']['cost_value'] ?? 0);

    $message = "📊 *تقرير إقفال المتجر*\n";
    $message .= "🏪 " . $store->name . "\n";
    $message .= "👤 " . $accountant->name . "\n";
    $message .= "📅 التاريخ المحاسبي: " . $date . " | إصدار: " . $time . "\n\n";

    $message .= "🧾 *ملخص الشفت :*\n";
    if (empty($reportData['hide_period'])) {
        $message .= "🕒 الفترة: " . ($reportData['start_time'] ?? '-') . " → " . ($reportData['end_time'] ?? '-') . "\n";
    }
    if (!empty($reportData['notes'])) {
        $message .= "📝 ملاحظة الإغلاق: " . $reportData['notes'] . "\n";
    }
    $message .= "💰 اجمالي العمليات: " . number_format($totalSales, 2) . " ريال\n";
    $message .= "🛒 قيمة المبيعات (بسعر البيع): " . number_format($productsSalesValue, 2) . " ريال\n";
    $message .= "📦 قيمة التكلفة: " . number_format($productsCostValue, 2) . " ريال\n";
    $message .= "💵 عمليات الكاش: " . number_format($cashSales, 2) . " ريال\n";
    $message .= "💳 عمليات الشبكة: " . number_format($cardSales, 2) . " ريال\n";
    $message .= "📤 مصاريف: " . number_format($totalOutgoing, 2) . " ريال\n";
    $message .= "🔢 عدد العمليات: " . number_format($operationsCount) . "\n\n";

    if (($reportData['labor_total'] ?? 0) > 0) {
        $message .= "👷 *أجرة اليد:* " . number_format((float) $reportData['labor_total'], 2) . " ريال\n\n";
    }

    $message .= "💵 *مطابقة الصندوق:*\n";
    $message .= "💰 الكاش المتوقع: " . number_format((float) ($reportData['cash_details']['expected'] ?? 0), 2) . " ريال\n";
    $message .= "💵 الكاش المستلم: " . number_format((float) ($reportData['cash_details']['actual'] ?? 0), 2) . " ريال\n";

    $diff = (float) ($reportData['cash_details']['difference'] ?? 0);
    if ($diff > 0) {
        $message .= "➕ فائض: " . number_format($diff, 2) . " ريال ✅\n";
    } elseif ($diff < 0) {
        $message .= "➖ عجز: " . number_format(abs($diff), 2) . " ريال ⚠️\n";
    } else {
        $message .= "✓ مطابق تماماً ✅\n";
    }

    if (!empty($reportData['notes'])) {
        $message .= "\n📝 *ملاحظات:*\n" . $reportData['notes'] . "\n";
    }

    $message .= "\n📄 *تقرير PDF:*\n";
    $message .= $reportUrl;

    return $message;
}

    private function createShiftHtmlReport($data)
    {
        $reportData = $data['data'];
        $salesRows = $reportData['details_tables']['all_sales'] ?? [];
        $salesRows = is_iterable($salesRows) ? $salesRows : [];

        $cashSales = (float) ($reportData['sales_breakdown']['cash_from_new_sales'] ?? 0);
        $cardSales = (float) ($reportData['sales_breakdown']['card_from_new_sales'] ?? 0);
        $productsSalesValue = (float) ($reportData['products_details']['sales_value'] ?? 0);
        $productsCostValue = (float) ($reportData['products_details']['cost_value'] ?? 0);
        $productsProfitValue = (float) ($reportData['products_details']['profit'] ?? 0);
        $collectionsCount = (int) ($reportData['credit_collections']['count'] ?? 0);
        $operationsCount = (is_countable($salesRows) ? count($salesRows) : 0) + $collectionsCount;
        $diff = (float) ($reportData['cash_details']['difference'] ?? 0);
        $diffClass = $diff >= 0 ? 'positive' : 'negative';
        $diffLabel = $diff > 0 ? 'فائض' : ($diff < 0 ? 'عجز' : 'مطابق');

        $typeMap = collect(['cash', 'card', 'mixed', 'credit', 'internal_use'])
            ->mapWithKeys(fn (string $type): array => [$type => PaymentTypeLabel::reportBadge($type)['label']])
            ->all();

        $html = '<!DOCTYPE html><html dir="rtl"><head><meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; direction: rtl; color: #1f2937; }
                h1 { color: #111827; text-align: center; margin-bottom: 12px; }
                h2 { color: #1f2937; margin: 18px 0 8px; }
                .header { background: #f8fafc; padding: 12px; border-radius: 8px; border-right: 4px solid #0ea5e9; margin-bottom: 16px; }
                .note { background: #fffbeb; border-right: 4px solid #f59e0b; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
                th, td { border: 1px solid #e5e7eb; padding: 8px; text-align: center; font-size: 13px; }
                th { background-color: #0f172a; color: #fff; }
                .total { font-weight: bold; background-color: #f3f4f6; }
                .negative { color: #dc2626; font-weight: bold; }
                .positive { color: #16a34a; font-weight: bold; }
                .muted { color: #6b7280; font-size: 12px; }
            </style></head><body>';

        $html .= '<h1>' . $data['report_title'] . '</h1>
            <div class="header">
                <p><strong>المتجر:</strong> ' . htmlspecialchars((string) ($data['store']->name ?? '-')) . ' | <strong>المحاسب:</strong> ' . htmlspecialchars((string) ($data['accountant']->name ?? '-')) . '</p>
                <p><strong>الفترة:</strong> ' . htmlspecialchars((string) ($reportData['start_time'] ?? '-')) . ' → ' . htmlspecialchars((string) ($reportData['end_time'] ?? '-')) . '</p>
            </div>';

        if (!empty($reportData['notes'])) {
            $html .= '<div class="note"><strong>ملاحظة الإغلاق:</strong> ' . nl2br(htmlspecialchars((string) $reportData['notes'])) . '</div>';
        }

        $html .= '<h2>📊 ملخص الشفت (مطابق صفحة المبيعات)</h2>
            <table>
                <tr><th>البند</th><th>القيمة</th></tr>
                <tr><td>إجمالي العمليات</td><td>' . number_format((float) ($reportData['total_sales'] ?? 0), 2) . ' ريال</td></tr>
                <tr><td>قيمة المبيعات (بسعر البيع)</td><td>' . number_format($productsSalesValue, 2) . ' ريال</td></tr>
                <tr><td>قيمة التكلفة</td><td>' . number_format($productsCostValue, 2) . ' ريال</td></tr>
                <tr><td>ربح المنتجات</td><td>' . number_format($productsProfitValue, 2) . ' ريال</td></tr>
                <tr><td>عمليات الكاش</td><td>' . number_format($cashSales, 2) . ' ريال</td></tr>
                <tr><td>عمليات الشبكة</td><td>' . number_format($cardSales, 2) . ' ريال</td></tr>
                <tr><td>المصاريف + السحوبات</td><td>' . number_format((float) ($reportData['outgoing_today']['total'] ?? 0), 2) . ' ريال</td></tr>
                <tr><td>عدد العمليات</td><td>' . number_format($operationsCount) . '</td></tr>
                <tr><td>أجرة اليد</td><td>' . number_format((float) ($reportData['labor_total'] ?? 0), 2) . ' ريال</td></tr>
                <tr class="total"><td>صافي الربح</td><td>' . number_format((float) ($reportData['net_profit'] ?? 0), 2) . ' ريال</td></tr>
            </table>';

        $html .= '<h2>🧾 تفاصيل العمليات</h2>
            <table>
                <tr>
                    <th>#</th>
                    <th>الوقت</th>
                    <th>نوع العملية</th>
                    <th>طريقة الدفع</th>
                    <th>القيمة</th>
                    <th>المستلم</th>
                </tr>';

        $index = 1;
        foreach ($salesRows as $row) {
            $row = (array) $row;
            $saleType = (string) ($row['type'] ?? '');
            $paymentLabel = $typeMap[$saleType] ?? $saleType;

            $productsCount = (int) ($row['products_count'] ?? 0);
            $laborTotal = (float) ($row['labor_total'] ?? 0);
            $operationKind = ($laborTotal > 0 && $productsCount === 0) ? 'شغل يد' : 'منتجات';

            $displayTotal = (float) ($row['total'] ?? 0);
            $received = (float) ($row['received'] ?? 0);
            $amountLabel = $displayTotal > 0 ? $displayTotal : $received;

            $html .= '<tr>
                <td>' . $index++ . '</td>
                <td>' . htmlspecialchars((string) ($row['time'] ?? '--')) . '</td>
                <td>' . htmlspecialchars($operationKind) . '</td>
                <td>' . htmlspecialchars($paymentLabel ?: '-') . '</td>
                <td>' . number_format($amountLabel, 2) . ' ريال</td>
                <td>' . number_format($received, 2) . ' ريال</td>
            </tr>';
        }

        if ($index === 1) {
            $html .= '<tr><td colspan="6" class="muted">لا توجد عمليات ضمن هذه الفترة.</td></tr>';
        }

        $html .= '</table>';

        $html .= '<h2>🏁 مطابقة الصندوق</h2>
            <table>
                <tr><td>الكاش المتوقع</td><td>' . number_format((float) ($reportData['cash_details']['expected'] ?? 0), 2) . ' ريال</td></tr>
                <tr><td>الكاش الفعلي المسلم</td><td>' . number_format((float) ($reportData['cash_details']['actual'] ?? 0), 2) . ' ريال</td></tr>
                <tr class="total"><td>الحالة</td><td class="' . $diffClass . '">' . $diffLabel . ' (' . number_format(abs($diff), 2) . ' ريال)</td></tr>
            </table>';

        $html .= '</body></html>';
        return $html;
    }


    public function showReport($id)
    {
        $accountant = auth('accountant')->user();
        $balance = DailyBalance::where('store_id', $accountant->store_id)->findOrFail($id);

        $logDetails = Log::where('model_type', 'DailyBalance')
            ->where('model_id', $balance->id)
            ->where('action', 'balance_done')
            ->first();

        $data = $logDetails ? json_decode($logDetails->details, true) : [];

        return view('accountant.balance.report', [
            'balance' => $balance,
            'store' => $accountant->store,
            'accountant' => $accountant,
            'data' => $data,
        ]);
    }

    private function cleanupOldReports()
    {
        try {
            // سياسة الاحتفاظ: حذف تقارير PDF الأقدم من 10 أيام.
            $cutoffDate = now()->subDays(10)->getTimestamp();
            $folder = public_path('reports/');

            if (file_exists($folder)) {
                $files = glob($folder . 'Report_*.pdf');

                foreach ($files as $file) {
                    if (filemtime($file) < $cutoffDate) {
                        @unlink($file);
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error cleaning up old reports: ' . $e->getMessage());
        }
    }
}
