<?php

namespace App\Services\Reports;

use App\Data\Finance\StoreFinancialSummary;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreTransfer;
use App\Services\Accounting\FinancialSummaryService;
use App\Services\Employees\EmployeePayrollService;

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
        $includedSaleTypes = ['cash', 'card', 'credit', 'mixed'];
        $salesQuery = Sale::where('store_id', $store->id)
            ->collectedDashboardSales()
            ->betweenAccountingDates($start, $end);

        $storeFinancialMetrics = app(FinancialSummaryService::class)->storeSummariesForPeriod(
            collect([$store->id]),
            $start,
            $end,
            $includedSaleTypes
        )->summariesByStore->get($store->id) ?? $this->emptyFinancialSummary($store->id);

        $internalUseSales = $storeFinancialMetrics->internalUse;
        $ownerPurchases = $storeFinancialMetrics->ownerPurchases;
        $monthlySoldProductsCost = $storeFinancialMetrics->productsCost;
        $profitDeductionTotal = $monthlySoldProductsCost;
        $totalConsumption = $storeFinancialMetrics->purchasesAndInternalUse();
        $expensesTotal = $storeFinancialMetrics->expenses;
        $withdrawalsQuery = \App\Models\Withdrawal::where('store_id', $store->id);
        app(FinancialSummaryService::class)->applyAccountingPeriodToTable($withdrawalsQuery, 'employee_withdrawals', $start, $end);
        $withdrawalsTotal = (float) $withdrawalsQuery->sum('amount');
        $monthlySalaries = app(EmployeePayrollService::class)->proratedSalariesTotalForStore($store->id, $start, $end);
        $totalSales = $storeFinancialMetrics->sales;

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
            $data['employeeRows'] = app(EmployeePayrollService::class)->monthlyRowsForStore($store->id, $month, $start, $end);
            $data['transferRows'] = $this->monthlyTransferRows($store->id, $start, $end);
        }

        return $data;
    }

    private function emptyFinancialSummary(int $storeId): StoreFinancialSummary
    {
        return new StoreFinancialSummary(
            storeId: $storeId,
            sales: 0.0,
            productsCost: 0.0,
            expenses: 0.0,
            ownerPurchases: 0.0,
            internalUse: 0.0,
        );
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
            ->collectedDashboardSales()
            ->betweenAccountingDates($start, $end)
            ->selectRaw('COALESCE(business_date, DATE(created_at)) as day')
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
        $ownerPurchasesQuery = Purchase::with('product:id,name')
            ->where('store_id', $storeId);

        app(FinancialSummaryService::class)->applyAccountingPeriodToTable($ownerPurchasesQuery, 'purchases', $start, $end);

        return $ownerPurchasesQuery
            ->orderBy('created_at')
            ->get(['id', 'product_id', 'purchase_name', 'quantity', 'cost', 'description', 'created_at']);
    }

    private function monthlyAccountantConsumptionRows(int $storeId, $start, $end)
    {
        return Sale::with(['items.product:id,name'])
            ->where('store_id', $storeId)
            ->where('sale_type', 'internal_use')
            ->betweenAccountingDates($start, $end)
            ->excludeManualInvoiceEntries()
            ->orderBy('created_at')
            ->get(['id', 'description', 'total', 'created_at']);
    }

    private function monthlyExpenseRows(int $storeId, $start, $end)
    {
        return \App\Models\Expense::where('store_id', $storeId)
            ->betweenAccountingDates($start, $end)
            ->orderBy('created_at')
            ->get(['id', 'description', 'amount', 'created_at']);
    }

}
