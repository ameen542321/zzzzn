<?php

namespace App\Services\Users;

use App\Services\Accounting\FinancialSummaryService;
use Illuminate\Support\Collection;

class OwnerDashboardViewService
{
    public function storeBreakdowns(Collection $stores, array $monthlyMetrics, Collection $salariesByStore, array $includedSaleTypes): array
    {
        $storeIds = $stores->pluck('id');
        $dailyFinancialSummary = app(FinancialSummaryService::class)->storeSummariesForPeriod(
            $storeIds,
            today()->startOfDay(),
            today()->endOfDay(),
            $includedSaleTypes
        );

        return $stores->map(function ($store) use ($dailyFinancialSummary, $salariesByStore, $monthlyMetrics) {
            $storeId = $store->id;
            $dailyMetrics = $dailyFinancialSummary->summariesByStore->get($storeId);
            $salesToday = (float) ($dailyMetrics?->sales ?? 0);
            $productsCostToday = (float) ($dailyMetrics?->productsCost ?? 0);
            $month = $monthlyMetrics[$storeId] ?? [];

            return array_merge([
                'store_id' => $storeId,
                'store_name' => $store->name,
                // المصروفات تعرض منفصلة ولا تخصم من ربح اليوم.
                'profit_today' => $salesToday - $productsCostToday,
                'sales_today' => $salesToday,
                'expenses_today' => (float) ($dailyMetrics?->expenses ?? 0),
                'products_cost_today' => $productsCostToday,
                'salaries_month' => (float) ($salariesByStore[$storeId] ?? 0),
            ], $month);
        })->values()->all();
    }
}
