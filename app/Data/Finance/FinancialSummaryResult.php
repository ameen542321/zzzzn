<?php

namespace App\Data\Finance;

use Illuminate\Support\Collection;

/**
 * نتيجة مالية مجمعة لفترة محاسبية.
 *
 * تحتفظ بالـ DTOs كمصدر حديث، وتوفر toLegacyArray فقط للمسارات القديمة حتى يتم نقلها تدريجيًا.
 */
final readonly class FinancialSummaryResult
{
    /**
     * @param Collection<int, StoreFinancialSummary> $summariesByStore
     */
    public function __construct(
        public Collection $summariesByStore,
    ) {}

    public function totals(): StoreFinancialSummary
    {
        return new StoreFinancialSummary(
            storeId: 0,
            sales: (float) $this->summariesByStore->sum(fn (StoreFinancialSummary $summary) => $summary->sales),
            productsCost: (float) $this->summariesByStore->sum(fn (StoreFinancialSummary $summary) => $summary->productsCost),
            expenses: (float) $this->summariesByStore->sum(fn (StoreFinancialSummary $summary) => $summary->expenses),
            ownerPurchases: (float) $this->summariesByStore->sum(fn (StoreFinancialSummary $summary) => $summary->ownerPurchases),
            internalUse: (float) $this->summariesByStore->sum(fn (StoreFinancialSummary $summary) => $summary->internalUse),
        );
    }

    public function toLegacyArray(Collection $salesByStore, array $productsCostByStore, Collection $expensesByStore, Collection $ownerPurchasesByStore, Collection $internalUseByStore): array
    {
        $metricsByStore = $this->summariesByStore
            ->map(fn (StoreFinancialSummary $summary) => $summary->toMetricArray())
            ->all();

        return [
            'sales_by_store' => $salesByStore,
            'products_cost_by_store' => $productsCostByStore,
            'expenses_by_store' => $expensesByStore,
            'owner_purchases_by_store' => $ownerPurchasesByStore,
            'internal_use_by_store' => $internalUseByStore,
            'metrics_by_store' => $metricsByStore,
            'totals' => $this->totals()->toMetricArray(),
        ];
    }
}
