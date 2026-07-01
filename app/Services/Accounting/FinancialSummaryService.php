<?php

namespace App\Services\Accounting;

use App\Data\Finance\FinancialSummaryResult;
use App\Data\Finance\StoreFinancialSummary;
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
     * هذه الدالة تبقى مؤقتًا للمسارات القديمة التي تتوقع مصفوفات.
     */
    public function storeMetricsForPeriod(Collection $storeIds, $periodStart, $periodEnd, array $includedSaleTypes): array
    {
        [$summaryResult, $rawAggregatesByStore] = $this->buildStoreSummariesForPeriod(
            $storeIds,
            $periodStart,
            $periodEnd,
            $includedSaleTypes
        );

        return $summaryResult->toLegacyArray(
            $rawAggregatesByStore['sales'],
            $rawAggregatesByStore['products_cost'],
            $rawAggregatesByStore['expenses'],
            $rawAggregatesByStore['owner_purchases'],
            $rawAggregatesByStore['internal_use'],
        );
    }

    /**
     * النسخة الحديثة المعتمدة تدريجيًا: تعيد DTOs مالية بدل مفاتيح مصفوفات متفرقة.
     */
    public function storeSummariesForPeriod(Collection $storeIds, $periodStart, $periodEnd, array $includedSaleTypes): FinancialSummaryResult
    {
        [$summaryResult] = $this->buildStoreSummariesForPeriod(
            $storeIds,
            $periodStart,
            $periodEnd,
            $includedSaleTypes
        );

        return $summaryResult;
    }

    private function buildStoreSummariesForPeriod(Collection $storeIds, $periodStart, $periodEnd, array $includedSaleTypes): array
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

        $summariesByStore = $storeIds->mapWithKeys(function (int $storeId) use (
            $salesByStore,
            $productsCostByStore,
            $expensesByStore,
            $ownerPurchasesByStore,
            $internalUseByStore
        ) {
            return [
                $storeId => new StoreFinancialSummary(
                    storeId: $storeId,
                    sales: (float) ($salesByStore[$storeId] ?? 0),
                    productsCost: (float) ($productsCostByStore[$storeId] ?? 0),
                    expenses: (float) ($expensesByStore[$storeId] ?? 0),
                    ownerPurchases: (float) ($ownerPurchasesByStore[$storeId] ?? 0),
                    internalUse: (float) ($internalUseByStore[$storeId] ?? 0),
                ),
            ];
        });

        return [
            new FinancialSummaryResult($summariesByStore),
            [
                'sales' => $salesByStore,
                'products_cost' => $productsCostByStore,
                'expenses' => $expensesByStore,
                'owner_purchases' => $ownerPurchasesByStore,
                'internal_use' => $internalUseByStore,
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

    public function applyAccountingPeriodToTable($query, string $table, $periodStart, $periodEnd, ?string $schemaTable = null): void
    {
        static $hasBusinessDate = [];

        // $table هو الاسم المستخدم داخل الاستعلام وقد يكون alias مثل s أو purchases.
        // $schemaTable هو اسم الجدول الحقيقي عند استخدام aliases حتى لا نفقد business_date.
        $schemaTable ??= $table;
        $cacheKey = $schemaTable;

        if (! array_key_exists($cacheKey, $hasBusinessDate)) {
            $hasBusinessDate[$cacheKey] = Schema::hasColumn($schemaTable, 'business_date');
        }

        if (! $hasBusinessDate[$cacheKey]) {
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
