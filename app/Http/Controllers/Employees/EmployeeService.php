<?php

namespace App\Http\Controllers\Employees;

use App\Models\Store;
use App\Models\Employee;
use App\Models\Accountant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Services\EmployeeLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class EmployeeService
{
    public static function index()
    {
        $user = auth()->user();

        if ($user->role === 'admin') {
            $storeIds = Store::pluck('id');
        } elseif ($user->role === 'user') {
            $storeIds = $user->stores->pluck('id');
        } elseif (auth('accountant')->check()) {
            $storeIds = [auth('accountant')->user()->store_id];
        } else {
            abort(403);
        }

        // إعادة منطقك الأصلي: جلب IDs الموظفين المرتبطين بمحاسبين فعالين لاستبعادهم
        $activeAccountantEmployeeIds = Accountant::whereIn('store_id', $storeIds)
            ->where('status', 'active')
            ->pluck('employee_id')
            ->filter()
            ->toArray();

        // إعادة منطقك الأصلي: استبعاد موظفي المحاسبة من قائمة الموظفين
        $employees = Employee::whereIn('store_id', $storeIds)
            ->whereNotIn('id', $activeAccountantEmployeeIds)
            ->get();

        $accountants = Accountant::whereIn('store_id', $storeIds)
            ->where('status', '!=', 'active')
            ->get();

        self::attachCurrentMonthSalaryInfo($employees);

        return view('employees.index', compact('employees', 'accountants'));
    }

    public function create(array $data)
    {
        // الحفظ المباشر للبيانات المجهزة من الكنترولر
        return Employee::create($data);
    }

    // بقية الدوال (edit, update, store القديمة) تبقى كما هي دون تغيير في منطقها الأصلي
    public static function edit(Employee $employee)
    {
        if (auth('accountant')->check()) { abort(403); }

        $user = auth('admin')->user() ?: auth('web')->user();

        if (!$user) {
            abort(403);
        }

        if ($user->role !== 'admin' && !$user->stores()->where('id', $employee->store_id)->exists()) {
            abort(403);
        }

        $stores = ($user->role === 'admin') ? Store::all() : $user->stores;

        return view('employees.edit', compact('employee', 'stores'));
    }

    public static function update(Request $request, Employee $employee)
    {
        if (auth('accountant')->check()) { abort(403); }

        $user = auth('admin')->user() ?: auth('web')->user();

        if (!$user) {
            abort(403);
        }

        if ($user->role !== 'admin' && !$user->stores()->where('id', $employee->store_id)->exists()) {
            abort(403);
        }

        $storeRule = Rule::exists('stores', 'id');

        if ($user->role !== 'admin') {
            $storeRule = $storeRule->where(fn ($query) => $query->where('user_id', $user->id));
        }

        $request->validate([
            'store_id' => ['required', $storeRule],
            'name'     => 'required|string|max:255',
            'salary'   => 'required|numeric|min:0',
        ]);

        $oldStoreId = $employee->store_id;
        $oldSalary  = $employee->salary;

        DB::transaction(function () use ($request, $employee, $oldStoreId, $oldSalary) {
            $employee->update($request->only('store_id', 'name', 'phone', 'salary'));

            EmployeeLogService::add($employee, 'employee_updated', "تم تعديل بيانات الموظف {$employee->name}");

            if ($oldStoreId != $employee->store_id) {
                $oldStore = Store::find($oldStoreId);
                $newStore = $employee->store;

                if ($oldStore && $newStore) {
                    self::transferEmployeeFinancialRecordsToStore($employee, (int) $employee->store_id);
                    EmployeeLogService::add(
                        $employee,
                        'employee_transferred',
                        "تم نقل الموظف من متجر {$oldStore->name} إلى متجر {$newStore->name}. تم نقل المديونيات كاملة وسجلات الشهر الحالي فقط، وبقيت المبيعات القديمة في المتجر القديم.",
                        null,
                        [
                            'old_store_id' => $oldStore->id,
                            'old_store_name' => $oldStore->name,
                            'new_store_id' => $newStore->id,
                            'new_store_name' => $newStore->name,
                            'current_month_records_only' => true,
                        ]
                    );
                }
            }

            if ($oldSalary != $employee->salary) {
                EmployeeLogService::add($employee, 'salary_update', "تعديل الراتب من {$oldSalary} إلى {$employee->salary} ريال");
            }
        });

        $returnTo = self::safeReturnTo($request->input('return_to'));

        return redirect($returnTo ?? route('user.employees.index'))
            ->with('success', 'تم تحديث بيانات العامل بنجاح');
    }


    /**
     * إيقاف الموظف ماليًا ووظيفيًا مع إيقاف حساب المحاسب المرتبط فقط إن وجد.
     */
    public static function suspend(Employee $employee, Request $request)
    {
        self::authorizeEmployeeForStatusChange($employee);

        DB::transaction(function () use ($employee) {
            $employee->update(['status' => 'suspended']);

            $employee->accountant()
                ->withTrashed()
                ->where('status', 'active')
                ->update(['status' => 'suspended']);

            EmployeeLogService::add($employee, 'employee_suspended', "تم إيقاف الموظف {$employee->name}");
        });

        $returnTo = self::safeReturnTo($request->input('return_to') ?: $request->query('return_to'));

        return redirect($returnTo ?? route('user.employees.index'))
            ->with('success', 'تم إيقاف الموظف ماليًا ووظيفيًا، وتم إيقاف حساب المحاسب المرتبط إن وجد. لن يتم احتساب راتبه عن أيام الإيقاف.');
    }

    /**
     * تفعيل الموظف فقط دون إعادة تفعيل حساب المحاسب المرتبط.
     */
    public static function activate(Employee $employee, Request $request)
    {
        self::authorizeEmployeeForStatusChange($employee);

        $employee->update(['status' => 'active']);
        EmployeeLogService::add($employee, 'employee_activated', "تم تفعيل الموظف {$employee->name}");

        $returnTo = self::safeReturnTo($request->input('return_to') ?: $request->query('return_to'));

        return redirect($returnTo ?? route('user.employees.index'))
            ->with('success', 'تم تفعيل الموظف فقط. سيتم استئناف احتساب راتبه من تاريخ التفعيل، ولم يتم تفعيل حساب المحاسب المرتبط.');
    }

    private static function authorizeEmployeeForStatusChange(Employee $employee): void
    {
        if (auth('accountant')->check()) { abort(403); }

        $user = auth('admin')->user() ?: auth('web')->user();

        if (!$user) {
            abort(403);
        }

        if ($user->role !== 'admin' && !$user->stores()->where('id', $employee->store_id)->exists()) {
            abort(403);
        }
    }

    /**
     * نقل السجلات المالية واليومية التابعة للموظف إلى متجره الجديد دون نقل المبيعات التشغيلية القديمة.
     */
    public static function transferEmployeeFinancialRecordsToStore(Employee $employee, int $newStoreId): void
    {
        $currentMonthStart = now()->startOfMonth();
        $currentMonthEnd = now()->endOfMonth();

        $employee->accountant()->withTrashed()->update(['store_id' => $newStoreId]);
        $employee->debts()->withTrashed()->update(['store_id' => $newStoreId]);

        self::updateCurrentMonthRecords($employee->logs()->withTrashed(), $newStoreId, $currentMonthStart, $currentMonthEnd);
        self::updateCurrentMonthRecords($employee->withdrawals()->withTrashed(), $newStoreId, $currentMonthStart, $currentMonthEnd);
        self::updateCurrentMonthRecords($employee->absences()->withTrashed(), $newStoreId, $currentMonthStart, $currentMonthEnd);
        self::updateCurrentMonthRecords($employee->salaryReports()->withTrashed(), $newStoreId, $currentMonthStart, $currentMonthEnd);

        // بعض السجلات القديمة ما زالت تعتمد employee_id بدل person_id، لذلك نغطيها بدون تغيير بنية قاعدة البيانات.
        self::updateLegacyEmployeeStoreColumn('employee_debts', \App\Models\Debt::class, $employee->id, $newStoreId);
        self::updateLegacyEmployeeStoreColumn('employee_withdrawals', \App\Models\Withdrawal::class, $employee->id, $newStoreId, $currentMonthStart, $currentMonthEnd);
        self::updateLegacyEmployeeStoreColumn('employee_absences', \App\Models\Absence::class, $employee->id, $newStoreId, $currentMonthStart, $currentMonthEnd);
        self::updateLegacyEmployeeStoreColumn('employee_salary_reports', \App\Models\SalaryReport::class, $employee->id, $newStoreId, $currentMonthStart, $currentMonthEnd);
    }


    /**
     * تحديث السجلات القديمة التي تحتوي employee_id فقط عند وجود العمود فعلياً.
     */
    private static function updateLegacyEmployeeStoreColumn(string $table, string $modelClass, int $employeeId, int $newStoreId, $start = null, $end = null): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn($table, 'employee_id')) {
            return;
        }

        $query = $modelClass::withTrashed()->where('employee_id', $employeeId);

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        $query->update(['store_id' => $newStoreId]);
    }

    private static function updateCurrentMonthRecords($query, int $newStoreId, $start, $end): void
    {
        $query->whereBetween('created_at', [$start, $end])
            ->update(['store_id' => $newStoreId]);
    }

    /**
     * إرفاق أثر الإيقاف على راتب الشهر الحالي لكل موظف لعرضه في الواجهات دون تغيير قاعدة البيانات.
     */
    public static function attachCurrentMonthSalaryInfo(Collection $employees): Collection
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        return $employees->each(function (Employee $employee) use ($start, $end) {
            $employee->salary_info = self::calculateProratedSalaryForEmployee($employee, $start, $end);
        });
    }

    /**
     * حساب الراتب المستحق حسب أيام العمل الفعلية وأيام الإيقاف المسجلة في employee_logs.
     */
    public static function calculateProratedSalaryForEmployee(Employee $employee, $start, $end): array
    {
        $periodStart = $start->copy()->startOfDay();
        $periodEnd = $end->copy()->endOfDay();
        $totalDays = (int) $periodStart->copy()->startOfDay()->diffInDays($periodEnd->copy()->startOfDay()) + 1;

        $events = \App\Models\EmployeeLog::where('person_type', Employee::class)
            ->where('person_id', $employee->id)
            ->whereIn('action_name', ['employee_suspended', 'employee_activated'])
            ->where('created_at', '<=', $periodEnd)
            ->orderBy('created_at')
            ->get(['action_name', 'created_at']);

        $lastBeforePeriod = $events
            ->filter(fn ($event) => $event->created_at->lt($periodStart))
            ->last();

        $suspendedFrom = null;

        if ($lastBeforePeriod?->action_name === 'employee_suspended') {
            $suspendedFrom = $periodStart->copy();
        } elseif (!$lastBeforePeriod && $events->isEmpty() && $employee->status === 'suspended') {
            $suspendedFrom = $periodStart->copy();
        }

        $suspendedDays = 0;

        foreach ($events->filter(fn ($event) => $event->created_at->betweenIncluded($periodStart, $periodEnd)) as $event) {
            if ($event->action_name === 'employee_suspended' && !$suspendedFrom) {
                $suspendedFrom = $event->created_at->copy()->startOfDay();
                continue;
            }

            if ($event->action_name === 'employee_activated' && $suspendedFrom) {
                $suspendedUntil = $event->created_at->copy()->startOfDay()->subDay();
                $suspendedDays += self::countInclusiveDays($suspendedFrom, $suspendedUntil);
                $suspendedFrom = null;
            }
        }

        if ($suspendedFrom) {
            $suspendedDays += self::countInclusiveDays($suspendedFrom, $periodEnd);
        }

        $suspendedDays = min($suspendedDays, $totalDays);
        $workedDays = max(0, $totalDays - $suspendedDays);
        $payableSalary = $totalDays > 0
            ? round(((float) $employee->salary / $totalDays) * $workedDays, 2)
            : 0.0;

        return [
            'base_salary' => (float) $employee->salary,
            'payable_salary' => $payableSalary,
            'worked_days' => $workedDays,
            'suspended_days' => $suspendedDays,
            'total_days' => $totalDays,
        ];
    }

    private static function countInclusiveDays($from, $to): int
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        return (int) $start->diffInDays($end) + 1;
    }

    public static function safeReturnTo(?string $returnTo): ?string
    {
        if (!$returnTo) {
            return null;
        }

        if (str_starts_with($returnTo, '/')) {
            return $returnTo;
        }

        $appHost = parse_url(url('/'), PHP_URL_HOST);
        $targetHost = parse_url($returnTo, PHP_URL_HOST);

        return $targetHost && $targetHost === $appHost ? $returnTo : null;
    }
}
