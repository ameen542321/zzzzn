<?php

namespace App\Services\Shifts;

use App\Models\DailyBalance;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\ShiftLifecycleService;
use App\Services\Stores\ActiveAccountantService;
use App\Services\Stores\StoreAccessService;

class ShiftGapOverviewService
{
    /**
     * يجهز بيانات صفحة مراجعة الشفتات الناقصة للمالك بدل بنائها داخل StoreController.
     */
    public function ownerOverview(Store $store, User $owner): array
    {
        if (! app(StoreAccessService::class)->isUsableForShiftWorkflow($store)) {
            return [
                'gapRows' => collect(),
                'recentBalances' => collect(),
                'activeAccountants' => collect(),
            ];
        }

        $missingDates = app(ShiftLifecycleService::class)->missingBusinessDates($store->id);
        $gapRows = collect($missingDates)->map(function (string $businessDate) use ($store) {
            $shiftInfo = app(ShiftGapInfoService::class)->shiftInfo($store, $businessDate);
            $missingShiftNumber = (int) $shiftInfo['missing_shift_number'];

            return array_merge(
                [
                    'date' => $businessDate,
                    'request_status' => app(ShiftGapRequestService::class)->activeStatus($store->id, $businessDate, $missingShiftNumber),
                ],
                $shiftInfo,
                $this->operationCounts($store, $businessDate)
            );
        });

        return [
            'gapRows' => $gapRows,
            'recentBalances' => $this->recentClosedBalances($store),
            'activeAccountants' => app(ActiveAccountantService::class)->activeAccountantsForStore($store, $owner),
        ];
    }

    /**
     * يحسب عمليات يوم محاسبي ناقص غير مربوطة بموازنة مغلقة بعد.
     */
    public function operationCounts(Store $store, string $businessDate): array
    {
        return [
            'sales_count' => Sale::where('store_id', $store->id)
                ->where(function ($query) use ($businessDate) {
                    $query->whereDate('business_date', $businessDate)
                        ->orWhere(function ($legacyQuery) use ($businessDate) {
                            $legacyQuery->whereNull('business_date')
                                ->whereDate('created_at', $businessDate);
                        });
                })
                ->whereNull('daily_balance_id')
                ->count(),
            'expenses_count' => Expense::where('store_id', $store->id)
                ->where(function ($query) use ($businessDate) {
                    $query->whereDate('business_date', $businessDate)
                        ->orWhere(function ($legacyQuery) use ($businessDate) {
                            $legacyQuery->whereNull('business_date')
                                ->whereDate('created_at', $businessDate);
                        });
                })
                ->whereNull('daily_balance_id')
                ->count(),
            'withdrawals_count' => Withdrawal::where('store_id', $store->id)
                ->where(function ($query) use ($businessDate) {
                    $query->whereDate('business_date', $businessDate)
                        ->orWhere(function ($legacyQuery) use ($businessDate) {
                            $legacyQuery->whereNull('business_date')
                                ->whereDate('created_at', $businessDate);
                        });
                })
                ->whereNull('daily_balance_id')
                ->count(),
        ];
    }

    /**
     * آخر الإقفالات المعروضة في صفحة مراجعة الشفتات لمساعدة المالك على التدقيق.
     */
    private function recentClosedBalances(Store $store)
    {
        return DailyBalance::where('store_id', $store->id)
            ->whereNotNull('end_time')
            ->latest('end_time')
            ->take(20)
            ->get();
    }
}
