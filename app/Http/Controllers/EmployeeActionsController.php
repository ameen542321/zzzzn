<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\Employee;
use App\Models\Accountant;
use App\Models\CreditSale;
use Illuminate\Http\Request;
use App\Services\Employees\EmployeeActionsViewService;
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

        return view('employees.actions', app(EmployeeActionsViewService::class)->viewData($person, $returnTo, request()->query('month')));
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
