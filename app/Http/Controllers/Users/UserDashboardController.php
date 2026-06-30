<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\CreditSale;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Log;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\EmployeeLogService;
use App\Services\ShiftLifecycleService;
use App\Services\Stores\StoreAccessService;
use App\Services\Stores\ActiveAccountantService;
use App\Services\Shifts\ShiftGapInfoService;
use App\Services\Shifts\ShiftGapRequestService;
use App\Http\Controllers\Employees\EmployeeService;

class UserDashboardController extends Controller
{
    private const COLLECTED_SALE_TYPES = ['cash', 'card', 'credit', 'mixed'];

    /**
     * عرض لوحة المالك بعد تجميع كل جزء من البيانات داخل دالة مستقلة.
     */
    public function index()
    {
        $user = auth('web')->user();
        $stores = app(StoreAccessService::class)->activeStoresForOwner($user);
        $storeIds = $stores->pluck('id');

        if ($storeIds->isEmpty()) {
            return view('dashboard.user.index', $this->emptyStateData($user, $stores));
        }

        $dailySummary = $this->buildDailySummary($storeIds);
        $monthlySummary = $this->buildMonthlySummary($user->id, $storeIds);
        $salarySummary = $this->buildSalarySummary($user, $storeIds);
        $creditSummary = $this->buildCreditSummary($storeIds);
        $inventorySummary = $this->buildInventorySummary($user->id, $storeIds);
        $metricStoreBreakdowns = $this->buildStoreBreakdowns(
            $stores,
            $monthlySummary['store_metrics'],
            $salarySummary['salariesByStore']
        );

        $subscriptionEnd = $user->subscription_end_at;
        $daysLeft = $subscriptionEnd ? now()->diffInDays($subscriptionEnd, false) : null;
        $chartData = $this->prepareChartData($storeIds);
        $activities = Log::with('store')
            ->whereIn('store_id', $storeIds)
            ->latest()
            ->limit(10)
            ->get();

        return view('dashboard.user.index', array_merge(
            [
                'user' => $user,
                'stores' => $stores,
                'daysLeft' => $daysLeft,
                'activities' => $activities,
                'metricStoreBreakdowns' => $metricStoreBreakdowns,
                'suspendedEmployeeAlerts' => $this->buildSuspendedEmployeeAlerts($user, $storeIds),
                'missingShiftAlerts' => $this->buildMissingShiftAlerts($stores),
                'pendingStoreTransfersCount' => \App\Models\StoreTransfer::where('status', 'pending')
                    ->where(function ($query) use ($storeIds) {
                        $query->whereIn('sender_store_id', $storeIds)
                            ->orWhereIn('receiver_store_id', $storeIds);
                    })->count(),
            ],
            $dailySummary,
            $monthlySummary['totals'],
            $salarySummary,
            $creditSummary,
            $inventorySummary,
            $chartData
        ));
    }


    private function buildMissingShiftAlerts(Collection $stores): Collection
    {
        $shiftService = app(ShiftLifecycleService::class);

        return $stores->filter(fn ($store) => $store->status === 'active')->map(function ($store) use ($shiftService) {
            $missingDates = $shiftService->missingBusinessDates($store->id);

            if (empty($missingDates)) {
                return null;
            }

            $activeAccountants = app(ActiveAccountantService::class)->activeAccountantsForStore($store, auth()->user());
            $shiftRows = collect($missingDates)->flatMap(function ($date) use ($store) {
                return app(ShiftGapInfoService::class)->missingShiftRowsForDate($store, $date);
            })->values();
            $requestStatuses = app(ShiftGapRequestService::class)->activeStatusesForMissingRows($store->id, $shiftRows);

            return [
                'store' => $store,
                'active_accountants' => $activeAccountants,
                'missing_dates' => $shiftRows->map(fn ($row) => array_merge($row, [
                    'request_status' => $requestStatuses[$row['date'].'#'.$row['missing_shift_number']] ?? null,
                ]))->values(),
                'missing_count' => $shiftRows->count(),
                'first_missing_date' => $missingDates[0],
                'last_missing_date' => $missingDates[count($missingDates) - 1],
            ];
        })->filter()->values();
    }



    public function dismissSuspendedEmployeeAlert(Employee $employee, Request $request)
    {
        $user = auth('web')->user();
        $this->authorizeOwnerEmployee($employee, $user);

        $nextReminder = $this->nextSuspendedEmployeeReminderDate();

        Cache::put(
            $this->suspendedEmployeeTravelCacheKey($user->id, $employee->id),
            true,
            $nextReminder
        );

        EmployeeLogService::add(
            $employee,
            'employee_unpaid_leave_confirmed',
            "تم اعتبار الموظف {$employee->name} مسافر / إجازة بدون راتب حتى تاريخ {$nextReminder->format('Y-m-d')}",
            null,
            ['next_reminder_at' => $nextReminder->toDateString()]
        );

        return back()->with('success', 'تم تأجيل تنبيه الموظف الموقوف حتى تاريخ 10 القادم وتسجيله كمسافر / إجازة بدون راتب.');
    }

    public function terminateSuspendedEmployee(Employee $employee, Request $request)
    {
        $user = auth('web')->user();
        $this->authorizeOwnerEmployee($employee, $user);

        if ($employee->status !== 'suspended') {
            return back()->with('error', 'لا يمكن تنفيذ الفصل إلا على موظف موقوف.');
        }

        DB::transaction(function () use ($employee) {
            EmployeeLogService::add($employee, 'employee_terminated', "تم فصل الموظف {$employee->name} من تنبيه المالك بعد مراجعة المديونيات والبيع الآجل.");

            $employee->accountant()
                ->withTrashed()
                ->update(['status' => 'suspended']);

            $employee->accountant()->withTrashed()->delete();
            $employee->delete();
        });

        return back()->with('success', 'تم فصل الموظف وحذف حساب المحاسب المرتبط إن وجد.');
    }

    /**
     * إرجاع بطاقات اليوم وآخر عملية دون إعادة تحميل الصفحة.
     *
     * النتيجة تخزن لثلاث ثوانٍ فقط لمنع تكرار الحساب نفسه بين عدة تبويبات.
     */
    public function dailySnapshot()
    {
        $user = auth('web')->user();
        $stores = $user->stores;
        $storeIds = $stores->pluck('id');
        $storeKey = $storeIds->sort()->implode('-') ?: 'none';
        $cacheKey = "owner-dashboard:{$user->id}:daily-snapshot:".today()->toDateString().":{$storeKey}";

        $snapshot = Cache::remember($cacheKey, now()->addSeconds(3), function () use ($storeIds) {
            $dailySummary = $this->buildDailySummary($storeIds);
            $latestSale = Sale::query()
                ->collectedDashboardSales()
                ->whereIn('store_id', $storeIds)
                ->forAccountingDate(today()->toDateString())
                ->with(['store:id,name', 'items.product:id,name'])
                ->latest()
                ->first();

            return [
                'sales_today' => $dailySummary['salesToday'],
                'expenses_today' => $dailySummary['expensesToday'],
                'products_cost_today' => $dailySummary['productsCostToday'],
                // المصروفات لا تخصم من الربح بناءً على توجيه النظام الحالي.
                'profit_today' => $dailySummary['profitToday'],
                'operations_count' => $dailySummary['dailySalesOperationsCount'],
                'latest_operation' => $this->buildLatestOperation($latestSale),
            ];
        });

        $snapshot['updated_at'] = now()->format('h:i:s A');

        return response()->json($snapshot)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * حساب مؤشرات اليوم. الربح لا يخصم المصروفات حسب السلوك المعتمد حاليًا.
     */
    private function buildDailySummary(Collection $storeIds): array
    {
        $salesQuery = Sale::query()
            ->collectedDashboardSales()
            ->whereIn('store_id', $storeIds)
            ->forAccountingDate(today()->toDateString());

        $salesToday = (float) (clone $salesQuery)->sum('paid_amount');
        $productsCostToday = $this->calculateProductsCost(
            $storeIds,
            today()->startOfDay(),
            today()->endOfDay(),
            self::COLLECTED_SALE_TYPES
        );

        return [
            'salesToday' => $salesToday,
            'dailySalesOperationsCount' => (int) (clone $salesQuery)->count(),
            'productsCostToday' => $productsCostToday,
            'expensesToday' => (float) Expense::whereIn('store_id', $storeIds)
                ->forAccountingDate(today()->toDateString())
                ->sum('amount'),
            'profitToday' => $salesToday - $productsCostToday,
        ];
    }

    /**
     * بناء ملخص الشهر مرة واحدة مع القيم المجمعة حسب المتجر.
     */
    private function buildMonthlySummary(int $userId, Collection $storeIds): array
    {
        $monthKey = now()->format('Y-m');
        $storeKey = $storeIds->sort()->implode('-');

        return Cache::remember(
            "owner-dashboard:{$userId}:monthly-summary:{$monthKey}:{$storeKey}",
            now()->addMinutes(5),
            function () use ($storeIds) {
                $monthStart = now()->startOfMonth();
                $monthEnd = now()->endOfMonth();
                $salesByStore = $this->sumCollectedSalesByStore($storeIds, $monthStart, $monthEnd);
                $productsCostByStore = $this->calculateProductsCostByStore(
                    $storeIds,
                    $monthStart,
                    $monthEnd,
                    self::COLLECTED_SALE_TYPES
                );
                $expensesByStore = $this->sumByStoreForPeriod(
                    'expenses',
                    'amount',
                    $storeIds,
                    $monthStart,
                    $monthEnd
                );
                $ownerPurchasesByStore = $this->sumByStoreForPeriod(
                    'purchases',
                    'cost',
                    $storeIds,
                    $monthStart,
                    $monthEnd
                );
                $accountantConsumptionByStore = Sale::query()
                    ->excludeManualInvoiceEntries()
                    ->whereIn('store_id', $storeIds)
                    ->betweenAccountingDates($monthStart, $monthEnd)
                    ->where('sale_type', 'internal_use')
                    ->groupBy('store_id')
                    ->selectRaw('store_id, COALESCE(SUM(total), 0) as aggregate')
                    ->pluck('aggregate', 'store_id');

                $storeMetrics = [];
                foreach ($storeIds as $storeId) {
                    $sales = (float) ($salesByStore[$storeId] ?? 0);
                    $cost = (float) ($productsCostByStore[$storeId] ?? 0);
                    $expenses = (float) ($expensesByStore[$storeId] ?? 0);
                    $purchases = (float) ($ownerPurchasesByStore[$storeId] ?? 0);
                    $consumption = (float) ($accountantConsumptionByStore[$storeId] ?? 0);

                    $storeMetrics[$storeId] = [
                        'sales_month' => $sales,
                        'products_cost_month' => $cost,
                        'expenses_month' => $expenses,
                        'monthly_owner_purchases' => $purchases,
                        'monthly_accountant_consumption' => $consumption,
                        'monthly_purchases_consumption' => $purchases + $consumption,
                        'profit_month' => $sales - $cost - $expenses - $purchases - $consumption,
                    ];
                }

                $salesMonth = (float) $salesByStore->sum();
                $productsCostMonth = array_sum(array_column($storeMetrics, 'products_cost_month'));
                $expensesMonth = (float) $expensesByStore->sum();
                $monthlyOwnerPurchases = (float) $ownerPurchasesByStore->sum();
                $monthlyAccountantConsumption = (float) $accountantConsumptionByStore->sum();
                $monthlyPurchasesAndConsumption = $monthlyOwnerPurchases + $monthlyAccountantConsumption;

                return [
                    'totals' => [
                        'salesMonth' => $salesMonth,
                        'expensesMonth' => $expensesMonth,
                        'profitMonth' => $salesMonth
                            - $productsCostMonth
                            - $expensesMonth
                            - $monthlyPurchasesAndConsumption,
                        'monthlyOwnerPurchases' => $monthlyOwnerPurchases,
                        'monthlyAccountantConsumption' => $monthlyAccountantConsumption,
                        'monthlyPurchasesAndConsumption' => $monthlyPurchasesAndConsumption,
                    ],
                    'store_metrics' => $storeMetrics,
                ];
            }
        );
    }

    /**
     * تجهيز الرواتب والسحوبات، بما فيها مجموع الرواتب لكل متجر باستعلام واحد.
     */
    private function buildSalarySummary($user, Collection $storeIds): array
    {
        $employeesWithoutSalary = $user->employees()
            ->with('store:id,name')
            ->whereIn('store_id', $storeIds)
            ->where(function ($query) {
                $query->whereNull('salary')->orWhere('salary', '<=', 0);
            })
            ->orderBy('store_id')
            ->orderBy('name')
            ->get();

        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $employeeRows = Employee::withTrashed()
            ->with('store:id,name')
            ->whereIn('store_id', $storeIds)
            ->where(function ($query) use ($periodStart, $periodEnd) {
                $query->whereNull('deleted_at')
                    ->orWhereBetween('deleted_at', [$periodStart, $periodEnd]);
            })
            ->get(['id', 'store_id', 'name', 'salary', 'status', 'deleted_at']);

        $employeeIds = $employeeRows->pluck('id');
        $absenceDaysByEmployee = $employeeIds->isEmpty()
            ? collect()
            : Absence::whereIn('store_id', $storeIds)
                ->where('person_type', Employee::class)
                ->whereIn('person_id', $employeeIds)
                ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->selectRaw('person_id, COUNT(*) as absence_days')
                ->groupBy('person_id')
                ->pluck('absence_days', 'person_id');

        $employeeMonthlyWithdrawals = $employeeRows
            ->map(function (Employee $employee) use ($periodStart, $periodEnd, $absenceDaysByEmployee) {
                $salaryInfo = EmployeeService::calculateProratedSalaryForEmployee($employee, $periodStart, $periodEnd);
                $withdrawalsQuery = DB::table('employee_withdrawals')
                    ->where('person_id', $employee->id)
                    ->where('person_type', Employee::class);

                $this->applyAccountingPeriodToTable($withdrawalsQuery, 'employee_withdrawals', $periodStart, $periodEnd);

                $withdrawalsTotal = (float) $withdrawalsQuery->sum('amount');
                $absenceDays = (int) ($absenceDaysByEmployee[$employee->id] ?? 0);
                $absenceDeduction = $absenceDays * (((float) $employee->salary) / max(1, $periodStart->daysInMonth));

                return (object) [
                    'id' => $employee->id,
                    'store_id' => $employee->store_id,
                    'name' => $employee->name,
                    'store_name' => $employee->store?->name,
                    'base_salary' => (float) $employee->salary,
                    'salary' => $salaryInfo['payable_salary'],
                    'worked_days' => $salaryInfo['worked_days'],
                    'suspended_days' => $salaryInfo['suspended_days'],
                    'withdrawals_total' => $withdrawalsTotal,
                    'absence_days' => $absenceDays,
                    'absence_deduction' => $absenceDeduction,
                ];
            });

        $salariesByStore = $employeeMonthlyWithdrawals
            ->groupBy('store_id')
            ->map(fn ($employees) => $employees->sum('salary'));

        $employeeSalaryRemainders = $employeeMonthlyWithdrawals
            ->map(fn ($employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'store_name' => $employee->store_name,
                'base_salary' => (float) $employee->base_salary,
                'salary' => (float) $employee->salary,
                'worked_days' => (int) $employee->worked_days,
                'suspended_days' => (int) $employee->suspended_days,
                'withdrawals_total' => (float) $employee->withdrawals_total,
                'absence_days' => (int) $employee->absence_days,
                'absence_deduction' => (float) $employee->absence_deduction,
                'salary_remaining' => max(
                    0,
                    (float) $employee->salary - (float) $employee->withdrawals_total - (float) $employee->absence_deduction
                ),
            ])
            ->values();

        $monthlySalaries = (float) $salariesByStore->sum();
        $monthlyWorkerWithdrawalsQuery = DB::table('employee_withdrawals')
            ->whereIn('store_id', $storeIds)
            ->where('person_type', Employee::class);

        $this->applyAccountingPeriodToTable($monthlyWorkerWithdrawalsQuery, 'employee_withdrawals', $periodStart, $periodEnd);

        $monthlyWorkerWithdrawals = (float) $monthlyWorkerWithdrawalsQuery->sum('amount');
        $monthlyAbsenceDeductions = (float) $employeeMonthlyWithdrawals->sum('absence_deduction');

        return [
            'employeesCount' => $user->employees()->count(),
            'employeesWithoutSalary' => $employeesWithoutSalary,
            'employeesWithoutSalaryCount' => $employeesWithoutSalary->count(),
            'monthlySalaries' => $monthlySalaries,
            'monthlyWorkerWithdrawals' => $monthlyWorkerWithdrawals,
            'monthlyAbsenceDeductions' => $monthlyAbsenceDeductions,
            'netMonthlySalaries' => max(0, $monthlySalaries - $monthlyWorkerWithdrawals - $monthlyAbsenceDeductions),
            'employeeSalaryRemainders' => $employeeSalaryRemainders,
            'salariesByStore' => $salariesByStore,
        ];
    }

    /**
     * مؤشرات المديونيات من المصدر الفعلي employee_credit_sales.
     */
    private function buildCreditSummary(Collection $storeIds): array
    {
        return [
            'creditOpen' => CreditSale::whereIn('store_id', $storeIds)
                ->where('status', 'pending')
                ->where('remaining_amount', '>', 0)
                ->count(),
            'creditClosed' => CreditSale::whereIn('store_id', $storeIds)
                ->where('status', 'deducted')
                ->count(),
            'creditLate' => CreditSale::whereIn('store_id', $storeIds)
                ->where('status', 'pending')
                ->where('remaining_amount', '>', 0)
                ->whereDate('date', '<', now()->subDays(30))
                ->count(),
        ];
    }

    /**
     * قوائم المخزون المنخفض وأفضل المنتجات.
     */
    private function buildInventorySummary(int $userId, Collection $storeIds): array
    {
        $lowStockProducts = Product::with('store')
            ->whereIn('store_id', $storeIds)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('sale_items')
                    ->whereColumn('sale_items.product_id', 'products.id');
            })
            ->lowStock()
            ->orderBy('quantity')
            ->get();

        $topSellingProducts = Cache::remember(
            "owner-dashboard:{$userId}:top-products:".now()->format('Y-m').':'.$storeIds->sort()->implode('-'),
            now()->addMinutes(5),
            fn () => DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->join('products', 'sale_items.product_id', '=', 'products.id')
                ->join('stores', 'sales.store_id', '=', 'stores.id')
                ->whereIn('sales.store_id', $storeIds)
                ->whereRaw('COALESCE(sales.business_date, DATE(sales.created_at)) BETWEEN ? AND ?', [
                    now()->startOfMonth()->toDateString(),
                    now()->endOfMonth()->toDateString(),
                ])
                ->whereIn('sales.sale_type', self::COLLECTED_SALE_TYPES)
                ->where(function ($query) {
                    $query->whereNull('sales.description')
                        ->orWhere('sales.description', '!=', 'manual_invoice_entry');
                })
                ->whereNull('products.deleted_at')
                ->groupBy('sales.store_id', 'stores.name', 'products.id', 'products.name')
                ->selectRaw('sales.store_id, stores.name as store_name, products.id, products.name')
                ->selectRaw('COUNT(DISTINCT sales.id) as operations_count')
                ->selectRaw('COALESCE(SUM(sale_items.quantity), 0) as sold_quantity')
                ->selectRaw('COALESCE(SUM(sale_items.total), 0) as sales_value')
                ->get()
                ->groupBy('store_id')
                ->flatMap(fn ($products) => $products
                    ->sortByDesc('sold_quantity')
                    ->take(5)
                    ->values())
                ->values()
        );

        return [
            'lowStockProducts' => $lowStockProducts,
            'lowStockCount' => $lowStockProducts->count(),
            'topSellingProducts' => $topSellingProducts,
        ];
    }

    /**
     * بناء تفاصيل البطاقات من نتائج مجمعة بدل استعلامات داخل حلقة المتاجر.
     */
    private function buildStoreBreakdowns(
        Collection $stores,
        array $monthlyMetrics,
        Collection $salariesByStore
    ): array
    {
        $storeIds = $stores->pluck('id');
        $todayStart = today()->startOfDay();
        $todayEnd = today()->endOfDay();
        $salesTodayByStore = $this->sumCollectedSalesByStore($storeIds, $todayStart, $todayEnd);
        $productsCostTodayByStore = $this->calculateProductsCostByStore(
            $storeIds,
            $todayStart,
            $todayEnd,
            self::COLLECTED_SALE_TYPES
        );
        $expensesTodayByStore = $this->sumByStoreForPeriod(
            'expenses',
            'amount',
            $storeIds,
            $todayStart,
            $todayEnd
        );
        return $stores->map(function ($store) use (
            $salesTodayByStore,
            $productsCostTodayByStore,
            $expensesTodayByStore,
            $salariesByStore,
            $monthlyMetrics
        ) {
            $storeId = $store->id;
            $salesToday = (float) ($salesTodayByStore[$storeId] ?? 0);
            $productsCostToday = (float) ($productsCostTodayByStore[$storeId] ?? 0);
            $month = $monthlyMetrics[$storeId] ?? [];

            return array_merge([
                'store_id' => $storeId,
                'store_name' => $store->name,
                // المصروفات تعرض منفصلة ولا تخصم من ربح اليوم.
                'profit_today' => $salesToday - $productsCostToday,
                'sales_today' => $salesToday,
                'expenses_today' => (float) ($expensesTodayByStore[$storeId] ?? 0),
                'products_cost_today' => $productsCostToday,
                'salaries_month' => (float) ($salariesByStore[$storeId] ?? 0),
            ], $month);
        })->values()->all();
    }

    /**
     * تجميع المبيعات المحصلة حسب المتجر لفترة محددة.
     */
    private function sumCollectedSalesByStore(Collection $storeIds, $start, $end): Collection
    {
        return Sale::query()
            ->collectedDashboardSales()
            ->whereIn('store_id', $storeIds)
            ->betweenAccountingDates($start, $end)
            ->groupBy('store_id')
            ->selectRaw('store_id, COALESCE(SUM(paid_amount), 0) as aggregate')
            ->pluck('aggregate', 'store_id');
    }

    /**
     * تجميع عمود مالي حسب المتجر لفترة محددة.
     */
    private function sumByStoreForPeriod(
        string $table,
        string $amountColumn,
        Collection $storeIds,
        $start,
        $end
    ): Collection {
        $query = DB::table($table)
            ->whereIn('store_id', $storeIds);

        $this->applyAccountingPeriodToTable($query, $table, $start, $end);

        return $query
            ->groupBy('store_id')
            ->selectRaw("store_id, COALESCE(SUM({$amountColumn}), 0) as aggregate")
            ->pluck('aggregate', 'store_id');
    }


    /**
     * تطبيق فترة محاسبية على استعلامات Query Builder التي لا تستخدم موديلات Eloquent.
     */
    private function applyAccountingPeriodToTable($query, string $table, $start, $end): void
    {
        static $hasBusinessDate = [];

        if (! array_key_exists($table, $hasBusinessDate)) {
            $hasBusinessDate[$table] = Schema::hasColumn($table, 'business_date');
        }

        if (! $hasBusinessDate[$table]) {
            $query->whereBetween("{$table}.created_at", [
                \Carbon\Carbon::parse($start)->startOfDay(),
                \Carbon\Carbon::parse($end)->endOfDay(),
            ]);

            return;
        }

        $startDate = \Carbon\Carbon::parse($start)->toDateString();
        $endDate = \Carbon\Carbon::parse($end)->toDateString();

        $query->whereRaw(
            "COALESCE({$table}.business_date, DATE({$table}.created_at)) BETWEEN ? AND ?",
            [$startDate, $endDate]
        );
    }

    /**
     * حساب إجمالي تكلفة المنتجات لجميع المتاجر المطلوبة.
     */
    private function calculateProductsCost($storeIds, $start, $end, array $saleTypes): float
    {
        return array_sum($this->calculateProductsCostByStore($storeIds, $start, $end, $saleTypes));
    }

    /**
     * حساب تكلفة المنتجات مجمعة حسب store_id باستعلام واحد.
     */
    private function calculateProductsCostByStore($storeIds, $start, $end, array $saleTypes): array
    {
        static $hasStoredItemCosts;

        $storeIds = collect($storeIds)->map(fn ($id) => (int) $id)->filter()->values();
        if ($storeIds->isEmpty()) {
            return [];
        }

        $hasStoredItemCosts ??= Schema::hasColumn('sale_items', 'total_cost');

        if (! $hasStoredItemCosts) {
            return Sale::query()
                ->excludeManualInvoiceEntries()
                ->whereIn('store_id', $storeIds)
                ->betweenAccountingDates($start, $end)
                ->whereIn('sale_type', $saleTypes)
                ->where('products_total', '>', 0)
                ->groupBy('store_id')
                // الحل الثاني: لا نسمح لأي تكلفة تراجعية سالبة بالدخول في إجمالي تكلفة المنتجات.
                // هذا يصحح شغل اليد والمنتجات بلا تكلفة إلى 0 بدل أن تخفض تكلفة المتجر.
                ->selectRaw('store_id, COALESCE(SUM(GREATEST((products_total + labor_total) - profit, 0)), 0) as aggregate')
                ->pluck('aggregate', 'store_id')
                ->map(fn ($value) => (float) $value)
                ->all();
        }

        $salesCosts = DB::table('sales')
            ->leftJoin('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sales.store_id', $storeIds)
            ->whereRaw('COALESCE(sales.business_date, DATE(sales.created_at)) BETWEEN ? AND ?', [
                \Carbon\Carbon::parse($start)->toDateString(),
                \Carbon\Carbon::parse($end)->toDateString(),
            ])
            ->whereIn('sales.sale_type', $saleTypes)
            ->where(function ($query) {
                $query->whereNull('sales.description')
                    ->orWhere('sales.description', '!=', 'manual_invoice_entry');
            })
            ->groupBy(
                'sales.id',
                'sales.store_id',
                'sales.products_total',
                'sales.labor_total',
                'sales.profit'
            )
            ->selectRaw('sales.store_id')
            ->selectRaw('COUNT(sale_items.id) as items_count')
            ->selectRaw('SUM(CASE WHEN sale_items.total_cost IS NOT NULL THEN 1 ELSE 0 END) as costed_items_count')
            ->selectRaw('COALESCE(SUM(sale_items.total_cost), 0) as saved_items_cost')
            // legacy_cost يستعمل فقط عند نقص تكلفة بعض عناصر البيع، لذلك نحميه من السالب.
            ->selectRaw('GREATEST(COALESCE((sales.products_total + sales.labor_total) - sales.profit, 0), 0) as legacy_cost');

        return DB::query()
            ->fromSub($salesCosts, 'sales_costs')
            ->groupBy('store_id')
            ->selectRaw('store_id')
            ->selectRaw(
                'COALESCE(SUM(
                    CASE
                        WHEN items_count = 0 THEN 0
                        WHEN items_count = costed_items_count THEN GREATEST(saved_items_cost, 0)
                        ELSE legacy_cost
                    END
                ), 0) as aggregate'
            )
            ->pluck('aggregate', 'store_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /**
     * تحويل آخر عملية إلى بنية مختصرة للواجهة.
     */
    private function buildLatestOperation(?Sale $latestSale): ?array
    {
        if (! $latestSale) {
            return null;
        }

        $description = trim((string) $latestSale->description);
        $isTintOperation = mb_stripos($description, 'تضليل') !== false
            || mb_stripos($description, 'تظليل') !== false;
        $productNames = $latestSale->items
            ->map(fn ($item) => optional($item->product)->name)
            ->filter()
            ->unique()
            ->values();
        $operationName = $isTintOperation
            ? $description
            : ($productNames->isNotEmpty()
                ? $productNames->implode(' - ')
                : ($description ?: ((float) $latestSale->labor_total > 0 ? 'شغل يد' : 'عملية بيع')));

        return [
            'id' => (int) $latestSale->id,
            'store_name' => $latestSale->store->name ?? 'متجر غير معروف',
            'description' => $operationName,
            'is_tint' => $isTintOperation,
            'amount' => (float) ($latestSale->paid_amount ?? 0),
            'time' => optional($latestSale->created_at)->format('h:i A'),
        ];
    }

    /**
     * تجهيز مخطط آخر 14 يومًا من المبيعات والمصروفات والدين المتبقي الفعلي.
     */
    private function prepareChartData(Collection $storeIds): array
    {
        $chartStart = now()->subDays(13)->startOfDay();
        $chartEnd = now()->endOfDay();

        $dailySales = Sale::query()
            ->collectedDashboardSales()
            ->selectRaw('COALESCE(business_date, DATE(created_at)) as day, SUM(paid_amount) as total')
            ->whereIn('store_id', $storeIds)
            ->betweenAccountingDates($chartStart, $chartEnd)
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $dailyExpenses = Expense::selectRaw('COALESCE(business_date, DATE(created_at)) as day, SUM(amount) as total')
            ->whereIn('store_id', $storeIds)
            ->betweenAccountingDates($chartStart, $chartEnd)
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $dailyRemainingCredit = CreditSale::selectRaw('DATE(date) as day, SUM(remaining_amount) as total')
            ->whereIn('store_id', $storeIds)
            ->where('status', 'pending')
            ->where('remaining_amount', '>', 0)
            ->whereBetween('date', [$chartStart->toDateString(), $chartEnd->toDateString()])
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $labels = [];
        $sales = [];
        $expenses = [];
        $remainingCredit = [];

        for ($dayOffset = 0; $dayOffset < 14; $dayOffset++) {
            $date = $chartStart->copy()->addDays($dayOffset)->toDateString();
            $labels[] = $date;
            $sales[] = (float) ($dailySales[$date]->total ?? 0);
            $expenses[] = (float) ($dailyExpenses[$date]->total ?? 0);
            $remainingCredit[] = (float) ($dailyRemainingCredit[$date]->total ?? 0);
        }

        return [
            'chartLabels' => $labels,
            'chartSales' => $sales,
            'chartExpenses' => $expenses,
            'chartCredit' => $remainingCredit,
        ];
    }



    private function buildSuspendedEmployeeAlerts($user, Collection $storeIds): Collection
    {
        return Employee::with(['store:id,name', 'accountant' => fn ($query) => $query->withTrashed()])
            ->whereIn('store_id', $storeIds)
            ->where('status', 'suspended')
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'store_id', 'name', 'status', 'updated_at'])
            ->reject(fn (Employee $employee) => Cache::has($this->suspendedEmployeeTravelCacheKey($user->id, $employee->id)))
            ->map(function (Employee $employee) {
                $suspendedAt = \App\Models\EmployeeLog::where('person_type', Employee::class)
                    ->where('person_id', $employee->id)
                    ->where('action_name', 'employee_suspended')
                    ->latest('created_at')
                    ->value('created_at');
                $suspendedAtDate = $suspendedAt
                    ? \Carbon\Carbon::parse($suspendedAt)->format('Y-m-d')
                    : 'غير محدد - موقوف قبل نظام السجلات';

                $debtsTotal = (float) \App\Models\Debt::where('person_type', Employee::class)
                    ->where('person_id', $employee->id)
                    ->where('status', 'pending')
                    ->sum('amount');

                $creditTotal = (float) CreditSale::where('person_type', Employee::class)
                    ->where('person_id', $employee->id)
                    ->where('remaining_amount', '>', 0)
                    ->sum('remaining_amount');

                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'store_name' => $employee->store?->name ?? 'متجر غير معروف',
                    'suspended_at' => $suspendedAtDate,
                    'debts_total' => $debtsTotal,
                    'credit_total' => $creditTotal,
                    'has_accountant' => (bool) $employee->accountant,
                ];
            })
            ->values();
    }

    private function authorizeOwnerEmployee(Employee $employee, $user): void
    {
        if (!$user || !$user->stores()->where('id', $employee->store_id)->exists()) {
            abort(403);
        }
    }

    private function suspendedEmployeeTravelCacheKey(int $userId, int $employeeId): string
    {
        return "owner:{$userId}:suspended-employee-alert:{$employeeId}:traveler";
    }

    private function nextSuspendedEmployeeReminderDate()
    {
        $nextReminder = now()->copy()->day(10)->startOfDay();

        if (now()->greaterThanOrEqualTo($nextReminder)) {
            $nextReminder->addMonthNoOverflow();
        }

        return $nextReminder;
    }

    /**
     * بيانات آمنة عندما لا يملك المستخدم متاجر.
     */
    private function emptyStateData($user, Collection $stores): array
    {
        return [
            'stores' => $stores,
            'user' => $user,
            'employeesCount' => 0,
            'daysLeft' => 0,
            'salesToday' => 0,
            'salesMonth' => 0,
            'productsCostToday' => 0,
            'expensesToday' => 0,
            'expensesMonth' => 0,
            'profitToday' => 0,
            'profitMonth' => 0,
            'monthlySalaries' => 0,
            'monthlyWorkerWithdrawals' => 0,
            'monthlyAbsenceDeductions' => 0,
            'netMonthlySalaries' => 0,
            'monthlyOwnerPurchases' => 0,
            'monthlyAccountantConsumption' => 0,
            'monthlyPurchasesAndConsumption' => 0,
            'creditOpen' => 0,
            'metricStoreBreakdowns' => [],
            'dailySalesOperationsCount' => 0,
            'lowStockCount' => 0,
            'lowStockProducts' => collect(),
            'topSellingProducts' => collect(),
            'employeeSalaryRemainders' => collect(),
            'employeesWithoutSalary' => collect(),
            'employeesWithoutSalaryCount' => 0,
            'creditClosed' => 0,
            'creditLate' => 0,
            'activities' => collect(),
            'chartLabels' => [],
            'chartSales' => [],
            'chartExpenses' => [],
            'chartCredit' => [],
        ];
    }
}
