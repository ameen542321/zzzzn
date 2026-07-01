<?php

namespace App\Http\Controllers\Store;
use App\Models\Debt;
use App\Models\Absence;
use App\Models\Employee;
use App\Models\CreditSale;
use App\Models\Withdrawal;
use App\Models\EmployeeLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Http\Controllers\Controller;
use App\Services\Employees\EmployeeOperationException;
use App\Services\Employees\EmployeeOperationService;

class EmployeeFinanceController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Helpers: findPerson + authorizePerson
    |--------------------------------------------------------------------------
    */
    private function findPerson($id)
    {
        return Employee::findOrFail($id);
    }

    private function authorizePerson($person)
    {
        $accountant = auth('accountant')->user();

        if ($person->store_id !== $accountant->store_id) {
            abort(403, 'غير مسموح');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 2) تنفيذ عملية السحب (مع منع التكرار)
    |--------------------------------------------------------------------------
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
                $employeeOperationService->actorFromCurrentAuth(),
                ['use_shift_gap_date' => true]
            );
        } catch (EmployeeOperationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم إضافة السحب بنجاح');
    }



    /*
    |--------------------------------------------------------------------------
    | 3) تنفيذ عملية الغياب (جاهزة مسبقًا)
    |--------------------------------------------------------------------------
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
                ['use_shift_gap_date' => true]
            );
        } catch (EmployeeOperationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم تسجيل الغياب بنجاح');
    }



    // /*
    // |--------------------------------------------------------------------------
    // | 4) تنفيذ عملية المصروف (مع منع التكرار)
    // |--------------------------------------------------------------------------
    // */
    // public function storeExpense(Request $request, $id)
    // {
    //     $person = $this->findPerson($id);
    //     $this->authorizePerson($person);

    //     $request->validate([
    //         'amount'      => 'required|numeric|min:0.01',
    //         'description' => 'required|string|max:255',
    //         'date'        => 'required|date',
    //     ]);

    //     // 🔥 منع التكرار
    //     $exists = Expense::where('store_id', $person->store_id)
    //         ->where('person_id', $person->id)
    //         ->where('amount', $request->amount)
    //         ->where('description', $request->description)
    //         ->whereDate('date', $request->date)
    //         ->exists();

    //     if ($exists) {
    //         return back()->with('error', 'تم تسجيل المصروف مسبقًا بنفس البيانات');
    //     }

    //     $accountant = auth('accountant')->user();

    //     $person->expenses()->create([
    //         'store_id'    => $person->store_id,
    //         'person_id'   => $person->id,
    //         'person_type' => Employee::class,
    //         'amount'      => $request->amount,
    //         'description' => $request->description,
    //         'date'        => $request->date,
    //         'month'       => date('Y-m'),
    //         'added_by'    => $accountant->id,
    //         'type'        => 'employee_expense',
    //     ]);

    //     EmployeeLogService::add(
    //         $person,
    //         'expense',
    //         "مصروف بقيمة {$request->amount} ريال",
    //         $request->amount,
    //         'operation'
    //     );

    //     return back()->with('success', 'تم إضافة المصروف بنجاح');
    // }

    /*
    |--------------------------------------------------------------------------
    | 5) تنفيذ عملية البيع الآجل (مع منع التكرار)
    |--------------------------------------------------------------------------
    */
 public function storeCreditSale(Request $request, $id)
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
        $employeeOperationService->recordCreditSale(
            $person,
            $validated,
            $employeeOperationService->actorFromCurrentAuth(),
            ['use_shift_gap_date' => true]
        );
    } catch (EmployeeOperationException $exception) {
        return back()->with('error', $exception->getMessage());
    }

    return back()->with('success', 'تم تسجيل البيع الآجل بنجاح');
}



    /*
    |--------------------------------------------------------------------------
    | 6) تنفيذ عملية التحصيل (مع منع التكرار)
    |--------------------------------------------------------------------------
    */
public function storeCollection(Request $request, $saleId)
{
    $accountant = auth('accountant')->user();

    $sale = CreditSale::where('store_id', $accountant->store_id)
        ->where('id', $saleId)
        ->firstOrFail();

    $person = $sale->person;

    if ($person->id == $accountant->employee_id) {
        return back()->with('error', 'غير مصرح لك بتحصيل البيع الآجل الخاص بك.');
    }

    $amount = $request->has('amount') ? (float) $request->amount : (float) $sale->remaining_amount;

    try {
        app(EmployeeOperationService::class)->collectCreditSale(
            $sale,
            $amount,
            app(EmployeeOperationService::class)->actorFromCurrentAuth(),
            ['full' => ! $request->has('amount')]
        );
    } catch (EmployeeOperationException $exception) {
        return $request->has('amount')
            ? response($exception->getMessage(), 422)
            : back()->with('error', $exception->getMessage());
    }

    return $request->has('amount')
        ? response()->noContent()
        : back()->with('success', 'تم تحصيل البيع الآجل بنجاح');
}






    /*
    |--------------------------------------------------------------------------
    | 7) تنفيذ عملية المديونية (مع منع التكرار)
    |--------------------------------------------------------------------------
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
            ['use_shift_gap_date' => true, 'notify_store_owner' => true]
        );
    } catch (EmployeeOperationException $exception) {
        return back()->with('error', $exception->getMessage());
    }

    return back()->with('success', 'تم تسجيل المديونية بنجاح');
}




public function collectPartial(Request $request, $debtId)
{
    $validated = $request->validate([
        'amount' => ['required', 'numeric', 'gt:0'],
    ]);

    $debt = Debt::findOrFail($debtId);
    $person = $debt->person;
    $this->authorizePerson($person);
    $accountant = auth('accountant')->user();

    if ($person->id == $accountant->employee_id) {
        return back()->with('error', 'غير مصرح لك بتحصيل مديونيتك الشخصية.');
    }

    try {
        app(EmployeeOperationService::class)->collectDebt(
            $debt,
            (float) $validated['amount'],
            app(EmployeeOperationService::class)->actorFromCurrentAuth(),
            ['use_shift_gap_date' => true, 'notify_store_owner' => true]
        );
    } catch (EmployeeOperationException $exception) {
        return back()->with('error', $exception->getMessage());
    }

    return back()->with('success', 'تم التحصيل الجزئي بنجاح');
}


public function collectFull($debtId)
{
    $debt = Debt::findOrFail($debtId);
    $person = $debt->person;
    $this->authorizePerson($person);
    $accountant = auth('accountant')->user();

    if ($person->id == $accountant->employee_id) {
        return back()->with('error', 'غير مصرح لك بتحصيل مديونيتك الشخصية.');
    }

    try {
        app(EmployeeOperationService::class)->collectDebt(
            $debt,
            (float) $debt->amount,
            app(EmployeeOperationService::class)->actorFromCurrentAuth(),
            ['use_shift_gap_date' => true, 'notify_store_owner' => true, 'full' => true]
        );
    } catch (EmployeeOperationException $exception) {
        return back()->with('error', $exception->getMessage());
    }

    return back()->with('success', 'تم التحصيل الكامل بنجاح');
}




public function getDebts($id)
{
    $person = Employee::findOrFail($id);
    $this->authorizePerson($person);

    // جلب المديونيات النشطة فقط (amount > 0)
    $debts = $person->debts()
        ->where('amount', '>', 0)
        ->orderBy('created_at', 'desc')
        ->get(['id', 'amount', 'description', 'date']);

    // تنسيق البيانات قبل الإرجاع
    $debts->transform(function ($d) {
        return [
            'id'          => $d->id,
            'amount'      => (float) $d->amount,
            'description' => $d->description ?: null,
            'date'        => $d->date,
        ];
    });

    return response()->json($debts);
}
    /*
    |--------------------------------------------------------------------------
    | صفحات العرض (بدون تعديل)
    |--------------------------------------------------------------------------
    */
public function withdrawalPage()
{
    $storeId = auth('accountant')->user()->store_id;

    $people = Employee::where('store_id', $storeId)->get();

    $lastWithdrawals = Withdrawal::where('store_id', $storeId)
        ->forAccountingDate(today()->toDateString())
        ->latest()
        ->get();

    return view('accountants.pos.withdrawals', compact('people', 'lastWithdrawals'));
}
    public function absencePage()
{
    $storeId = auth('accountant')->user()->store_id;

    $people = Employee::with('accountant')
        ->where('store_id', $storeId)
        ->orderBy('name')
        ->get()
        ->each(function ($person) {
            $person->role = $person->accountant ? 'accountant' : 'employee';
        });

    $lastAbsences = Absence::where('store_id', $storeId)
        ->forOperationDate(today()->toDateString())
        ->orderBy('created_at', 'desc')
        ->get();

    return view('accountants.pos.absence', compact('people', 'lastAbsences'));
}


    public function debtPage()
{
    $storeId = auth('accountant')->user()->store_id;

    $people = Employee::where('store_id', $storeId)
        ->withCount([
            'debts as active_debt_count' => function ($query) {
                $query->where('amount', '>', 0);
            },
        ])
        ->withSum([
            'debts as active_debt_total' => function ($query) {
                $query->where('amount', '>', 0);
            },
        ], 'amount')
        ->get();

    $lastDebts = Debt::where('store_id', $storeId)
        ->forOperationDate(today()->toDateString())
        ->with(['person', 'addedBy'])
        ->orderBy('created_at', 'desc')
        ->get();

    return view('accountants.pos.debt', compact('people', 'lastDebts'));
}

   public function creditSalePage()
{
    $storeId = auth('accountant')->user()->store_id;

    $people = Employee::where('store_id', $storeId)->get();

    $lastCreditSales = CreditSale::where('store_id', $storeId)
        ->forOperationDate(today()->toDateString())
        ->orderBy('created_at', 'desc')
        ->get();

    return view('accountants.pos.credit-sale', compact('people', 'lastCreditSales'));
}

public function collectionPage()
{
    $storeId = auth('accountant')->user()->store_id;

    // جلب الموظفين الذين لديهم عمليات بيع آجل معلّقة
    $people = Employee::where('store_id', $storeId)
        ->whereHas('creditSales', function ($q) {
            $q->where('status', 'pending');
        })
        ->get();

    // تجهيز بيانات البيع الآجل لكل موظف
    foreach ($people as $emp) {
        $emp->pending_credit_sales = $emp->creditSales()
            ->where('status', 'pending')
            ->get()
            ->map(function ($sale) {
                return [
                    'id'               => $sale->id,
                    'amount'           => $sale->amount,
                    'remaining_amount' => $sale->remaining_amount ?? $sale->amount,
                    'date'             => $sale->date,
                    'description'      => $sale->description,
                    'partial_payments' => $sale->partial_payments ?? [],
                ];
            });
    }

    // آخر 5 عمليات تحصيل من اللوج (بعد تطوير اللوج)
    $lastCollections = EmployeeLog::where('store_id', $storeId)
        ->whereIn('action_name', ['credit_sale_deducted', 'credit_sale_partial'])
        ->latest()
        ->take(5)
        ->get();

    return view('accountants.pos.collection', compact('people', 'lastCollections'));
}




}
