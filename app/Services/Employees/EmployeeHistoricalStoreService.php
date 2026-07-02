<?php

namespace App\Services\Employees;

use App\Models\Absence;
use App\Models\CreditSale;
use App\Models\Debt;
use App\Models\Employee;
use App\Models\EmployeeLog;
use App\Models\Withdrawal;
use App\Services\Accounting\FinancialSummaryService;
use Illuminate\Support\Collection;

class EmployeeHistoricalStoreService
{
    /**
     * يرجع الموظفين الذين يجب أن يظهروا في تقرير متجر وفترة، حتى لو نُقلوا لاحقًا.
     */
    public function employeesForStoreDuringPeriod(int $storeId, $periodStart, $periodEnd): Collection
    {
        $historicalEmployeeIds = $this->employeeIdsForStorePeriod($storeId, $periodStart, $periodEnd);

        return Employee::withTrashed()
            ->where(function ($query) use ($storeId, $historicalEmployeeIds) {
                $query->where('store_id', $storeId)
                    ->orWhereIn('id', $historicalEmployeeIds);
            })
            ->where(function ($query) use ($periodStart, $periodEnd) {
                $query->whereNull('deleted_at')
                    ->orWhereBetween('deleted_at', [$periodStart, $periodEnd]);
            })
            ->orderBy('name')
            ->get(['id', 'store_id', 'name', 'salary', 'status', 'deleted_at']);
    }

    public function employeeIdsForStorePeriod(int $storeId, $periodStart, $periodEnd): Collection
    {
        $transferredAfterPeriodEmployeeIds = EmployeeLog::query()
            ->where('action_name', 'employee_transferred')
            ->where('person_type', Employee::class)
            ->where('meta->old_store_id', $storeId)
            ->where('created_at', '>', $periodEnd)
            ->pluck('person_id');

        $withdrawalEmployeeIdsQuery = Withdrawal::where('store_id', $storeId)
            ->where('person_type', Employee::class);
        app(FinancialSummaryService::class)->applyAccountingPeriodToTable(
            $withdrawalEmployeeIdsQuery,
            'employee_withdrawals',
            $periodStart,
            $periodEnd
        );

        $withdrawalEmployeeIds = $withdrawalEmployeeIdsQuery->pluck('person_id');

        $absenceEmployeeIds = Absence::where('store_id', $storeId)
            ->where('person_type', Employee::class)
            ->betweenOperationDates($periodStart, $periodEnd)
            ->pluck('person_id');

        $debtEmployeeIds = Debt::where('store_id', $storeId)
            ->where('person_type', Employee::class)
            ->betweenOperationDates($periodStart, $periodEnd)
            ->pluck('person_id');

        $creditEmployeeIds = CreditSale::where('store_id', $storeId)
            ->where('person_type', Employee::class)
            ->betweenOperationDates($periodStart, $periodEnd)
            ->pluck('person_id');

        return $transferredAfterPeriodEmployeeIds
            ->merge($withdrawalEmployeeIds)
            ->merge($absenceEmployeeIds)
            ->merge($debtEmployeeIds)
            ->merge($creditEmployeeIds)
            ->map(fn ($employeeId) => (int) $employeeId)
            ->filter()
            ->unique()
            ->values();
    }
}
