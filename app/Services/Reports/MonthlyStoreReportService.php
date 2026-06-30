<?php

namespace App\Services\Reports;

use App\Http\Controllers\Employees\EmployeeService;
use App\Models\Employee;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreTransfer;
use App\Services\Accounting\SalesCostService;

class MonthlyStoreReportService
{
    /**
     * بناء اسم واضح للتقرير الشهري بنفس أسلوب التقرير اليومي مع توضيح نوع التقرير.
     */
    public function buildMonthlyReportTitle(string $storeName, string $month, bool $isDetailed): string
    {
        $reportType = $isDetailed ? 'مفصل' : 'مختصر';

        return "تقرير شهري {$reportType} متجر {$storeName} {$month}";
    }

    /**
     * تحويل اسم التقرير إلى اسم ملف آمن مع الحفاظ على الأحرف العربية قدر الإمكان.
     */
    public function buildSafeReportFileName(string $reportTitle, int $storeId): string
    {
        $safeReportTitle = preg_replace('/[^\p{Arabic}\p{L}\p{N}\-_ ]+/u', '', $reportTitle) ?: 'تقرير_شهري_متجر';
        $safeReportTitle = trim(preg_replace('/\s+/u', ' ', $safeReportTitle));
        $safeReportTitle = str_replace(' ', '_', $safeReportTitle);

        return 'Report_' . $safeReportTitle . '_' . time() . '_' . $storeId . '.pdf';
    }

    /**
     * تجهيز بيانات التقرير الشهري في مكان واحد للصفحة والـ PDF.
     */
    public function buildMonthlyReportData(Store $store, string $month, $start, $end, bool $withDetails): array
    {
        $salesQuery = Sale::where('store_id', $store->id)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('sale_type', ['cash', 'card', 'credit', 'mixed']);

        $internalUseSales = (float) Sale::where('store_id', $store->id)
            ->where('sale_type', 'internal_use')
            ->whereBetween('created_at', [$start, $end])
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->sum('total');

        $ownerPurchases = (float) Purchase::where('store_id', $store->id)
            ->whereBetween('created_at', [$start, $end])
            ->sum('cost');

        $monthlySoldProductsCost = app(SalesCostService::class)->soldProductsCostForPeriod(
            $store->id,
            $start,
            $end,
            ['cash', 'card', 'credit', 'mixed']
        );

        $profitDeductionTotal = $monthlySoldProductsCost;
        $totalConsumption = $internalUseSales + $ownerPurchases;
        $expensesTotal = (float) \App\Models\Expense::where('store_id', $store->id)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');
        $withdrawalsTotal = (float) \App\Models\Withdrawal::where('store_id', $store->id)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');
        $monthlySalaries = $this->monthlyProratedSalariesTotal($store->id, $start, $end);
        $totalSales = (float) (clone $salesQuery)->sum('paid_amount');

        $data = [
            'store' => $store,
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'totalSales' => $totalSales,
            'operationsCount' => (int) (clone $salesQuery)->count(),
            'cashSales' => (float) (clone $salesQuery)->sum('cash_amount'),
            'cardSales' => (float) (clone $salesQuery)->sum('card_amount'),
            'internalUseSales' => $internalUseSales,
            'ownerPurchases' => $ownerPurchases,
            'monthlySoldProductsCost' => $monthlySoldProductsCost,
            'profitDeductionTotal' => $profitDeductionTotal,
            'totalConsumption' => $totalConsumption,
            'expensesTotal' => $expensesTotal,
            'withdrawalsTotal' => $withdrawalsTotal,
            'monthlySalaries' => $monthlySalaries,
            'netAfterCosts' => $totalSales - ($profitDeductionTotal + $totalConsumption + $expensesTotal),
            'dailyRows' => $this->monthlyDailyRows($store->id, $start, $end),
            'transferSummary' => $this->monthlyTransferSummary($store->id, $start, $end),
        ];

        if ($withDetails) {
            $data['ownerPurchaseRows'] = $this->monthlyOwnerPurchaseRows($store->id, $start, $end);
            $data['accountantConsumptionRows'] = $this->monthlyAccountantConsumptionRows($store->id, $start, $end);
            $data['expenseRows'] = $this->monthlyExpenseRows($store->id, $start, $end);
            $data['employeeRows'] = $this->monthlyEmployeeRows($store->id, $month, $start, $end);
            $data['transferRows'] = $this->monthlyTransferRows($store->id, $start, $end);
        }

        return $data;
    }


    /**
     * ملخص النقل المخزني المكتمل داخل الشهر لعرضه ضمن التقرير الشهري المختصر.
     */
    private function monthlyTransferSummary(int $storeId, $start, $end): array
    {
        $transfers = StoreTransfer::with('items')
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$start, $end])
            ->where(function ($query) use ($storeId) {
                $query->where('sender_store_id', $storeId)
                    ->orWhere('receiver_store_id', $storeId);
            })
            ->get(['id', 'sender_store_id', 'receiver_store_id', 'status', 'completed_at']);

        $outgoing = $transfers->where('sender_store_id', $storeId);
        $incoming = $transfers->where('receiver_store_id', $storeId);
        $valueFor = fn ($collection) => (float) $collection->sum(fn ($transfer) => $transfer->items->sum(
            fn ($item) => (float) $item->normalized_quantity * (float) $item->cost_price
        ));

        $outgoingCost = $valueFor($outgoing);
        $incomingCost = $valueFor($incoming);

        $difference = $incomingCost - $outgoingCost;

        return [
            'outgoing_count' => $outgoing->count(),
            'incoming_count' => $incoming->count(),
            'outgoing_cost' => $outgoingCost,
            'incoming_cost' => $incomingCost,
            'difference' => $difference,
            'difference_abs' => abs($difference),
            'difference_type' => $difference > 0 ? 'profit' : ($difference < 0 ? 'loss' : 'balanced'),
            'difference_label' => $difference > 0 ? 'ربح نقل مخزني' : ($difference < 0 ? 'خسارة نقل مخزني' : 'متعادل'),
            'formula_note' => 'الفارق = إجمالي الوارد بسعر التكلفة - إجمالي الصادر بسعر التكلفة',
        ];
    }

    /**
     * تفاصيل عمليات النقل المخزني للتقرير الشهري المفصل.
     */
    private function monthlyTransferRows(int $storeId, $start, $end)
    {
        return StoreTransfer::with(['senderStore:id,name', 'receiverStore:id,name', 'items.senderProduct:id,name', 'items.receiverProduct:id,name', 'actionBy'])
            ->where(function ($query) use ($storeId) {
                $query->where('sender_store_id', $storeId)
                    ->orWhere('receiver_store_id', $storeId);
            })
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('created_at', [$start, $end])
                    ->orWhereBetween('completed_at', [$start, $end])
                    ->orWhereBetween('rejected_at', [$start, $end])
                    ->orWhereBetween('cancelled_at', [$start, $end]);
            })
            ->orderBy('created_at')
            ->get()
            ->flatMap(function (StoreTransfer $transfer) use ($storeId) {
                $direction = (int) $transfer->sender_store_id === (int) $storeId ? 'صادر' : 'وارد';
                $otherStore = $direction === 'صادر' ? $transfer->receiverStore?->name : $transfer->senderStore?->name;

                return $transfer->items->map(function ($item) use ($transfer, $direction, $otherStore) {
                    return [
                        'date' => optional($transfer->completed_at ?? $transfer->acted_at ?? $transfer->created_at)->format('Y-m-d'),
                        'request_date' => optional($transfer->created_at)->format('Y-m-d'),
                        'transfer_id' => $transfer->id,
                        'direction' => $direction,
                        'other_store' => $otherStore,
                        'sender_product' => $item->senderProduct?->name,
                        'receiver_product' => $item->receiverProduct?->name,
                        'quantity' => (float) $item->requested_quantity,
                        'normalized_quantity' => (float) $item->normalized_quantity,
                        'unit_type' => $item->unit_type,
                        'cost_price' => (float) $item->cost_price,
                        'total_cost' => (float) $item->normalized_quantity * (float) $item->cost_price,
                        'status' => $transfer->status,
                        'notes' => $transfer->notes ?: $transfer->rejection_reason,
                        'action_by' => $transfer->actionBy?->name,
                    ];
                });
            })
            ->values();
    }

    /**
     * ملخص المبيعات اليومية للتقرير المفصل مع الكاش والشبكة وترقيمه يتم في العرض.
     */
    private function monthlyDailyRows(int $storeId, $start, $end)
    {
        return Sale::where('store_id', $storeId)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('sale_type', ['cash', 'card', 'credit', 'mixed'])
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as ops_count')
            ->selectRaw('COALESCE(SUM(cash_amount), 0) as cash_total')
            ->selectRaw('COALESCE(SUM(card_amount), 0) as card_total')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) as sales_total')
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    private function monthlyOwnerPurchaseRows(int $storeId, $start, $end)
    {
        return Purchase::with('product:id,name')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get(['id', 'product_id', 'purchase_name', 'quantity', 'cost', 'description', 'created_at']);
    }

    private function monthlyAccountantConsumptionRows(int $storeId, $start, $end)
    {
        return Sale::with(['items.product:id,name'])
            ->where('store_id', $storeId)
            ->where('sale_type', 'internal_use')
            ->whereBetween('created_at', [$start, $end])
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->orderBy('created_at')
            ->get(['id', 'description', 'total', 'created_at']);
    }

    private function monthlyExpenseRows(int $storeId, $start, $end)
    {
        return \App\Models\Expense::where('store_id', $storeId)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get(['id', 'description', 'amount', 'created_at']);
    }

    private function monthlyEmployeeRows(int $storeId, string $month, $start, $end)
    {
        // يشمل الموظف في تقرير المتجر القديم إذا تم نقله بعد نهاية شهر التقرير حتى لا تتغير تقارير الشهور السابقة.
        $transferredAfterPeriodEmployeeIds = \App\Models\EmployeeLog::query()
            ->where('action_name', 'employee_transferred')
            ->where('person_type', Employee::class)
            ->where('meta->old_store_id', $storeId)
            ->where('created_at', '>', $end)
            ->pluck('person_id');

        $employees = Employee::withTrashed()
            ->where(function ($query) use ($storeId, $transferredAfterPeriodEmployeeIds) {
                $query->where('store_id', $storeId)
                    ->orWhereIn('id', $transferredAfterPeriodEmployeeIds);
            })
            ->where(function ($query) use ($start, $end) {
                $query->whereNull('deleted_at')
                    ->orWhereBetween('deleted_at', [$start, $end]);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'salary', 'status', 'deleted_at']);

        $employeeIds = $employees->pluck('id');
        $withdrawals = \App\Models\Withdrawal::where('store_id', $storeId)
            ->where('person_type', Employee::class)
            ->whereIn('person_id', $employeeIds)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('person_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('person_id')
            ->pluck('total', 'person_id');
        $absences = \App\Models\Absence::where('store_id', $storeId)
            ->where('person_type', Employee::class)
            ->whereIn('person_id', $employeeIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('person_id, COUNT(*) as count_total')
            ->groupBy('person_id')
            ->pluck('count_total', 'person_id');
        $debts = \App\Models\Debt::where('store_id', $storeId)
            ->where('person_type', Employee::class)
            ->whereIn('person_id', $employeeIds)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('person_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('person_id')
            ->pluck('total', 'person_id');
        $creditRemaining = \App\Models\CreditSale::where('store_id', $storeId)
            ->where('person_type', Employee::class)
            ->whereIn('person_id', $employeeIds)
            ->selectRaw('person_id, COALESCE(SUM(remaining_amount), 0) as total')
            ->groupBy('person_id')
            ->pluck('total', 'person_id');

        return $employees->map(function (Employee $employee) use ($month, $start, $end, $withdrawals, $absences, $debts, $creditRemaining) {
            $withdrawalTotal = (float) ($withdrawals[$employee->id] ?? 0);
            $absenceDays = (int) ($absences[$employee->id] ?? 0);
            $debtTotal = (float) ($debts[$employee->id] ?? 0) + (float) ($creditRemaining[$employee->id] ?? 0);
            $salaryInfo = EmployeeService::calculateProratedSalaryForEmployee($employee, $start, $end);
            $payableSalary = (float) $salaryInfo['payable_salary'];
            $dailySalary = ((float) $employee->salary) / max((int) $start->daysInMonth, 1);
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

    private function monthlyProratedSalariesTotal(int $storeId, $start, $end): float
    {
        $transferredAfterPeriodEmployeeIds = \App\Models\EmployeeLog::query()
            ->where('action_name', 'employee_transferred')
            ->where('person_type', Employee::class)
            ->where('meta->old_store_id', $storeId)
            ->where('created_at', '>', $end)
            ->pluck('person_id');

        return Employee::withTrashed()
            ->where(function ($query) use ($storeId, $transferredAfterPeriodEmployeeIds) {
                $query->where('store_id', $storeId)
                    ->orWhereIn('id', $transferredAfterPeriodEmployeeIds);
            })
            ->where(function ($query) use ($start, $end) {
                $query->whereNull('deleted_at')
                    ->orWhereBetween('deleted_at', [$start, $end]);
            })
            ->get(['id', 'salary', 'status', 'deleted_at'])
            ->sum(fn (Employee $employee) => EmployeeService::calculateProratedSalaryForEmployee($employee, $start, $end)['payable_salary']);
    }

}
