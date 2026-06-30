<?php

namespace App\Services\Accounting;

use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialSummaryService
{
    /**
     * يبني ملخصًا ماليًا مجمعًا لكل متجر خلال فترة محاسبية واحدة.
     *
     * القيم هنا تستخدم business_date عند توفره، وتعود إلى created_at فقط كدعم للبيانات القديمة.
     */
    public function storeMetricsForPeriod(Collection $storeIds, $periodStart, $periodEnd, array $includedSaleTypes): array
    {
        $storeIds = $storeIds->map(fn ($storeId) => (int) $storeId)->filter()->values();

        $salesByStore = $this->collectedSalesByStore($storeIds, $periodStart, $periodEnd, $includedSaleTypes);
        $productsCostByStore = app(SalesCostService::class)->soldProductsCostByStoreForPeriod(
            $storeIds,
            $periodStart,
            $periodEnd,
            $includedSaleTypes
        );
        $expensesByStore = $this->sumByStoreForPeriod('expenses', 'amount', $storeIds, $periodStart, $periodEnd);
        $ownerPurchasesByStore = $this->sumByStoreForPeriod('purchases', 'cost', $storeIds, $periodStart, $periodEnd);
        $internalUseByStore = $this->internalUseByStore($storeIds, $periodStart, $periodEnd);

        $metricsByStore = [];
        foreach ($storeIds as $storeId) {
            $sales = (float) ($salesByStore[$storeId] ?? 0);
            $productsCost = (float) ($productsCostByStore[$storeId] ?? 0);
            $expenses = (float) ($expensesByStore[$storeId] ?? 0);
            $ownerPurchases = (float) ($ownerPurchasesByStore[$storeId] ?? 0);
            $internalUse = (float) ($internalUseByStore[$storeId] ?? 0);

            $metricsByStore[$storeId] = [
                'sales' => $sales,
                'products_cost' => $productsCost,
                'expenses' => $expenses,
                'owner_purchases' => $ownerPurchases,
                'internal_use' => $internalUse,
                'purchases_and_internal_use' => $ownerPurchases + $internalUse,
                'profit' => $sales - $productsCost - $expenses - $ownerPurchases - $internalUse,
            ];
        }

        $salesTotal = (float) $salesByStore->sum();
        $productsCostTotal = array_sum(array_column($metricsByStore, 'products_cost'));
        $expensesTotal = (float) $expensesByStore->sum();
        $ownerPurchasesTotal = (float) $ownerPurchasesByStore->sum();
        $internalUseTotal = (float) $internalUseByStore->sum();

        return [
            'sales_by_store' => $salesByStore,
            'products_cost_by_store' => $productsCostByStore,
            'expenses_by_store' => $expensesByStore,
            'owner_purchases_by_store' => $ownerPurchasesByStore,
            'internal_use_by_store' => $internalUseByStore,
            'metrics_by_store' => $metricsByStore,
            'totals' => [
                'sales' => $salesTotal,
                'products_cost' => $productsCostTotal,
                'expenses' => $expensesTotal,
                'owner_purchases' => $ownerPurchasesTotal,
                'internal_use' => $internalUseTotal,
                'purchases_and_internal_use' => $ownerPurchasesTotal + $internalUseTotal,
                'profit' => $salesTotal - $productsCostTotal - $expensesTotal - $ownerPurchasesTotal - $internalUseTotal,
            ],
        ];
    }

    public function collectedSalesByStore(Collection $storeIds, $periodStart, $periodEnd, array $includedSaleTypes): Collection
    {
        return Sale::query()
            ->excludeManualInvoiceEntries()
            ->whereIn('sale_type', $includedSaleTypes)
            ->whereIn('store_id', $storeIds)
            ->betweenAccountingDates($periodStart, $periodEnd)
            ->groupBy('store_id')
            ->selectRaw('store_id, COALESCE(SUM(paid_amount), 0) as aggregate')
            ->pluck('aggregate', 'store_id');
    }

    public function sumByStoreForPeriod(string $table, string $amountColumn, Collection $storeIds, $periodStart, $periodEnd): Collection
    {
        $query = DB::table($table)
            ->whereIn('store_id', $storeIds);

        $this->applyAccountingPeriodToTable($query, $table, $periodStart, $periodEnd);

        return $query
            ->groupBy('store_id')
            ->selectRaw("store_id, COALESCE(SUM({$amountColumn}), 0) as aggregate")
            ->pluck('aggregate', 'store_id');
    }

    private function internalUseByStore(Collection $storeIds, $periodStart, $periodEnd): Collection
    {
        return Sale::query()
            ->excludeManualInvoiceEntries()
            ->whereIn('store_id', $storeIds)
            ->betweenAccountingDates($periodStart, $periodEnd)
            ->where('sale_type', 'internal_use')
            ->groupBy('store_id')
            ->selectRaw('store_id, COALESCE(SUM(total), 0) as aggregate')
            ->pluck('aggregate', 'store_id');
    }

    public function applyAccountingPeriodToTable($query, string $table, $periodStart, $periodEnd): void
    {
        static $hasBusinessDate = [];

        if (! array_key_exists($table, $hasBusinessDate)) {
            $hasBusinessDate[$table] = Schema::hasColumn($table, 'business_date');
        }

        if (! $hasBusinessDate[$table]) {
            $query->whereBetween("{$table}.created_at", [
                Carbon::parse($periodStart)->startOfDay(),
                Carbon::parse($periodEnd)->endOfDay(),
            ]);

            return;
        }

        $query->whereRaw(
            "COALESCE({$table}.business_date, DATE({$table}.created_at)) BETWEEN ? AND ?",
            [
                Carbon::parse($periodStart)->toDateString(),
                Carbon::parse($periodEnd)->toDateString(),
            ]
        );
    }
}
