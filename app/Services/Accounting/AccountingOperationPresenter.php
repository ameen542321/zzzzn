<?php

namespace App\Services\Accounting;

use App\Models\Accountant;
use App\Models\Employee;
use App\Support\PaymentTypeLabel;
use Illuminate\Support\Str;

class AccountingOperationPresenter
{
    public function lastOperation(object $operation, string $operationType): object
    {
        $employeeName = $this->employeeNameForLastOperation($operation, $operationType);
        $actorName = $this->executorName($operation) ?: ($employeeName !== '--' ? $employeeName : null);
        $description = $operation->description ?? $operation->reason ?? $operation->note ?? ($actorName ?: 'غير محدد');

        if (in_array($operationType, ['expense', 'withdrawal'], true) && empty($operation->description)) {
            $description = $actorName ?: 'غير محدد';
        }

        $amount = $operation->amount ?? 0;

        if ($operationType === 'sale') {
            $saleDescription = trim((string) $operation->description);
            $description = $this->saleOperationLabel($operation, $saleDescription);
            $amount = (float) ($operation->paid_amount ?? 0);
            $employeeName = $operation->accountant?->name ?: ($employeeName !== '--' ? $employeeName : 'المحاسب');
        } elseif (in_array($operationType, ['expense', 'withdrawal'], true) && ($employeeName === '--' || $employeeName === 'نظام')) {
            $employeeName = $actorName && $actorName !== '--' ? $actorName : ($operationType === 'expense' ? 'منفذ المصروف' : 'منفذ السحب');
        }

        return (object) [
            'type' => $operationType,
            'employee' => $employeeName,
            'description' => Str::limit($description, 30),
            'amount' => $amount,
            'created_at' => $operation->created_at,
            'formatted_time' => optional($operation->created_at)->format('h:i A') ?? '--',
        ];
    }

    public function saleDetail(object $sale): array
    {
        $description = trim((string) ($sale->description ?: $sale->internal_notes));
        $operationType = $this->saleOperationType($sale, $description);

        return [
            'time' => $sale->created_at,
            'operation_type' => $operationType['type'],
            'product' => $operationType['label'],
            'amount' => (float) ($sale->paid_amount ?? 0),
            'cost_amount' => (float) max(((float) $sale->products_total + (float) $sale->labor_total) - (float) $sale->profit, 0),
            'cash_amount' => (float) ($sale->sale_type === 'cash'
                ? $sale->paid_amount
                : ($sale->sale_type === 'mixed' ? $sale->cash_amount : 0)),
            'card_amount' => (float) ($sale->sale_type === 'card'
                ? $sale->paid_amount
                : ($sale->sale_type === 'mixed' ? $sale->card_amount : 0)),
            'payment_type' => PaymentTypeLabel::dashboardLabel($sale->sale_type),
            'note' => $description ?: null,
            'actor' => $sale->accountant?->name ?: 'المحاسب',
        ];
    }

    public function creditCollectionDetail(array $collection): array
    {
        return [
            'time' => \Carbon\Carbon::parse($collection['collection_date'] ?? now()),
            'operation_type' => 'تحصيل',
            'product' => $collection['employee_name'] ?? 'تحصيل آجل',
            'amount' => (float) ($collection['collected_amount'] ?? 0),
            'cash_amount' => (float) ($collection['collected_amount'] ?? 0),
            'card_amount' => 0,
            'payment_type' => 'تحصيل',
            'note' => $collection['description'] ?? null,
            'actor' => $collection['employee_name'] ?? 'تحصيل آجل',
        ];
    }

    public function expenseDetail(object $expense): array
    {
        return [
            'time' => $expense->created_at,
            'operation_type' => 'مصروف',
            'product' => $expense->description ?: optional($expense->employee)->name ?: optional($expense->user)->name ?: $expense->type ?: 'مصروف',
            'amount' => (float) $expense->amount,
            'cash_amount' => 0,
            'card_amount' => 0,
            'payment_type' => 'مصروف',
            'note' => $expense->description,
            'actor' => optional($expense->user)->name ?: optional($expense->employee)->name ?: 'منفذ المصروف',
        ];
    }

    public function withdrawalDetail(object $withdrawal): array
    {
        return [
            'time' => $withdrawal->created_at,
            'operation_type' => 'سحب',
            'product' => $withdrawal->description ?: optional($withdrawal->person)->name ?: 'سحب',
            'amount' => (float) $withdrawal->amount,
            'cash_amount' => 0,
            'card_amount' => 0,
            'payment_type' => 'سحب',
            'note' => $withdrawal->description,
            'actor' => optional($withdrawal->addedBy)->name ?: optional($withdrawal->person)->name ?: 'منفذ السحب',
        ];
    }

    public function debtDetail(object $debt): array
    {
        return [
            'time' => $debt->created_at,
            'operation_type' => 'مديونية',
            'product' => $debt->description ?: 'مديونية',
            'amount' => (float) $debt->amount,
            'cash_amount' => 0,
            'card_amount' => 0,
            'payment_type' => 'مديونية',
            'note' => $debt->description,
        ];
    }

    private function employeeNameForLastOperation(object $operation, string $operationType): string
    {
        $personId = $operationType === 'withdrawal'
            ? ($operation->person_id ?? null)
            : ($operation->employee_id ?? null);

        if (! $personId) {
            return '--';
        }

        $relation = $operationType === 'withdrawal' ? 'person' : 'employee';
        if (method_exists($operation, $relation) && $operation->{$relation}) {
            return $operation->{$relation}->name ?? '--';
        }

        $employee = Employee::find($personId);

        return $employee ? $employee->name : 'موظف #' . $personId;
    }

    private function executorName(object $operation): ?string
    {
        if ($operation->accountant?->name) {
            return $operation->accountant->name;
        }

        if ($operation->user?->name) {
            return $operation->user->name;
        }

        if ($operation->addedBy?->name) {
            return $operation->addedBy->name;
        }

        if (! empty($operation->added_by)) {
            $accountant = Accountant::find($operation->added_by);
            if ($accountant?->name) {
                return $accountant->name;
            }
        }

        if ($operation->employee?->name) {
            return $operation->employee->name;
        }

        return null;
    }

    private function saleOperationType(object $sale, string $description): array
    {
        $productNames = collect($sale->items ?? [])
            ->map(fn ($item) => optional($item->product)->name)
            ->filter()
            ->unique()
            ->values();

        if ($this->isTintOperation($description)) {
            return ['type' => 'تضليل', 'label' => $description];
        }

        if ($productNames->isNotEmpty()) {
            return ['type' => 'بيع منتجات', 'label' => $productNames->implode(' - ')];
        }

        if ((float) $sale->labor_total > 0) {
            return ['type' => 'شغل يد', 'label' => $description ?: 'شغل يد'];
        }

        return ['type' => 'عملية بيع', 'label' => $description ?: 'عملية بدون منتجات'];
    }

    private function saleOperationLabel(object $sale, string $description): string
    {
        return $this->saleOperationType($sale, $description)['label'] ?: 'عملية بيع بدون منتجات';
    }

    private function isTintOperation(string $description): bool
    {
        return mb_stripos($description, 'تضليل') !== false
            || mb_stripos($description, 'تظليل') !== false;
    }
}
