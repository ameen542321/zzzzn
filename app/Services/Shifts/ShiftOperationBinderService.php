<?php

namespace App\Services\Shifts;

use App\Models\DailyBalance;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ShiftOperationBinderService
{
    /**
     * يربط عمليات يوم محاسبي غير مرتبطة بعد بشفت مغلق.
     *
     * يستخدم created_at فقط كـ fallback اختياري للبيانات القديمة التي لا تملك business_date.
     */
    public function attachByBusinessDate(
        DailyBalance $balance,
        string $businessDate,
        bool $includeLegacyCreatedAtDate = false,
        bool $excludeManualInvoiceEntries = false
    ): array {
        $date = Carbon::parse($businessDate)->toDateString();
        $payload = [
            'business_date' => $date,
            'daily_balance_id' => $balance->id,
        ];

        $sales = Sale::query()
            ->where('store_id', $balance->store_id)
            ->whereNull('daily_balance_id')
            ->when($excludeManualInvoiceEntries, fn (Builder $query) => $query->excludeManualInvoiceEntries());
        $this->applyBusinessDateFilter($sales, $date, $includeLegacyCreatedAtDate);
        $salesCount = $sales->update($payload);

        $expenses = Expense::query()
            ->where('store_id', $balance->store_id)
            ->whereNull('daily_balance_id');
        $this->applyBusinessDateFilter($expenses, $date, $includeLegacyCreatedAtDate);
        $expensesCount = $expenses->update($payload);

        $withdrawals = Withdrawal::query()
            ->where('store_id', $balance->store_id)
            ->whereNull('daily_balance_id');
        $this->applyBusinessDateFilter($withdrawals, $date, $includeLegacyCreatedAtDate);
        $withdrawalsCount = $withdrawals->update($payload);

        return [
            'sales' => $salesCount,
            'expenses' => $expensesCount,
            'withdrawals' => $withdrawalsCount,
        ];
    }

    /**
     * يربط عمليات نافذة زمنية محددة بشفت مغلق، مع تثبيت business_date للعمليات الجديدة.
     */
    public function attachByWindow(DailyBalance $balance, Carbon $start, Carbon $end, string $businessDate): array
    {
        $date = Carbon::parse($businessDate)->toDateString();
        $payload = [
            'business_date' => $date,
            'daily_balance_id' => $balance->id,
        ];

        return [
            'sales' => Sale::where('store_id', $balance->store_id)
                ->whereBetween('created_at', [$start, $end])
                ->update($payload),
            'expenses' => Expense::where('store_id', $balance->store_id)
                ->whereBetween('created_at', [$start, $end])
                ->update($payload),
            'withdrawals' => Withdrawal::where('store_id', $balance->store_id)
                ->whereBetween('created_at', [$start, $end])
                ->update($payload),
        ];
    }

    /**
     * ينقل التاريخ المحاسبي لكل العمليات المرتبطة بالشفت، ثم يلحق العمليات القديمة غير المرتبطة من نافذته.
     */
    public function moveBalanceOperations(DailyBalance $balance, string $targetBusinessDate, ?string $sourceBusinessDate = null): array
    {
        $date = Carbon::parse($targetBusinessDate)->toDateString();
        $month = Carbon::parse($date)->format('Y-m');

        $counts = [
            'sales' => Sale::where('daily_balance_id', $balance->id)->update(['business_date' => $date]),
            'expenses' => Expense::where('daily_balance_id', $balance->id)->update(['business_date' => $date]),
            'withdrawals' => Withdrawal::where('daily_balance_id', $balance->id)->update([
                'business_date' => $date,
                'date' => $date,
                'month' => $month,
            ]),
        ];

        $start = $balance->start_time ? Carbon::parse($balance->start_time) : null;
        $end = $balance->end_time ? Carbon::parse($balance->end_time) : null;

        if (! $start || ! $end) {
            return $counts;
        }

        $payload = [
            'business_date' => $date,
            'daily_balance_id' => $balance->id,
        ];

        $counts['legacy_sales'] = Sale::where('store_id', $balance->store_id)
            ->whereNull('daily_balance_id')
            ->whereBetween('created_at', [$start, $end])
            ->update($payload);

        $counts['legacy_expenses'] = Expense::where('store_id', $balance->store_id)
            ->whereNull('daily_balance_id')
            ->whereBetween('created_at', [$start, $end])
            ->update($payload);

        $counts['legacy_withdrawals'] = Withdrawal::where('store_id', $balance->store_id)
            ->whereNull('daily_balance_id')
            ->where(function (Builder $query) use ($start, $end, $sourceBusinessDate) {
                $query->whereBetween('created_at', [$start, $end]);

                if ($sourceBusinessDate) {
                    $query->orWhereDate('date', $sourceBusinessDate);
                }
            })
            ->update([
                'business_date' => $date,
                'daily_balance_id' => $balance->id,
                'date' => $date,
                'month' => $month,
            ]);

        return $counts;
    }

    private function applyBusinessDateFilter(Builder $query, string $date, bool $includeLegacyCreatedAtDate): void
    {
        $query->where(function (Builder $dateQuery) use ($date, $includeLegacyCreatedAtDate) {
            $dateQuery->whereDate('business_date', $date);

            if ($includeLegacyCreatedAtDate) {
                $dateQuery->orWhere(function (Builder $legacyQuery) use ($date) {
                    $legacyQuery->whereNull('business_date')
                        ->whereDate('created_at', $date);
                });
            }
        });
    }
}
