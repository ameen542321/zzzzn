<?php

namespace Tests\Unit;

use App\Data\Finance\FinancialSummaryResult;
use App\Data\Finance\StoreFinancialSummary;
use PHPUnit\Framework\TestCase;

class FinancialSummaryResultTest extends TestCase
{
    public function test_totals_sum_store_summaries_and_profit_formula(): void
    {
        $result = new FinancialSummaryResult(collect([
            1 => new StoreFinancialSummary(
                storeId: 1,
                sales: 1000.0,
                productsCost: 300.0,
                expenses: 100.0,
                ownerPurchases: 50.0,
                internalUse: 25.0,
            ),
            2 => new StoreFinancialSummary(
                storeId: 2,
                sales: 500.0,
                productsCost: 125.0,
                expenses: 40.0,
                ownerPurchases: 10.0,
                internalUse: 5.0,
            ),
        ]));

        $totals = $result->totals();

        $this->assertSame(0, $totals->storeId);
        $this->assertSame(1500.0, $totals->sales);
        $this->assertSame(425.0, $totals->productsCost);
        $this->assertSame(140.0, $totals->expenses);
        $this->assertSame(60.0, $totals->ownerPurchases);
        $this->assertSame(30.0, $totals->internalUse);
        $this->assertSame(845.0, $totals->profit());
    }

    public function test_legacy_array_keeps_old_keys_while_exposing_dto_metrics(): void
    {
        $salesByStore = collect([7 => 250.0]);
        $expensesByStore = collect([7 => 20.0]);
        $ownerPurchasesByStore = collect([7 => 15.0]);
        $internalUseByStore = collect([7 => 5.0]);
        $productsCostByStore = [7 => 80.0];

        $result = new FinancialSummaryResult(collect([
            7 => new StoreFinancialSummary(
                storeId: 7,
                sales: 250.0,
                productsCost: 80.0,
                expenses: 20.0,
                ownerPurchases: 15.0,
                internalUse: 5.0,
            ),
        ]));

        $legacy = $result->toLegacyArray(
            $salesByStore,
            $productsCostByStore,
            $expensesByStore,
            $ownerPurchasesByStore,
            $internalUseByStore,
        );

        $this->assertSame($salesByStore, $legacy['sales_by_store']);
        $this->assertSame($productsCostByStore, $legacy['products_cost_by_store']);
        $this->assertSame($expensesByStore, $legacy['expenses_by_store']);
        $this->assertSame($ownerPurchasesByStore, $legacy['owner_purchases_by_store']);
        $this->assertSame($internalUseByStore, $legacy['internal_use_by_store']);
        $this->assertSame(130.0, $legacy['metrics_by_store'][7]['profit']);
        $this->assertSame(20.0, $legacy['metrics_by_store'][7]['purchases_and_internal_use']);
        $this->assertSame($legacy['metrics_by_store'][7], $legacy['totals']);
    }
}
