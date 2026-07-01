<?php

namespace App\Http\Controllers;

use App\Helpers\LogHelper;
use App\Models\Debt;
use App\Models\Employee;
use App\Models\Accountant;
use App\Models\CreditSale;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Services\EmployeeLogService;
use App\Services\Employees\EmployeeOperationException;
use App\Services\Employees\EmployeeOperationService;

class EmployeeActionsController extends Controller
{
    /**
     * عرض صفحة العمليات
     */
    public function index($id)
    {
        $person = $this->findPerson($id);
        $this->authorizePerson($person);

        $returnTo = $this->safeReturnTo(request()->query('return_to')) ?? route('user.employees.index');

        return view('employees.actions', [
            'employee' => $person,
            'returnTo' => $returnTo,
            'operationSummary' => $this->operationSummary($person),
        ]);
    }

    /**
     * حفظ عملية السحب
     */
    public function storeWithdrawal(Request $request, $id)
    {
        $person = $this->findPerson($id);
        $this->authorizePerson($person);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'description' => 'nullable|string|max:255',
        ]);

        $employeeOperationService = app(EmployeeOperationService::class);

        try {
            $employeeOperationService->recordWithdrawal(
                $person,
                $validated,
                $employeeOperationService->actorFromCurrentAuth()
            );
        } catch (EmployeeOperationException $exception) {
            return back()->withErrors(['duplicate' => $exception->getMessage()]);
        }

        return back()->with('success', 'تم إضافة السحب بنجاح');
    }


    /**
     * حفظ عملية الغياب
     */
    public function storeAbsence(Request $request, $id)
    {
        $person = $this->findPerson($id);
        $this->authorizePerson($person);

        $validated = $request->validate([
            'date' => 'required|date',
            'description' => 'nullable|string|max:255',
        ]);

        $employeeOperationService = app(EmployeeOperationService::class);

        try {
            $employeeOperationService->recordAbsence(
                $person,
                $validated,
                $employeeOperationService->actorFromCurrentAuth(),
                ['notify_store_owner' => auth('accountant')->check()]
            );
        } catch (EmployeeOperationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم تسجيل الغياب بنجاح');
    }

    /**
     * حفظ المديونية
     */
public function storeDebt(Request $request, $id)
{
    $person = $this->findPerson($id);
    $this->authorizePerson($person);

    $validated = $request->validate([
        'amount'      => 'required|numeric|min:0.01',
        'description' => 'nullable|string|max:255',
        'date'        => 'required|date',
    ]);

    $employeeOperationService = app(EmployeeOperationService::class);

    try {
        $employeeOperationService->recordDebt(
            $person,
            $validated,
            $employeeOperationService->actorFromCurrentAuth(),
            ['notify_store_owner' => auth('accountant')->check()]
        );
    } catch (EmployeeOperationException $exception) {
        return back()->withErrors(['duplicate' => $exception->getMessage()]);
    }

    return back()->with('success', 'تم إضافة المديونية بنجاح');
}


public function collectPartial($debtId, $amount)
{
    $debt = Debt::findOrFail($debtId);
    $person = $this->authorizeDebtAccess($debt);

    try {
        app(EmployeeOperationService::class)->collectDebt(
            $debt,
            (float) $amount,
            app(EmployeeOperationService::class)->actorFromCurrentAuth()
        );
    } catch (EmployeeOperationException $exception) {
        return back()->with('error', $exception->getMessage());
    }

    return back()->with('success', 'تم التحصيل الجزئي بنجاح');
}

// دالة إنشاء بيع آجل جديد
public function storeCreditSale(Request $request, $employeeId)
{
    $person = $this->findPerson($employeeId);
    $this->authorizePerson($person);

    $validated = $request->validate([
        'amount'      => 'required|numeric|min:1',
        'description' => 'nullable|string|max:255',
        'date'        => 'required|date',
    ]);

    $employeeOperationService = app(EmployeeOperationService::class);

    try {
        $employeeOperationService->recordCreditSale(
            $person,
            $validated,
            $employeeOperationService->actorFromCurrentAuth()
        );
    } catch (EmployeeOperationException $exception) {
        return back()->withErrors(['duplicate' => $exception->getMessage()]);
    }

    return back()->with('success', 'تم إنشاء عملية بيع آجل بنجاح');
}

// دالة التحصيل الكامل
public function collectCreditSale($employeeId, CreditSale $sale)
{
    $person = $this->findPerson($employeeId);
    $this->authorizePerson($person);

    if ($sale->person_id !== $person->id || $sale->person_type !== get_class($person)) {
        abort(403, 'غير مسموح');
    }

    try {
        app(EmployeeOperationService::class)->collectCreditSale(
            $sale,
            (float) $sale->remaining_amount,
            app(EmployeeOperationService::class)->actorFromCurrentAuth(),
            ['full' => true]
        );
    } catch (EmployeeOperationException $exception) {
        return back()->with('error', $exception->getMessage());
    }

    return back()->with('success', 'تم التحصيل الكامل بنجاح');
}



public function collectFull($debtId)
{
    $debt = Debt::findOrFail($debtId);
    $this->authorizeDebtAccess($debt);

    if ($debt->amount <= 0) {
        return back()->with('error', 'لا توجد مديونية لتسديدها.');
    }

    try {
        app(EmployeeOperationService::class)->collectDebt(
            $debt,
            (float) $debt->amount,
            app(EmployeeOperationService::class)->actorFromCurrentAuth(),
            ['full' => true]
        );
    } catch (EmployeeOperationException $exception) {
        return back()->with('error', $exception->getMessage());
    }

    return back()->with('success', 'تم التحصيل الكامل بنجاح');
}


public function collectDebt(Request $request, $id)
{
    $person = $this->findPerson($id);
    $this->authorizePerson($person);

    $actorName = auth('accountant')->user()?->name ?? auth()->user()?->name ?? 'النظام';

    $request->validate([
        'amount'      => 'required|numeric|min:0.01',
        'description' => 'nullable|string|max:255',
        'date'        => 'required|date',
        'mode'        => 'required|in:partial,full',
    ]);

    $month = date('Y-m', strtotime($request->date));
    $currentBalance = $person->debts()->sum('amount');

    if ($currentBalance <= 0) {
        return back()->withErrors(['amount' => 'لا توجد مديونية حالية على هذا الموظف.']);
    }

    // ================================
    // 🔥 منع التكرار خلال يوم كامل
    // ================================
    $exists = $person->debts()
        ->whereDate('date', $request->date)
        ->where('amount', -($request->mode === 'full' ? $currentBalance : min($request->amount, $currentBalance)))
        ->where('description', $request->description ?: 'تحصيل مديونية')
        ->exists();

    if ($exists) {
        return back()->withErrors([
            'duplicate' => 'لا يمكن تكرار نفس عملية التحصيل بنفس الوصف والقيمة في نفس اليوم.'
        ]);
    }

    // ================================
    // حساب مبلغ التحصيل
    // ================================
    $collectAmount = $request->mode === 'full'
        ? $currentBalance
        : min($request->amount, $currentBalance);

    $person->debts()->create([
        'store_id'     => $person->store_id,
        'amount'       => -$collectAmount,
        'description'  => $request->description ?: 'تحصيل مديونية',
        'date'         => $request->date,
        'type'         => 'normal',
        'status'       => 'pending',
        'month'        => $month,
        'added_by'     => auth()->id(),
    ]);

    EmployeeLogService::add(
        $person,
        'debt_collect',
        "تحصيل مديونية بقيمة {$collectAmount} ريال"
    );

    LogHelper::add(
        'debt_collect',
        "قام {$actorName} بتحصيل مديونية بقيمة {$collectAmount} ريال للموظف {$person->name}",
        $person->store_id
    );

    // 🔥 إشعار داخلي
    $message = $request->mode === 'full'
        ? "قام المحاسب بتحصيل كامل مديونية الموظف {$person->name} بقيمة {$collectAmount} ريال"
        : "قام المحاسب بتحصيل مبلغ جزئي بقيمة {$collectAmount} ريال من مديونية الموظف {$person->name}";

    $this->notifyStoreOwnerForInternalOps(
        $person,
        'تحصيل مديونية',
        $message,
        'debt_collect'
    );

    return back()->with('success', 'تم تحصيل المديونية بنجاح');
}

public function collectPartialCreditSale($employeeId, CreditSale $sale, $amount)
{
    $person = $this->findPerson($employeeId);
    $this->authorizePerson($person);

    if ($sale->person_id !== $person->id || $sale->person_type !== get_class($person)) {
        abort(403, 'غير مسموح');
    }

    try {
        app(EmployeeOperationService::class)->collectCreditSale(
            $sale,
            (float) $amount,
            app(EmployeeOperationService::class)->actorFromCurrentAuth()
        );
    } catch (EmployeeOperationException $exception) {
        return back()->with('error', $exception->getMessage());
    }

    return back()->with('success', 'تم التحصيل الجزئي بنجاح');
}


    private function authorizeDebtAccess(Debt $debt)
    {
        $person = $debt->person;

        if (!$person) {
            abort(404, 'لم يتم العثور على صاحب المديونية');
        }

        $this->authorizePerson($person);

        return $person;
    }

    /**
     * صفحة السجل
     */
    public function logs($id)
    {
        $person = $this->findPerson($id);
        $this->authorizePerson($person);

        $logs = $person->logs()
            ->orderBy('created_at', 'desc')
            ->get();

        $returnTo = $this->safeReturnTo(request()->query('return_to'))
            ?? route('user.employees.show', ['employee' => $person->id]);

        return view('employees.logs', [
            'employee' => $person,
            'logs'     => $logs,
            'returnTo' => $returnTo,
        ]);
    }



    private function operationSummary($person): array
    {
        return [
            'withdrawals_total' => $this->relationSum($person, 'withdrawals', 'amount'),
            'debts_total' => $this->relationSum($person, 'debts', 'amount'),
            'credit_remaining_total' => $this->relationSum($person, 'creditSales', 'remaining_amount'),
            'absences_count' => $this->relationCount($person, 'absences'),
            'logs_count' => $this->relationCount($person, 'logs'),
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

    private function notifyStoreOwnerForInternalOps($person, string $title, string $message, ?string $templateKey = null): void
    {
        if (!auth('accountant')->check()) {
            return;
        }

        $accountant = auth('accountant')->user();

        Notification::create([
            'sender_id'    => $accountant->id,
            'sender_type'  => 'accountant',
            'target_type'  => 'store',
            'target_ids'   => [$person->store_id],
            'title'        => $title,
            'message'      => $message,
            'template_key' => $templateKey,
            'channel'      => 'CARLED',
        ]);
    }

    private function safeReturnTo(?string $returnTo): ?string
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

    /**
     * إيجاد موظف أو محاسب
     */
    private function findPerson($id)
    {
        return Employee::find($id) ?? Accountant::findOrFail($id);
    }

    /**
     * حماية المستخدم حسب المتجر
     */
   private function authorizePerson($person)
{
    $user = auth()->user();
/** @var \App\Models\User $user */
    // المالك: يجب أن يكون المتجر تابعاً له حصراً
    if (auth('web')->check() && $user->role === 'user') {
        if (!$user->stores()->where('id', $person->store_id)->exists()) {
            abort(403, 'هذا الموظف لا ينتمي لمتاجرك');
        }
        return;
    }

    // المحاسب: يجب أن يكون في نفس المتجر
    if (auth('accountant')->check()) {
        if ($person->store_id !== auth('accountant')->user()->store_id) {
            abort(403, 'لا يمكنك إدارة موظفين خارج متجرك');
        }
        return;
    }

    // الأدمن له صلاحية كاملة تلقائياً
    if ($user && $user->role === 'admin') return;

    abort(403, 'غير مسموح');
}
}
