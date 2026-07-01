<?php

namespace App\Services\Employees;

class EmployeeActionsViewService
{
    public function viewData($person, string $returnTo): array
    {
        $operationSummary = $this->operationSummary($person);

        return [
            'employee' => $person,
            'returnTo' => $returnTo,
            'operationSummaryCards' => $this->operationSummaryCards($operationSummary),
            'actionCards' => $this->actionCards(),
            'recentLogs' => $person->logs()->latest()->take(5)->get(),
            'logActionMap' => $this->logActionMap(),
        ];
    }

    public function operationSummary($person): array
    {
        return [
            'withdrawals_total' => $this->relationSum($person, 'withdrawals', 'amount'),
            'debts_total' => $this->relationSum($person, 'debts', 'amount'),
            'credit_remaining_total' => $this->relationSum($person, 'creditSales', 'remaining_amount'),
            'absences_count' => $this->relationCount($person, 'absences'),
            'logs_count' => $this->relationCount($person, 'logs'),
        ];
    }

    public function operationSummaryCards(array $operationSummary): array
    {
        return [
            ['label' => 'إجمالي السحوبات', 'value' => number_format($operationSummary['withdrawals_total'] ?? 0, 2), 'suffix' => 'ريال', 'color' => 'text-sky-300'],
            ['label' => 'إجمالي المديونيات', 'value' => number_format($operationSummary['debts_total'] ?? 0, 2), 'suffix' => 'ريال', 'color' => 'text-rose-300'],
            ['label' => 'الآجل المتبقي', 'value' => number_format($operationSummary['credit_remaining_total'] ?? 0, 2), 'suffix' => 'ريال', 'color' => 'text-violet-300'],
            ['label' => 'أيام الغياب', 'value' => (int) ($operationSummary['absences_count'] ?? 0), 'suffix' => 'يوم', 'color' => 'text-amber-300'],
            ['label' => 'سجلات العمليات', 'value' => (int) ($operationSummary['logs_count'] ?? 0), 'suffix' => 'سجل', 'color' => 'text-emerald-300'],
        ];
    }

    public function actionCards(): array
    {
        return [
            ['modal' => 'employeeDetailsModal', 'title' => 'بيانات المستخدم', 'hint' => 'مراجعة ملف الموظف', 'icon' => 'fa-id-card', 'accent' => 'blue'],
            ['modal' => 'withdrawalModal', 'title' => 'سحب', 'hint' => 'تسجيل عملية سحب', 'icon' => 'fa-money-bill-transfer', 'accent' => 'sky'],
            ['modal' => 'absenceModal', 'title' => 'غياب', 'hint' => 'إضافة يوم غياب', 'icon' => 'fa-user-xmark', 'accent' => 'amber'],
            ['modal' => 'debtModal', 'title' => 'مديونية', 'hint' => 'تسجيل مديونية', 'icon' => 'fa-hand-holding-dollar', 'accent' => 'rose'],
            ['modal' => 'creditSaleModal', 'title' => 'بيع آجل', 'hint' => 'إنشاء عملية بيع آجل', 'icon' => 'fa-cart-shopping', 'accent' => 'violet'],
            ['modal' => 'creditSaleCollectionModal', 'title' => 'تحصيل', 'hint' => 'تحصيل من المديونية', 'icon' => 'fa-sack-dollar', 'accent' => 'emerald'],
        ];
    }

    public function logActionMap(): array
    {
        return [
            'withdraw' => ['label' => 'سحب نقدي', 'color' => 'text-blue-400', 'icon' => 'fa-money-bill-transfer'],
            'absence' => ['label' => 'غياب', 'color' => 'text-yellow-400', 'icon' => 'fa-user-xmark'],
            'debt' => ['label' => 'مديونية', 'color' => 'text-red-400', 'icon' => 'fa-circle-exclamation'],
            'collect' => ['label' => 'تحصيل مديونية', 'color' => 'text-green-400', 'icon' => 'fa-hand-holding-dollar'],
            'sale_credit' => ['label' => 'بيع آجل', 'color' => 'text-purple-400', 'icon' => 'fa-file-invoice-dollar'],
            'store_transfer' => ['label' => 'نقل بين المتاجر', 'color' => 'text-indigo-400', 'icon' => 'fa-right-left'],
            'salary_update' => ['label' => 'تعديل راتب', 'color' => 'text-gray-400', 'icon' => 'fa-sack-dollar'],
        ];
    }

    private function relationSum($person, string $relation, string $column): float
    {
        return method_exists($person, $relation) ? (float) $person->{$relation}()->sum($column) : 0.0;
    }

    private function relationCount($person, string $relation): int
    {
        return method_exists($person, $relation) ? (int) $person->{$relation}()->count() : 0;
    }
}
