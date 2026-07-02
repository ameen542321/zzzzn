<?php

namespace App\Services\Employees;

use App\Http\Controllers\Employees\EmployeeService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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
            'actionCards' => $this->actionCards($person, $selectedMonth),
            'recentLogs' => $this->paginatedLogs($operationDetails, $periodStart),
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

    public function actionCards(Model $person, string $selectedMonth): array
    {
        return [
            ['modal' => 'withdrawalModal', 'title' => 'سحب', 'hint' => 'تسجيل عملية سحب', 'icon' => 'fa-money-bill-transfer', 'accent' => 'sky', 'type' => 'modal'],
            ['modal' => 'absenceModal', 'title' => 'غياب', 'hint' => 'إضافة يوم غياب', 'icon' => 'fa-user-xmark', 'accent' => 'amber', 'type' => 'modal'],
            ['modal' => 'debtModal', 'title' => 'مديونية', 'hint' => 'تسجيل مديونية', 'icon' => 'fa-hand-holding-dollar', 'accent' => 'rose', 'type' => 'modal'],
            ['modal' => 'creditSaleModal', 'title' => 'بيع آجل', 'hint' => 'إنشاء عملية بيع آجل', 'icon' => 'fa-cart-shopping', 'accent' => 'violet', 'type' => 'modal'],
            ['modal' => 'creditSaleCollectionModal', 'title' => 'تحصيل', 'hint' => 'تحصيل من المديونية', 'icon' => 'fa-sack-dollar', 'accent' => 'emerald', 'type' => 'modal'],
            ['url' => route('user.employees.exportLog', $person->id) . '?month=' . $selectedMonth, 'title' => 'تصدير بيانات الموظف', 'hint' => 'PDF قراءة فقط دون تصفير', 'icon' => 'fa-file-pdf', 'accent' => 'red', 'type' => 'link'],
            ['url' => route('user.employees.edit', $person->id), 'title' => 'تعديل ملف الموظف', 'hint' => 'تحديث بيانات الموظف', 'icon' => 'fa-user-pen', 'accent' => 'indigo', 'type' => 'link'],
            [
                'url' => $person->status === 'active' ? route('user.employees.suspend', $person->id) : route('user.employees.activate', $person->id),
                'title' => 'إيقاف / تفعيل',
                'hint' => $person->status === 'active' ? 'إيقاف الموظف مؤقتًا' : 'إعادة تفعيل الموظف',
                'icon' => $person->status === 'active' ? 'fa-user-slash' : 'fa-user-check',
                'accent' => $person->status === 'active' ? 'orange' : 'emerald',
                'type' => 'status',
                'method' => 'PATCH',
                'confirm' => $person->status === 'active'
                    ? 'سيتم إيقاف الموظف ماليًا ووظيفيًا، وسيتم إيقاف حساب المحاسب المرتبط إن وجد. هل أنت متأكد؟'
                    : 'سيتم تفعيل الموظف فقط واستئناف احتساب راتبه من تاريخ التفعيل. هل أنت متأكد؟',
            ],
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

    private function paginatedLogs(array $details, Carbon $periodStart): LengthAwarePaginator
    {
        $rows = collect()
            ->merge($this->withdrawalLogRows($details['withdrawals']))
            ->merge($this->debtLogRows($details['debts']))
            ->merge($this->creditSaleLogRows($details['credit_sales']))
            ->merge($this->absenceLogRows($details['absences']))
            ->sortByDesc(fn ($row) => $row->meta['operation_date'] ?? '')
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage('logs_page');
        $perPage = 10;

        return (new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['pageName' => 'logs_page', 'path' => request()->url()]
        ))->appends(['month' => $periodStart->format('Y-m')]);
    }

    private function withdrawalLogRows(Collection $withdrawals): Collection
    {
        return $withdrawals->map(fn ($item) => (object) [
            'action_name' => 'withdrawal',
            'description' => 'سحب مبلغ ' . number_format((float) $item->amount, 2) . ' ريال',
            'meta' => $this->rowMeta($item, 'سحب', $item->business_date ?? $item->date),
        ]);
    }

    private function debtLogRows(Collection $debts): Collection
    {
        return $debts->map(function ($item) {
            $isCollection = (float) $item->amount < 0;

            return (object) [
                'action_name' => $isCollection ? 'debt_collect_partial' : 'debt',
                'description' => ($isCollection ? 'تحصيل مديونية' : 'تسجيل مديونية') . ' بقيمة ' . number_format(abs((float) $item->amount), 2) . ' ريال',
                'meta' => $this->rowMeta($item, $isCollection ? 'تحصيل مديونية' : 'مديونية', $item->date),
            ];
        });
    }

    private function creditSaleLogRows(Collection $creditSales): Collection
    {
        return $creditSales->flatMap(function ($item) {
            $rows = collect([(object) [
                'action_name' => 'credit_sale',
                'description' => 'بيع آجل بقيمة ' . number_format((float) $item->amount, 2) . ' ريال',
                'meta' => $this->rowMeta($item, 'آجل', $item->date),
            ]]);

            foreach (($item->partial_payments ?? []) as $payment) {
                $rows->push((object) [
                    'action_name' => (($payment['description'] ?? '') === 'تحصيل كامل') ? 'credit_sale_deducted' : 'credit_sale_partial',
                    'description' => ($payment['description'] ?? 'تحصيل آجل') . ' بقيمة ' . number_format((float) ($payment['amount'] ?? 0), 2) . ' ريال',
                    'meta' => [
                        'type' => 'تحصيل آجل',
                        'actor_name' => $payment['added_by_name'] ?? 'غير محدد',
                        'operation_date' => isset($payment['date']) ? Carbon::parse($payment['date'])->format('Y-m-d') : null,
                    ],
                ]);
            }

            return $rows;
        });
    }

    private function absenceLogRows(Collection $absences): Collection
    {
        return $absences->map(fn ($item) => (object) [
            'action_name' => 'absence',
            'description' => 'تسجيل غياب',
            'meta' => $this->rowMeta($item, 'غياب', $item->date),
        ]);
    }

    private function rowMeta($item, string $type, $date): array
    {
        return [
            'type' => $type,
            'actor_name' => $item->addedBy?->name ?? 'غير محدد',
            'operation_date' => $date ? Carbon::parse($date)->format('Y-m-d') : null,
        ];
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
