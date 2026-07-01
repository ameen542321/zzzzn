<?php

namespace App\Services\Employees;

use App\Http\Controllers\Employees\EmployeeService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class EmployeeActionsViewService
{
    public function viewData(Model $person, string $returnTo, ?string $month = null): array
    {
        $selectedMonth = $this->normalizeMonth($month);
        [$periodStart, $periodEnd] = $this->monthBounds($selectedMonth);
        $operationDetails = $this->operationDetails($person, $periodStart, $periodEnd);
        $operationSummary = $this->operationSummary($person, $operationDetails, $periodStart, $periodEnd);

        return [
            'employee' => $person,
            'returnTo' => $returnTo,
            'selectedMonth' => $selectedMonth,
            'operationSummaryCards' => $this->operationSummaryCards($operationSummary),
            'operationDetails' => $operationDetails,
            'actionCards' => $this->actionCards($person),
            'recentLogs' => $this->paginatedLogs($person, $periodStart, $periodEnd),
            'logActionMap' => $this->logActionMap(),
        ];
    }

    public function operationSummary(Model $person, array $details, Carbon $periodStart, Carbon $periodEnd): array
    {
        $salaryInfo = method_exists($person, 'trashed')
            ? EmployeeService::calculateProratedSalaryForEmployee($person, $periodStart, $periodEnd)
            : ['payable_salary' => (float) ($person->salary ?? 0)];

        return [
            'withdrawals_total' => $details['withdrawals']->sum('amount'),
            'salary_payable' => (float) ($salaryInfo['payable_salary'] ?? 0),
            'debts_total' => $details['debts']->where('amount', '>', 0)->sum('amount'),
            'debt_collections_total' => abs((float) $details['debts']->where('amount', '<', 0)->sum('amount')),
            'credit_remaining_total' => $details['credit_sales']->sum('remaining_amount'),
            'credit_sales_total' => $details['credit_sales']->sum('amount'),
            'absences_count' => $details['absences']->count(),
        ];
    }

    public function operationSummaryCards(array $operationSummary): array
    {
        return [
            [
                'modal' => 'withdrawalsDetailsModal',
                'label' => 'إجمالي السحوبات',
                'value' => number_format($operationSummary['withdrawals_total'] ?? 0, 2),
                'suffix' => 'ريال',
                'color' => 'text-sky-300',
                'hint' => 'الراتب المستحق: ' . number_format($operationSummary['salary_payable'] ?? 0, 2) . ' ريال',
            ],
            [
                'modal' => 'debtsDetailsModal',
                'label' => 'إجمالي المديونيات',
                'value' => number_format($operationSummary['debts_total'] ?? 0, 2),
                'suffix' => 'ريال',
                'color' => 'text-rose-300',
                'hint' => 'التحصيلات: ' . number_format($operationSummary['debt_collections_total'] ?? 0, 2) . ' ريال',
            ],
            [
                'modal' => 'creditSalesDetailsModal',
                'label' => 'الآجل المتبقي',
                'value' => number_format($operationSummary['credit_remaining_total'] ?? 0, 2),
                'suffix' => 'ريال',
                'color' => 'text-violet-300',
                'hint' => 'إجمالي الآجل: ' . number_format($operationSummary['credit_sales_total'] ?? 0, 2) . ' ريال',
            ],
            [
                'modal' => 'absencesDetailsModal',
                'label' => 'أيام الغياب',
                'value' => (int) ($operationSummary['absences_count'] ?? 0),
                'suffix' => 'يوم',
                'color' => 'text-amber-300',
                'hint' => 'تفاصيل الغياب للشهر المحدد',
            ],
        ];
    }

    public function actionCards(Model $person): array
    {
        return [
            ['modal' => 'withdrawalModal', 'title' => 'سحب', 'hint' => 'تسجيل عملية سحب', 'icon' => 'fa-money-bill-transfer', 'accent' => 'sky', 'type' => 'modal'],
            ['modal' => 'absenceModal', 'title' => 'غياب', 'hint' => 'إضافة يوم غياب', 'icon' => 'fa-user-xmark', 'accent' => 'amber', 'type' => 'modal'],
            ['modal' => 'debtModal', 'title' => 'مديونية', 'hint' => 'تسجيل مديونية', 'icon' => 'fa-hand-holding-dollar', 'accent' => 'rose', 'type' => 'modal'],
            ['modal' => 'creditSaleModal', 'title' => 'بيع آجل', 'hint' => 'إنشاء عملية بيع آجل', 'icon' => 'fa-cart-shopping', 'accent' => 'violet', 'type' => 'modal'],
            ['modal' => 'creditSaleCollectionModal', 'title' => 'تحصيل', 'hint' => 'تحصيل من المديونية', 'icon' => 'fa-sack-dollar', 'accent' => 'emerald', 'type' => 'modal'],
            ['url' => route('user.employees.exportLog', $person->id), 'title' => 'تصدير بيانات الموظف', 'hint' => 'PDF قراءة فقط دون تصفير', 'icon' => 'fa-file-pdf', 'accent' => 'red', 'type' => 'link'],
            ['url' => route('user.employees.edit', $person->id), 'title' => 'تعديل ملف الموظف', 'hint' => 'تحديث بيانات الموظف', 'icon' => 'fa-user-pen', 'accent' => 'indigo', 'type' => 'link'],
        ];
    }

    public function logActionMap(): array
    {
        return [
            'withdrawal' => ['label' => 'سحب نقدي', 'color' => 'text-blue-400', 'icon' => 'fa-money-bill-transfer'],
            'absence' => ['label' => 'غياب', 'color' => 'text-yellow-400', 'icon' => 'fa-user-xmark'],
            'debt' => ['label' => 'مديونية', 'color' => 'text-red-400', 'icon' => 'fa-circle-exclamation'],
            'debt_collect_full' => ['label' => 'تحصيل مديونية كامل', 'color' => 'text-green-400', 'icon' => 'fa-hand-holding-dollar'],
            'debt_collect_partial' => ['label' => 'تحصيل مديونية جزئي', 'color' => 'text-green-400', 'icon' => 'fa-hand-holding-dollar'],
            'credit_sale' => ['label' => 'بيع آجل', 'color' => 'text-purple-400', 'icon' => 'fa-file-invoice-dollar'],
            'credit_sale_deducted' => ['label' => 'تحصيل بيع آجل كامل', 'color' => 'text-emerald-400', 'icon' => 'fa-sack-dollar'],
            'credit_sale_partial' => ['label' => 'تحصيل بيع آجل جزئي', 'color' => 'text-emerald-400', 'icon' => 'fa-sack-dollar'],
            'store_transfer' => ['label' => 'نقل بين المتاجر', 'color' => 'text-indigo-400', 'icon' => 'fa-right-left'],
            'salary_update' => ['label' => 'تعديل راتب', 'color' => 'text-gray-400', 'icon' => 'fa-sack-dollar'],
            'report_exported' => ['label' => 'تصدير تقرير', 'color' => 'text-red-400', 'icon' => 'fa-file-pdf'],
        ];
    }

    private function operationDetails(Model $person, Carbon $periodStart, Carbon $periodEnd): array
    {
        return [
            'withdrawals' => $person->withdrawals()
                ->with('addedBy:id,name')
                ->betweenAccountingDates($periodStart, $periodEnd)
                ->orderBy('date')
                ->get(),
            'debts' => $person->debts()
                ->with('addedBy:id,name')
                ->betweenOperationDates($periodStart, $periodEnd)
                ->orderBy('date')
                ->get(),
            'credit_sales' => $person->creditSales()
                ->with('addedBy:id,name')
                ->betweenOperationDates($periodStart, $periodEnd)
                ->orderBy('date')
                ->get(),
            'absences' => $person->absences()
                ->with('addedBy:id,name')
                ->betweenOperationDates($periodStart, $periodEnd)
                ->orderBy('date')
                ->get(),
        ];
    }

    private function paginatedLogs(Model $person, Carbon $periodStart, Carbon $periodEnd)
    {
        return $person->logs()
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->latest()
            ->paginate(10, ['*'], 'logs_page')
            ->appends(['month' => $periodStart->format('Y-m')]);
    }

    private function normalizeMonth(?string $month): string
    {
        return preg_match('/^\d{4}-\d{2}$/', (string) $month) === 1
            ? $month
            : now()->format('Y-m');
    }

    private function monthBounds(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }
}
