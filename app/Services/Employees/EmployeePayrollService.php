<?php

namespace App\Services\Employees;

use App\Http\Controllers\Employees\EmployeeService;
use App\Models\Absence;
use App\Models\CreditSale;
use App\Models\Debt;
use App\Models\Employee;
use App\Models\Store;
use App\Models\Withdrawal;
use App\Services\Accounting\FinancialSummaryService;
use Illuminate\Support\Collection;

class EmployeePayrollService
{
    public function __construct(private readonly EmployeeHistoricalStoreService $historicalStores)
    {
    }

    public function monthlyRowsForStore(int $storeId, string $month, $periodStart, $periodEnd): Collection
    {
        $employees = $this->historicalStores->employeesForStoreDuringPeriod($storeId, $periodStart, $periodEnd);
        $employeeIds = $employees->pluck('id');

        $withdrawals = $this->withdrawalsByEmployee($storeId, $employeeIds, $periodStart, $periodEnd);
        $absences = $this->absencesByEmployee($storeId, $employeeIds, $periodStart, $periodEnd);
        $debts = $this->debtsByEmployee($storeId, $employeeIds, $periodStart, $periodEnd);
        $creditRemaining = $this->creditRemainingByEmployee($storeId, $employeeIds, $periodStart, $periodEnd);

        return $employees->map(function (Employee $employee) use ($month, $periodStart, $periodEnd, $withdrawals, $absences, $debts, $creditRemaining) {
            $withdrawalTotal = (float) ($withdrawals[$employee->id] ?? 0);
            $absenceDays = (int) ($absences[$employee->id] ?? 0);
            $debtTotal = (float) ($debts[$employee->id] ?? 0) + (float) ($creditRemaining[$employee->id] ?? 0);
            $salaryInfo = EmployeeService::calculateProratedSalaryForEmployee($employee, $periodStart, $periodEnd);
            $payableSalary = (float) $salaryInfo['payable_salary'];
            $dailySalary = ((float) $employee->salary) / max((int) $periodStart->daysInMonth, 1);
            $absencePenalty = $dailySalary * $absenceDays;
            $netSalary = max(0, $payableSalary - $withdrawalTotal - $absencePenalty - $debtTotal);

            return [
                'id' => $employee->id,
                'month' => $month,
                'name' => $employee->name,
                'base_salary' => (float) $employee->salary,
                'salary' => $payableSalary,
                'worked_days' => $salaryInfo['worked_days'],
                'suspended_days' => $salaryInfo['suspended_days'],
                'withdrawals' => $withdrawalTotal,
                'absences_count' => $absenceDays,
                'absence_penalty' => $absencePenalty,
                'debts' => $debtTotal,
                'net_salary' => $netSalary,
                'remaining' => $netSalary,
                'status' => $employee->trashed() ? 'محذوف' : ($employee->status ?? 'نشط'),
            ];
        });
    }

    public function proratedSalariesTotalForStore(int $storeId, $periodStart, $periodEnd): float
    {
        return $this->historicalStores
            ->employeesForStoreDuringPeriod($storeId, $periodStart, $periodEnd)
            ->sum(fn (Employee $employee) => EmployeeService::calculateProratedSalaryForEmployee($employee, $periodStart, $periodEnd)['payable_salary']);
    }

    public function salaryRowsForStores(Collection $storeIds, $periodStart, $periodEnd): Collection
    {
        $storeNames = Store::whereIn('id', $storeIds)->pluck('name', 'id');

        return $storeIds->flatMap(function ($storeId) use ($periodStart, $periodEnd, $storeNames) {
            $employees = $this->historicalStores->employeesForStoreDuringPeriod((int) $storeId, $periodStart, $periodEnd);
            $employeeIds = $employees->pluck('id');
            $withdrawals = $this->withdrawalsByEmployee((int) $storeId, $employeeIds, $periodStart, $periodEnd);
            $absenceDaysByEmployee = $this->absencesByEmployee((int) $storeId, $employeeIds, $periodStart, $periodEnd);

            return $employees->map(function (Employee $employee) use ($storeId, $periodStart, $periodEnd, $storeNames, $withdrawals, $absenceDaysByEmployee) {
                $salaryInfo = EmployeeService::calculateProratedSalaryForEmployee($employee, $periodStart, $periodEnd);
                $withdrawalsTotal = (float) ($withdrawals[$employee->id] ?? 0);
                $absenceDays = (int) ($absenceDaysByEmployee[$employee->id] ?? 0);
                $absenceDeduction = $absenceDays * (((float) $employee->salary) / max(1, $periodStart->daysInMonth));

                return (object) [
                    'id' => $employee->id,
                    'store_id' => (int) $storeId,
                    'name' => $employee->name,
                    'store_name' => $storeNames[$storeId] ?? null,
                    'base_salary' => (float) $employee->salary,
                    'salary' => $salaryInfo['payable_salary'],
                    'worked_days' => $salaryInfo['worked_days'],
                    'suspended_days' => $salaryInfo['suspended_days'],
                    'withdrawals_total' => $withdrawalsTotal,
                    'absence_days' => $absenceDays,
                    'absence_deduction' => $absenceDeduction,
                ];
            });
        })->values();
    }

    private function withdrawalsByEmployee(int $storeId, Collection $employeeIds, $periodStart, $periodEnd): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        $withdrawalsQuery = Withdrawal::where('store_id', $storeId)
            ->where('person_type', Employee::class)
            ->whereIn('person_id', $employeeIds);

        app(FinancialSummaryService::class)->applyAccountingPeriodToTable(
            $withdrawalsQuery,
            'employee_withdrawals',
            $periodStart,
            $periodEnd
        );

        return $withdrawalsQuery
            ->selectRaw('person_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('person_id')
            ->pluck('total', 'person_id');
    }

    private function absencesByEmployee(int $storeId, Collection $employeeIds, $periodStart, $periodEnd): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return Absence::where('store_id', $storeId)
            ->where('person_type', Employee::class)
            ->whereIn('person_id', $employeeIds)
            ->betweenOperationDates($periodStart, $periodEnd)
            ->selectRaw('person_id, COUNT(*) as count_total')
            ->groupBy('person_id')
            ->pluck('count_total', 'person_id');
    }

    private function debtsByEmployee(int $storeId, Collection $employeeIds, $periodStart, $periodEnd): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return Debt::where('store_id', $storeId)
            ->where('person_type', Employee::class)
            ->whereIn('person_id', $employeeIds)
            ->betweenOperationDates($periodStart, $periodEnd)
            ->selectRaw('person_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('person_id')
            ->pluck('total', 'person_id');
    }

    private function creditRemainingByEmployee(int $storeId, Collection $employeeIds, $periodStart, $periodEnd): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return CreditSale::where('store_id', $storeId)
            ->where('person_type', Employee::class)
            ->whereIn('person_id', $employeeIds)
            ->betweenOperationDates($periodStart, $periodEnd)
            ->selectRaw('person_id, COALESCE(SUM(remaining_amount), 0) as total')
            ->groupBy('person_id')
            ->pluck('total', 'person_id');
    }
}
