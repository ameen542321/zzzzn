<?php

namespace App\Services\Accounting;

use App\Models\Debt;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\Withdrawal;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\CarbonInterface;

class AccountingOperationFeedService
{
    public function __construct(private readonly AccountingOperationPresenter $presenter)
    {
    }

    public function latestForStore(int $storeId, int $perPage = 10, int $page = 1, ?string $path = null, array $query = []): LengthAwarePaginator
    {
        $sales = Sale::with(['items.product:id,name', 'accountant:id,name'])
            ->where('store_id', $storeId)
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->latest()
            ->take(120)
            ->get()
            ->map(fn ($sale) => $this->presenter->lastOperation($sale, 'sale'));

        $expenses = Expense::with(['user:id,name', 'employee:id,name'])
            ->where('store_id', $storeId)
            ->latest()
            ->take(120)
            ->get()
            ->map(fn ($expense) => $this->presenter->lastOperation($expense, 'expense'));

        $withdrawals = Withdrawal::with(['addedBy:id,name', 'person'])
            ->where('store_id', $storeId)
            ->latest()
            ->take(120)
            ->get()
            ->map(fn ($withdrawal) => $this->presenter->lastOperation($withdrawal, 'withdrawal'));

        $operationRows = $sales->concat($expenses)->concat($withdrawals)
            ->sortByDesc('created_at')
            ->values();

        return new LengthAwarePaginator(
            $operationRows->forPage($page, $perPage)->values(),
            $operationRows->count(),
            $perPage,
            $page,
            ['path' => $path, 'pageName' => 'operations_page', 'query' => $query]
        );
    }

    public function shiftDetails(int $storeId, CarbonInterface $shiftStart, array $creditCollections = [], ?string $businessDate = null): array
    {
        $sales = Sale::with(['items.product:id,name', 'accountant:id,name'])
            ->where('store_id', $storeId)
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->forOpenAccountingShift($businessDate, $shiftStart)
            ->latest()
            ->get()
            ->map(fn ($sale) => $this->presenter->saleDetail($sale));

        $collections = collect($creditCollections['details'] ?? [])
            ->map(fn ($collection) => $this->presenter->creditCollectionDetail($collection));

        $expenses = Expense::with(['user:id,name', 'employee:id,name'])
            ->where('store_id', $storeId)
            ->forOpenAccountingShift($businessDate, $shiftStart)
            ->latest()
            ->get()
            ->map(fn ($expense) => $this->presenter->expenseDetail($expense));

        $withdrawals = Withdrawal::with(['addedBy:id,name', 'person'])
            ->where('store_id', $storeId)
            ->forOpenAccountingShift($businessDate, $shiftStart)
            ->latest()
            ->get()
            ->map(fn ($withdrawal) => $this->presenter->withdrawalDetail($withdrawal));

        $debts = Debt::where('store_id', $storeId)
            ->where('created_at', '>', $shiftStart)
            ->latest()
            ->get()
            ->map(fn ($debt) => $this->presenter->debtDetail($debt));

        $rows = $sales
            ->concat($collections)
            ->concat($expenses)
            ->concat($withdrawals)
            ->concat($debts)
            ->sortByDesc('time')
            ->values();

        return [
            'rows' => $rows,
            'count' => $rows->count(),
            'total_in' => (float) ($sales->sum('amount') + $collections->sum('amount')),
            'total_out' => (float) (
                $expenses->sum('amount')
                + $withdrawals->sum('amount')
                + $debts->sum('amount')
            ),
            'sales_total' => (float) $sales->sum('amount'),
            'cash_total' => (float) $sales->sum('cash_amount'),
            'card_total' => (float) $sales->sum('card_amount'),
            'credit_total' => (float) $sales->where('payment_type', 'آجل')->sum('amount'),
            'collections_total' => (float) $collections->sum('amount'),
            'expenses_total' => (float) $expenses->sum('amount'),
            'withdrawals_total' => (float) $withdrawals->sum('amount'),
            'cost_total' => (float) $sales->sum('cost_amount'),
        ];
    }
}
