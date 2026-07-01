<?php

namespace App\Http\Controllers\Employees;

use App\Support\ArabicPdf as PDF;
use App\Models\Debt;
use App\Traits\FindPersonTrait;
use App\Services\EmployeeLogService;
use Illuminate\Support\Carbon;
use App\Models\Employee;

/**
 * --------------------------------------------------------------------------
 * EmployeeReports
 * --------------------------------------------------------------------------
 * هذا الملف مسؤول عن:
 * - تصدير تقارير PDF الخاصة بالموظف أو المحاسب
 * - يعتمد على FindPersonTrait لتحديد نوع الشخص (موظف / محاسب)
 * --------------------------------------------------------------------------
 */
class EmployeeReports
{
    use FindPersonTrait;

    /**
     * ----------------------------------------------------------------------
     * تصدير تقرير PDF باستخدام mPDF الداعم للعربية
     * ----------------------------------------------------------------------
     * - يقوم بجلب بيانات الشخص (موظف أو محاسب)
     * - يجمع جميع العمليات المرتبطة به
     * - ينشئ ملف PDF جاهز للتحميل
     * ----------------------------------------------------------------------
     */
    public static function exportPdf($id)
    {
        // إنشاء نسخة من الكلاس لاستخدام الـ trait
        $self = new self();

        // جلب الموظف أو المحاسب
        $person = $self->findPerson($id);

        // الشهر المستهدف:
        // - إذا كان التصدير يوم 1 أو 2 أو 3: نعتمد الشهر السابق.
        // - فيما عدا ذلك: نعتمد الشهر الحالي.
        $reportMonth = Carbon::now()->day <= 3
            ? Carbon::now()->subMonthNoOverflow()
            : Carbon::now();
        $reportMonthKey = $reportMonth->format('Y-m');
        $monthStart = $reportMonth->copy()->startOfMonth()->toDateString();
        $monthEnd = $reportMonth->copy()->endOfMonth()->toDateString();

        // حسابات المديونية
        $remainingDebt = $person->debts()->sum('amount');

        // نعتمد على التاريخ الفعلي للعملية (date) مع fallback على month لضمان عدم ضياع السجلات القديمة
        $debtOperations = $person->debts()
            ->where(function ($query) use ($monthStart, $monthEnd, $reportMonthKey) {
                $query->whereBetween('date', [$monthStart, $monthEnd])
                    ->orWhere('month', $reportMonthKey);
            })
            ->orderBy('date')
            ->get();

        $collectedThisMonth = $debtOperations->where('amount', '<', 0)->sum('amount');
        $addedThisMonth = $debtOperations->where('amount', '>', 0)->sum('amount');

        $withdrawals = $person->withdrawals()
            ->where(function ($query) use ($monthStart, $monthEnd, $reportMonthKey) {
                $query->whereBetween('date', [$monthStart, $monthEnd])
                    ->orWhere('month', $reportMonthKey);
            })
            ->orderBy('date')
            ->get();

        $absences = $person->absences()
            ->where(function ($query) use ($monthStart, $monthEnd, $reportMonthKey) {
                $query->whereBetween('date', [$monthStart, $monthEnd])
                    ->orWhere('month', $reportMonthKey);
            })
            ->with('addedBy')
            ->orderBy('date')
            ->get();

        $periodStart = Carbon::parse($monthStart)->startOfDay();
        $periodEnd = Carbon::parse($monthEnd)->endOfDay();
        $salaryInfo = $person instanceof Employee
            ? EmployeeService::calculateProratedSalaryForEmployee($person, $periodStart, $periodEnd)
            : [
                'payable_salary' => (float) ($person->salary ?? 0),
                'worked_days' => $periodStart->daysInMonth,
                'suspended_days' => 0,
            ];
        $absencePenalty = (((float) ($person->salary ?? 0)) / max(1, $periodStart->daysInMonth)) * $absences->count();
        $salaryNet = max(0, (float) $salaryInfo['payable_salary'] - (float) $withdrawals->sum('amount') - $absencePenalty);

        $creditSalesPending = $person->creditSales()
            ->where(function ($query) use ($monthStart, $monthEnd, $reportMonthKey) {
                $query->whereBetween('date', [$monthStart, $monthEnd])
                    ->orWhere('month', $reportMonthKey);
            })
            ->where('status', 'pending')
            ->get();

        $creditSalesCollected = $person->creditSales()
            ->where('status', 'deducted')
            ->where(function ($query) use ($monthStart, $monthEnd, $reportMonthKey) {
                $query->where('deducted_month', $reportMonthKey)
                    ->orWhereBetween('date', [$monthStart, $monthEnd]);
            })
            ->with('addedBy')
            ->get();

        // تجهيز البيانات للعرض داخل الـ PDF
        $data = [
            'person'               => $person,
            'report_month'         => $reportMonthKey,
            'withdrawals'          => $withdrawals,
            'withdrawals_total'    => (float) $withdrawals->sum('amount'),
            'absences'             => $absences,
            'absences_count'       => $absences->count(),
            'absence_penalty'      => $absencePenalty,
            'salary_payable'       => (float) $salaryInfo['payable_salary'],
            'salary_net'           => $salaryNet,
            'debts'                => $debtOperations, // العمليات كاملة
            'remainingDebt'        => $remainingDebt,  // الرصيد النهائي
            'addedThisMonth'       => $addedThisMonth, // مجموع الإضافات
            'collectedThisMonth'   => abs($collectedThisMonth), // التحصيل الشهري (موجب)
            'creditSalesPending'   => $creditSalesPending,
            'creditSalesCollected' => $creditSalesCollected,
            'created_by'           => auth()->user(),
        ];

        // تسجيل عملية التصدير
        EmployeeLogService::add(
            $person,
            'report_exported',
            "تم تصدير تقرير PDF للموظف/المحاسب {$person->name} لشهر {$reportMonthKey}"
        );

        // إنشاء ملف PDF
        $pdf = PDF::loadView('pdf.employee-pdf', $data)
            ->setPaper('a4')
            ->setOption('encoding', 'UTF-8');

        // قرار مالي مثبت: تصدير PDF لا يحذف ولا يصفر أي عملية.
        // التقرير أصبح لقطة قراءة فقط؛ التحصيل أو التسوية تتم من شاشات العمليات المخصصة.
        session()->flash(
            'success',
            "تم تصدير التقرير بنجاح دون حذف أو تصفير أي بيانات للشهر {$reportMonthKey}."
        );

        return $pdf->download("تقرير {$reportMonthKey} - {$person->name}.pdf");
    }

    /**
     * 2026-05-16: دالة توافق مؤقتة لأي استدعاء قديم ما زال يستخدم اسم exportSnappy.
     */
    public static function exportSnappy($id)
    {
        return self::exportPdf($id);
    }
}
