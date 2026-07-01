<?php

namespace App\Services\Employees;

use App\Helpers\LogHelper;
use App\Models\Absence;
use App\Models\CreditSale;
use App\Models\Debt;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Withdrawal;
use App\Services\EmployeeLogService;
use App\Services\NotificationService;
use App\Services\ShiftLifecycleService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class EmployeeOperationService
{
    public function recordWithdrawal(Model $person, array $data, array $actor, array $options = []): Withdrawal
    {
        $description = $this->nullableDescription($data['description'] ?? null);
        $shiftContext = app(ShiftLifecycleService::class)->currentShiftContext($person->store_id, now());
        $operationDate = $this->operationDate($data['date'], $shiftContext, (bool) ($options['use_shift_gap_date'] ?? false));

        $exists = $person->withdrawals()
            ->whereDate('date', $operationDate->toDateString())
            ->where('amount', $data['amount'])
            ->where('description', $description)
            ->forAccountingDate($operationDate->toDateString())
            ->exists();

        if ($exists) {
            throw EmployeeOperationException::duplicate('لا يمكن تكرار نفس عملية السحب بنفس الوصف والقيمة في نفس اليوم.');
        }

        $withdrawal = $person->withdrawals()->create([
            'store_id' => $person->store_id,
            'person_id' => $person->id,
            'person_type' => get_class($person),
            'amount' => $data['amount'],
            'description' => $description,
            'date' => $operationDate->toDateString(),
            'status' => 'pending',
            'month' => $operationDate->format('Y-m'),
            'business_date' => $shiftContext['business_date'],
            'daily_balance_id' => $shiftContext['daily_balance_id'],
            'added_by' => $actor['id'] ?? null,
        ]);

        EmployeeLogService::add(
            $person,
            'withdrawal',
            "سحب مبلغ {$data['amount']} ريال",
            $data['amount'],
            $this->operationLogMeta($actor, 'operation')
        );

        LogHelper::add(
            'withdrawal',
            "قام {$actor['name']} بتسجيل سحب بقيمة {$data['amount']} ريال للموظف {$person->name}",
            $person->store_id
        );

        return $withdrawal;
    }

    public function recordAbsence(Model $person, array $data, array $actor, array $options = []): Absence
    {
        $description = $this->nullableDescription($data['description'] ?? null);
        $shiftContext = app(ShiftLifecycleService::class)->currentShiftContext($person->store_id, now());
        $operationDate = $this->operationDate($data['date'], $shiftContext, (bool) ($options['use_shift_gap_date'] ?? false));

        $exists = $person->absences()
            ->whereDate('date', $operationDate->toDateString())
            ->exists();

        if ($exists) {
            throw EmployeeOperationException::duplicate('تم تسجيل غياب لهذا المستخدم في هذا التاريخ مسبقًا');
        }

        $absence = $person->absences()->create([
            'store_id' => $person->store_id,
            'person_id' => $person->id,
            'person_type' => get_class($person),
            'date' => $operationDate->toDateString(),
            'description' => $description,
            'status' => 'pending',
            'month' => $operationDate->format('Y-m'),
            'created_at' => $operationDate->copy()->setTimeFrom(now()),
            'added_by' => $actor['id'] ?? null,
        ]);

        EmployeeLogService::add(
            $person,
            'absence',
            "تسجيل غياب بتاريخ {$operationDate->toDateString()}",
            null,
            $this->operationLogMeta($actor, 'operation')
        );

        LogHelper::add(
            'employee_absence',
            "قام {$actor['name']} بتسجيل غياب للموظف {$person->name} بتاريخ {$operationDate->toDateString()}",
            $person->store_id
        );

        if ((bool) ($options['notify_store_owner'] ?? false)) {
            NotificationService::sendTemplate('absence_recorded', [
                'sender_type' => 'CARLED',
                'target_type' => 'store',
                'target_ids' => [$person->store_id],
            ]);
        }

        return $absence;
    }



    public function recordDebt(Model $person, array $data, array $actor, array $options = []): Debt
    {
        $description = $this->nullableDescription($data['description'] ?? null);
        $operationContext = $this->resolveOperationContext(
            $person->store_id,
            $data['date'],
            (bool) ($options['use_shift_gap_date'] ?? false)
        );
        $operationDate = $operationContext['operation_date'];

        $exists = Debt::where('store_id', $person->store_id)
            ->where('person_id', $person->id)
            ->where('person_type', get_class($person))
            ->where('amount', $data['amount'])
            ->where('description', $description)
            ->forOperationDate($operationDate->toDateString())
            ->exists();

        if ($exists) {
            throw EmployeeOperationException::duplicate('تم تسجيل المديونية مسبقًا بنفس البيانات في تاريخ العملية.');
        }

        $debt = $person->debts()->create([
            'store_id' => $person->store_id,
            'person_id' => $person->id,
            'person_type' => get_class($person),
            'amount' => $data['amount'],
            'description' => $description,
            'date' => $operationDate->toDateString(),
            'type' => 'normal',
            'status' => 'pending',
            'month' => $operationDate->format('Y-m'),
            'created_at' => $operationDate->copy()->setTimeFrom(now()),
            'added_by' => $actor['id'] ?? null,
        ]);

        EmployeeLogService::add(
            $person,
            'debt',
            "تسجيل مديونية بقيمة {$data['amount']} ريال",
            $data['amount'],
            $this->operationLogMeta($actor, 'operation')
        );

        LogHelper::add(
            'employee_debt',
            "قام {$actor['name']} بتسجيل مديونية بقيمة {$data['amount']} ريال على الموظف {$person->name}",
            $person->store_id
        );

        if ((bool) ($options['notify_store_owner'] ?? false)) {
            $this->notifyStoreOwner($person, $actor, 'تسجيل مديونية', "قام {$actor['name']} بتسجيل مديونية بقيمة {$data['amount']} ريال على الموظف {$person->name}", 'debt_add');
        }

        return $debt;
    }

    public function recordCreditSale(Model $person, array $data, array $actor, array $options = []): CreditSale
    {
        $description = $this->nullableDescription($data['description'] ?? null);
        $operationContext = $this->resolveOperationContext(
            $person->store_id,
            $data['date'],
            (bool) ($options['use_shift_gap_date'] ?? false)
        );
        $operationDate = $operationContext['operation_date'];

        $exists = CreditSale::where('store_id', $person->store_id)
            ->where('person_id', $person->id)
            ->where('person_type', get_class($person))
            ->where('amount', $data['amount'])
            ->where('description', $description)
            ->forOperationDate($operationDate->toDateString())
            ->exists();

        if ($exists) {
            throw EmployeeOperationException::duplicate('تم تسجيل البيع الآجل مسبقًا بنفس البيانات في تاريخ العملية.');
        }

        $creditSale = $person->creditSales()->create([
            'store_id' => $person->store_id,
            'person_id' => $person->id,
            'person_type' => get_class($person),
            'amount' => $data['amount'],
            'remaining_amount' => $data['amount'],
            'partial_payments' => [],
            'description' => $description,
            'date' => $operationDate->toDateString(),
            'status' => 'pending',
            'month' => $operationDate->format('Y-m'),
            'added_by' => $actor['id'] ?? null,
        ]);

        EmployeeLogService::add(
            $person,
            'credit_sale',
            "تسجيل بيع آجل بقيمة {$data['amount']} ريال",
            $data['amount'],
            $this->operationLogMeta($actor, 'operation')
        );

        LogHelper::add(
            'credit_sale',
            "قام {$actor['name']} بتسجيل بيع آجل بقيمة {$data['amount']} ريال على الموظف {$person->name}",
            $person->store_id
        );

        return $creditSale;
    }


    public function collectDebt(Debt $debt, float $amount, array $actor, array $options = []): Debt
    {
        $person = $debt->person;
        if (! $person) {
            throw EmployeeOperationException::duplicate('لم يتم العثور على صاحب المديونية.');
        }

        if ($amount <= 0 || $amount > (float) $debt->amount) {
            throw EmployeeOperationException::duplicate('مبلغ التحصيل غير صالح.');
        }

        $operationContext = $this->resolveOperationContext(
            $person->store_id,
            $options['date'] ?? now()->toDateString(),
            (bool) ($options['use_shift_gap_date'] ?? false)
        );
        $operationDate = $operationContext['operation_date'];
        $description = (bool) ($options['full'] ?? false) ? 'تحصيل كامل' : 'تحصيل جزئي';

        $exists = Debt::where('store_id', $person->store_id)
            ->where('person_id', $person->id)
            ->where('person_type', get_class($person))
            ->where('amount', -$amount)
            ->where('description', $description)
            ->forOperationDate($operationDate->toDateString())
            ->exists();

        if ($exists) {
            throw EmployeeOperationException::duplicate('تم تسجيل هذا التحصيل مسبقًا في تاريخ العملية.');
        }

        $collectionDebt = $person->debts()->create([
            'store_id' => $person->store_id,
            'person_id' => $person->id,
            'person_type' => get_class($person),
            'amount' => -$amount,
            'description' => $description,
            'date' => $operationDate->toDateString(),
            'type' => 'normal',
            'status' => 'pending',
            'month' => $operationDate->format('Y-m'),
            'added_by' => $actor['id'] ?? null,
        ]);

        $remainingAmount = max(0, (float) $debt->amount - $amount);
        $debt->update([
            'amount' => $remainingAmount,
            'status' => $remainingAmount <= 0 ? 'cleared' : 'pending',
        ]);

        $actionName = (bool) ($options['full'] ?? false) ? 'debt_collect_full' : 'debt_collect_partial';
        EmployeeLogService::add(
            $person,
            $actionName,
            "{$description} بقيمة {$amount} ريال",
            $amount,
            $this->operationLogMeta($actor, 'operation')
        );

        LogHelper::add(
            'employee_' . $actionName,
            "قام {$actor['name']} بـ{$description} بقيمة {$amount} ريال من مديونية الموظف {$person->name}",
            $person->store_id
        );

        if ((bool) ($options['notify_store_owner'] ?? false)) {
            $this->notifyStoreOwner($person, $actor, $description . ' للمديونية', "قام {$actor['name']} بـ{$description} بقيمة {$amount} ريال من مديونية الموظف {$person->name}", $actionName);
        }

        return $collectionDebt;
    }

    public function collectCreditSale(CreditSale $creditSale, float $amount, array $actor, array $options = []): CreditSale
    {
        $person = $creditSale->person;
        if (! $person) {
            throw EmployeeOperationException::duplicate('لم يتم العثور على صاحب البيع الآجل.');
        }

        if ($creditSale->status === 'deducted') {
            throw EmployeeOperationException::duplicate('تم تحصيل البيع الآجل مسبقًا.');
        }

        if ($amount <= 0 || $amount > (float) $creditSale->remaining_amount) {
            throw EmployeeOperationException::duplicate('مبلغ التحصيل غير صالح.');
        }

        $remainingAmount = max(0, (float) $creditSale->remaining_amount - $amount);
        $payments = $creditSale->partial_payments ?? [];
        $payments[] = [
            'amount' => $amount,
            'date' => now()->toDateTimeString(),
            'added_by' => $actor['id'] ?? null,
            'added_by_name' => $actor['name'] ?? null,
            'description' => $remainingAmount == 0 ? 'تحصيل كامل' : 'تحصيل جزئي',
        ];

        $creditSale->remaining_amount = $remainingAmount;
        $creditSale->partial_payments = $payments;
        $creditSale->status = $remainingAmount == 0 ? 'deducted' : 'pending';
        $creditSale->deducted_month = $remainingAmount == 0 ? now()->format('Y-m') : $creditSale->deducted_month;
        $creditSale->save();
        $creditSale->syncLinkedSaleCollectionState();

        $actionName = $remainingAmount == 0 ? 'credit_sale_deducted' : 'credit_sale_partial';
        EmployeeLogService::add(
            $person,
            $actionName,
            ($remainingAmount == 0 ? 'تحصيل كامل بيع آجل' : 'تحصيل جزئي من بيع آجل') . " بقيمة {$amount} ريال",
            $amount,
            $this->operationLogMeta($actor, 'operation')
        );

        LogHelper::add(
            $actionName,
            "قام {$actor['name']} بتحصيل {$amount} ريال من بيع آجل للموظف {$person->name}",
            $person->store_id
        );

        return $creditSale;
    }

    public function resolveOperationContext(int $storeId, string $requestedDate, bool $useShiftGapDate = false): array
    {
        $shiftContext = app(ShiftLifecycleService::class)->currentShiftContext($storeId, now());

        return [
            'shift_context' => $shiftContext,
            'operation_date' => $this->operationDate($requestedDate, $shiftContext, $useShiftGapDate),
        ];
    }

    public function actorFromCurrentAuth(): array
    {
        $accountant = auth('accountant')->user();
        $user = auth()->user();

        return [
            'id' => $accountant?->id ?? $user?->id,
            'type' => $accountant ? 'accountant' : ($user?->role ?? 'system'),
            'name' => $accountant?->name ?? $user?->name ?? 'النظام',
        ];
    }


    private function notifyStoreOwner(Model $person, array $actor, string $title, string $message, string $templateKey): void
    {
        if (! $person instanceof Employee || ! $person->store?->user) {
            return;
        }

        Notification::create([
            'sender_id' => $actor['id'] ?? null,
            'sender_type' => $actor['type'] ?? 'system',
            'target_type' => 'user',
            'target_ids' => [$person->store->user->id],
            'title' => $title,
            'message' => $message,
            'template_key' => $templateKey,
            'channel' => 'CARLED',
        ]);
    }

    private function operationLogMeta(array $actor, string $type): array
    {
        return [
            'type' => $type,
            'actor_id' => $actor['id'] ?? null,
            'actor_type' => $actor['type'] ?? null,
            'actor_name' => $actor['name'] ?? 'النظام',
        ];
    }

    private function operationDate(string $requestedDate, array $shiftContext, bool $useShiftGapDate): Carbon
    {
        if ($useShiftGapDate && ($shiftContext['is_shift_gap_processing'] ?? false)) {
            return Carbon::parse($shiftContext['business_date']);
        }

        return Carbon::parse($requestedDate);
    }

    private function nullableDescription(?string $description): ?string
    {
        $description = trim((string) $description);

        return $description === '' ? null : $description;
    }
}
