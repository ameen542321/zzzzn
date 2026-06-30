<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Store;
use App\Models\Product;
use App\Models\CreditSale;
use App\Models\Expense;
use App\Models\Withdrawal;
use App\Support\ProductProfitCostCalculator;
use App\Support\PaymentTypeLabel;
use App\Services\Shifts\ShiftWindowService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailySalesController extends Controller
{
    private array $processedSalesCache = [];

    public function index(Store $store, Request $request)
    {
        $selectedDate = $request->filled('date')
            ? Carbon::parse($request->date)->startOfDay()
            : Carbon::today()->startOfDay();

        $shiftWindowService = app(ShiftWindowService::class);
        $shiftWindows = $shiftWindowService->forDate($store->id, $selectedDate);

        // fallback آمن: إذا لا توجد شفتات مغلقة/حالية نرجع لفترة يومية تقويمية
        if ($shiftWindows->isEmpty()) {
            $shiftWindows = $shiftWindowService->calendarFallback($selectedDate);
        }

        $startTime = $shiftWindows->first()['start'];
        $endTime = $shiftWindows->last()['end'];
        $selectedShift = $shiftWindows->contains(fn($shiftWindow) => ($shiftWindow['source'] ?? null) === 'balance') ? 'shift_based' : null;

        $buildSalesWithItemsQuery = function () use ($store) {
            return Sale::where('sales.store_id', $store->id)
            ->where(function ($salesDescriptionQuery) {
                $salesDescriptionQuery->whereNull('sales.description')
                    ->orWhere('sales.description', '!=', 'manual_invoice_entry');
            })
            ->leftJoin('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
            ->select(
                'sales.*',
                'sale_items.id as item_id',
                'sale_items.product_id',
                'sale_items.quantity as item_quantity',
                'sale_items.price as item_price',
                'sale_items.total as item_total',
                'sale_items.cost_price as item_cost_price',
                'sale_items.total_cost as item_total_cost',
                'sale_items.is_custom',
                'sale_items.custom_name',
                'sale_items.custom_consumption',
                'sale_items.custom_meters',
                'products.name as product_name',
                'products.cost_price as product_cost_price',
                'products.product_type as product_type',
                'products.is_splittable as product_is_splittable',
                'products.items_per_unit as product_items_per_unit',
                'products.roll_length as product_roll_length'
            )
            ->with(['employee', 'accountant']);
        };

        // استعلام المبيعات مع المنتجات
        $salesWithItemsQuery = $buildSalesWithItemsQuery();

        $shiftWindowService->applySalePeriodFilter($salesWithItemsQuery, $shiftWindows);

        // فلترة البحث (رقم العملية / اسم المنتج / اسم العنصر المخصص / وصف العملية)
        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $salesWithItemsQuery->where(function ($searchQuery) use ($search) {
                if (is_numeric($search)) {
                    $searchQuery->orWhere('sales.id', (int) $search);
                }

                $searchQuery->orWhere('products.name', 'like', "%{$search}%")
                    ->orWhere('sale_items.custom_name', 'like', "%{$search}%")
                    ->orWhere('sales.description', 'like', "%{$search}%");
            });
        }

        // تنفيذ الاستعلام
        $saleRows = $salesWithItemsQuery->orderBy('sales.created_at', 'desc')->get();

        // fallback: إذا الشفتات المغلقة أعادت نتائج صفرية نرجع للفترة اليومية لنفس التاريخ
        if ($saleRows->isEmpty() && $shiftWindows->contains(fn($shiftWindow) => ($shiftWindow['source'] ?? null) === 'balance')) {
            $shiftWindows = $shiftWindowService->calendarFallback($selectedDate, 'calendar_fallback');

            $startTime = $shiftWindows->first()['start'];
            $endTime = $shiftWindows->last()['end'];
            $selectedShift = null;

            $salesWithItemsQuery = $buildSalesWithItemsQuery();
            $shiftWindowService->applySalePeriodFilter($salesWithItemsQuery, $shiftWindows);

            if ($request->filled('search')) {
                $search = trim((string) $request->search);

                $salesWithItemsQuery->where(function ($searchQuery) use ($search) {
                    if (is_numeric($search)) {
                        $searchQuery->orWhere('sales.id', (int) $search);
                    }

                    $searchQuery->orWhere('products.name', 'like', "%{$search}%")
                        ->orWhere('sale_items.custom_name', 'like', "%{$search}%")
                        ->orWhere('sales.description', 'like', "%{$search}%");
                });
            }

            $saleRows = $salesWithItemsQuery->orderBy('sales.created_at', 'desc')->get();
        }

        // إعادة تجميع النتائج حسب كل عملية بيع
        $sales = collect();
        $groupedSale = null;

        foreach ($saleRows as $saleItemRow) {
            if (!$groupedSale || $groupedSale->id != $saleItemRow->id) {
                if ($groupedSale) {
                    $processed = $this->processSale($groupedSale);
                    $processed->shift_key = $shiftWindowService->resolveShiftKey($processed, $shiftWindows);
                    $sales->push($processed);
                }

                $groupedSale = clone $saleItemRow;
                $groupedSale->items = collect();
                $groupedSale->total_cost = 0;
                $groupedSale->total_profit = 0;
                $groupedSale->products_total_value = 0;
            }

            if ($saleItemRow->item_id) {
                $groupedSale->items->push((object)[
                    'id' => $saleItemRow->item_id,
                    'product_id' => $saleItemRow->product_id,
                    'quantity' => $saleItemRow->item_quantity,
                    'price' => $saleItemRow->item_price,
                    'total' => $saleItemRow->item_total,
                    'cost_price_at_sale' => $saleItemRow->item_cost_price,
                    'total_cost_at_sale' => $saleItemRow->item_total_cost,
                    'is_custom' => $saleItemRow->is_custom,
                    'custom_name' => $saleItemRow->custom_name,
                    'custom_consumption' => $saleItemRow->custom_consumption,
                    'custom_meters' => $saleItemRow->custom_meters,
                    'product_name' => $saleItemRow->product_name ?? 'منتج غير معروف',
                    'cost_price' => $saleItemRow->product_cost_price ?? 0,
                    'product_type' => $saleItemRow->product_type,
                    'is_splittable' => (bool) ($saleItemRow->product_is_splittable ?? false),
                    'items_per_unit' => (float) ($saleItemRow->product_items_per_unit ?? 0),
                    'roll_length' => (float) ($saleItemRow->product_roll_length ?? 0)
                ]);

                $groupedSale->products_total_value += $saleItemRow->item_total ?? 0;
            }
        }

        if ($groupedSale) {
            $processed = $this->processSale($groupedSale);
            $processed->shift_key = $shiftWindowService->resolveShiftKey($processed, $shiftWindows);
            $sales->push($processed);
        }

        $visibleSaleIds = $sales->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $collectionOperations = $this->getCreditCollectionOperations($store->id, $shiftWindows, $visibleSaleIds);
        $sales = $sales
            ->concat($collectionOperations)
            ->sortByDesc(fn ($entry) => $entry->display_time ?? $entry->created_at ?? now())
            ->values();

        $employees = $store->employees()->select('id', 'name')->orderBy('name')->get();

        $shiftSummaries = $shiftWindows->map(function ($shiftWindow) use ($sales, $store, $shiftWindowService) {
            return $this->buildShiftSummary($shiftWindow, $sales, $store, $shiftWindowService);
        })->sort(function ($firstShiftSummary, $secondShiftSummary) {
            $firstIsOpen = ($firstShiftSummary['source'] ?? null) === 'open_shift';
            $secondIsOpen = ($secondShiftSummary['source'] ?? null) === 'open_shift';

            if ($firstIsOpen !== $secondIsOpen) {
                return $firstIsOpen ? -1 : 1;
            }

            return $secondShiftSummary['start']->getTimestamp() <=> $firstShiftSummary['start']->getTimestamp();
        })->values();

        // الإحصائيات العامة عبر كل الشفتات ضمن الفترة المختارة
        $stats = [
            'total' => $shiftSummaries->sum(fn($shiftSummary) => $shiftSummary['stats']['total']),
            'total_cost' => $shiftSummaries->sum(fn($shiftSummary) => $shiftSummary['stats']['total_cost']),
            'total_profit' => $shiftSummaries->sum(fn($shiftSummary) => $shiftSummary['stats']['total_profit']),
            'deferred_profit' => $shiftSummaries->sum(fn($shiftSummary) => $shiftSummary['stats']['deferred_profit']),
            'cash_sales' => $shiftSummaries->sum(fn($shiftSummary) => $shiftSummary['stats']['cash_sales']),
            'card_sales' => $shiftSummaries->sum(fn($shiftSummary) => $shiftSummary['stats']['card_sales']),
            'tadlil_total' => $shiftSummaries->sum(fn($shiftSummary) => $shiftSummary['stats']['tadlil_total'] ?? 0),
            'tadlil_count' => $shiftSummaries->sum(fn($shiftSummary) => $shiftSummary['stats']['tadlil_count'] ?? 0),
            'tadlil_names' => $shiftSummaries
                ->flatMap(fn($shiftSummary) => $shiftSummary['stats']['tadlil_names'] ?? collect())
                ->filter()
                ->unique()
                ->values(),
            'collected_total' => $shiftSummaries->sum(fn($shiftSummary) => $shiftSummary['stats']['collected_total']),
            'expenses' => $shiftSummaries->sum(fn($shiftSummary) => $shiftSummary['stats']['expenses']),
            'withdrawals' => $shiftSummaries->sum(fn($shiftSummary) => $shiftSummary['stats']['withdrawals']),
            'outgoing_total' => $shiftSummaries->sum(fn($shiftSummary) => $shiftSummary['stats']['outgoing_total']),
            'count' => $sales->count(),
            'products_count' => $sales->filter(fn($sale) => ($sale->operation_kind ?? null) !== 'collection' && $sale->items->isNotEmpty())->count(),
            'labor_count' => $sales->filter(fn($sale) => ($sale->operation_kind ?? null) !== 'collection' && $sale->items->isEmpty())->count(),
            'shift_count' => $shiftSummaries->count(),
        ];

        return view('user.stores.daily', compact('store', 'sales', 'stats', 'startTime', 'endTime', 'selectedShift', 'shiftSummaries', 'employees'));
    }

    private function buildShiftSummary(array $shiftWindow, $sales, Store $store, ShiftWindowService $shiftWindowService): array
    {
        $operationsInShift = $sales->filter(fn($sale) => ($sale->shift_key ?? 'default_shift') === $shiftWindow['key']);
        $saleOperationsInShift = $operationsInShift->filter(fn($sale) => ($sale->operation_kind ?? null) !== 'collection');
        $collectionOperationsInShift = $operationsInShift->filter(fn($sale) => ($sale->operation_kind ?? null) === 'collection');
        $tadlilOperationsInShift = $saleOperationsInShift->filter(
            fn ($sale) => !empty($sale->tint_operation_name)
        );

        $cashSalesAmount = $saleOperationsInShift->sum(fn ($sale) => $this->cashAmountForSale($sale));
        $cardSalesAmount = $saleOperationsInShift->sum(fn ($sale) => $this->cardAmountForSale($sale));
        $creditCollectionAmount = $collectionOperationsInShift->sum(
            fn($sale) => (float) ($sale->cash_paid ?? 0) + (float) ($sale->card_paid ?? 0)
        );

        $shiftExpenseQuery = Expense::where('store_id', $store->id)
            ->where('actor_type', '!=', 'owner_purchase');
        $shiftWindowService->applyOperationWindowFilter($shiftExpenseQuery, $shiftWindow);
        $shiftExpensesAmount = (float) $shiftExpenseQuery->sum('amount');

        $shiftWithdrawalQuery = Withdrawal::where('store_id', $store->id);
        $shiftWindowService->applyOperationWindowFilter($shiftWithdrawalQuery, $shiftWindow);
        $shiftWithdrawalsAmount = (float) $shiftWithdrawalQuery->sum('amount');

        $summaryStats = [
            'total' => $saleOperationsInShift->sum(fn ($sale) => $this->operationTotalForSale($sale)),
            'total_cost' => $saleOperationsInShift->sum('total_cost'),
            'total_profit' => $saleOperationsInShift->sum('recognized_profit'),
            'deferred_profit' => $saleOperationsInShift->sum('deferred_profit'),
            'cash_sales' => $cashSalesAmount,
            'card_sales' => $cardSalesAmount,
            'credit_collections' => $creditCollectionAmount,
            'tadlil_total' => $tadlilOperationsInShift->sum(fn ($sale) => $this->tadlilWorkAmount($sale)),
            'tadlil_count' => $tadlilOperationsInShift->count(),
            'tadlil_names' => $tadlilOperationsInShift
                ->pluck('tint_operation_name')
                ->filter()
                ->unique()
                ->values(),
            'collected_total' => $operationsInShift->sum('paid_amount'),
            'expenses' => $shiftExpensesAmount,
            'withdrawals' => $shiftWithdrawalsAmount,
            'outgoing_total' => $shiftExpensesAmount + $shiftWithdrawalsAmount,
            'count' => $operationsInShift->count(),
        ];

        return [
            'key' => $shiftWindow['key'],
            'label' => $shiftWindow['label'],
            'start' => $shiftWindow['start'],
            'end' => $shiftWindow['end'],
            'source' => $shiftWindow['source'] ?? null,
            'notes' => $shiftWindow['notes'] ?? null,
            'stats' => $summaryStats,
        ];
    }

    private function cashAmountForSale(object $sale): float
    {
        $cashPaidAmount = (float) ($sale->cash_paid ?? 0);
        if ($cashPaidAmount > 0) {
            return $cashPaidAmount;
        }

        if (($sale->sale_type ?? null) === 'cash') {
            return (float) ($sale->paid_amount ?? 0);
        }

        if (($sale->sale_type ?? null) === 'mixed') {
            return (float) ($sale->cash_amount ?? 0);
        }

        return 0;
    }

    private function cardAmountForSale(object $sale): float
    {
        $cardPaidAmount = (float) ($sale->card_paid ?? 0);
        if ($cardPaidAmount > 0) {
            return $cardPaidAmount;
        }

        if (($sale->sale_type ?? null) === 'card') {
            return (float) ($sale->paid_amount ?? 0);
        }

        if (($sale->sale_type ?? null) === 'mixed') {
            return (float) ($sale->card_amount ?? 0);
        }

        return 0;
    }

    private function operationTotalForSale(object $sale): float
    {
        $splitPaymentTotal = (float) ($sale->cash_paid ?? 0) + (float) ($sale->card_paid ?? 0);
        if ($splitPaymentTotal <= 0) {
            $splitPaymentTotal = (float) ($sale->cash_amount ?? 0) + (float) ($sale->card_amount ?? 0);
        }

        return max($splitPaymentTotal, (float) ($sale->paid_amount ?? 0));
    }

    private function tadlilWorkAmount(object $sale): float
    {
        $operationTotal = (float) ($sale->operation_total ?? $sale->final_total ?? 0);
        $productsTotal = (float) ($sale->products_total_value ?? 0);

        return max(0, $operationTotal - $productsTotal);
    }

    private function getCreditCollectionOperations(int $storeId, $shiftWindows, array $visibleSaleIds = [])
    {
        $shiftWindowService = app(ShiftWindowService::class);
        $startTime = $shiftWindows->first()['start'] ?? now()->startOfDay();
        $endTime = $shiftWindows->last()['end'] ?? now();

        $collections = DB::table('employee_credit_sales')
            ->leftJoin('employees', 'employee_credit_sales.person_id', '=', 'employees.id')
            ->where('employee_credit_sales.store_id', $storeId)
            ->whereNull('employee_credit_sales.deleted_at')
            ->where('employee_credit_sales.created_at', '<=', $endTime)
            ->whereColumn('employee_credit_sales.remaining_amount', '<', 'employee_credit_sales.amount')
            ->select(
                'employee_credit_sales.id',
                'employee_credit_sales.amount',
                'employee_credit_sales.remaining_amount',
                'employee_credit_sales.partial_payments',
                'employee_credit_sales.updated_at',
                'employee_credit_sales.created_at',
                'employee_credit_sales.description',
                'employees.name as employee_name'
            )
            ->get();

        return $collections->flatMap(function ($collection) use ($shiftWindows, $startTime, $endTime, $storeId, $visibleSaleIds, $shiftWindowService) {
            $linkedSaleId = $this->extractLinkedSaleId((string) ($collection->description ?? ''));
            if ($linkedSaleId && in_array($linkedSaleId, $visibleSaleIds, true)) {
                return collect();
            }

            $allPayments = $this->extractCollectionPayments($collection);
            $payments = array_values(array_filter(
                $allPayments,
                fn ($payment) => ($payment['date'] ?? null) >= $startTime && ($payment['date'] ?? null) <= $endTime
            ));

            return collect($payments)->map(function ($payment, $index) use ($collection, $shiftWindows, $storeId, $shiftWindowService) {
                $profitBreakdown = $this->calculateCollectionProfitBreakdown($storeId, $collection, $payment);

                $operation = (object) [
                    'id' => 'collection-' . $collection->id . '-' . $index,
                    'store_id' => null,
                    'items' => collect(),
                    'description' => $collection->description,
                    'internal_notes' => null,
                    'employee_name' => $collection->employee_name ?: 'غير معروف',
                    'employee_id' => null,
                    'operation_kind' => 'collection',
                    'sale_type' => 'collection',
                    'has_partial_credit' => false,
                    'paid_amount' => (float) ($payment['amount'] ?? 0),
                    'remaining_amount' => 0,
                    'cash_amount' => (float) ($payment['amount'] ?? 0),
                    'card_amount' => 0,
                    'cash_paid' => (float) ($payment['amount'] ?? 0),
                    'card_paid' => 0,
                    'labor_total' => 0,
                    'total' => (float) ($payment['amount'] ?? 0),
                    'final_total' => (float) ($payment['amount'] ?? 0),
                    'operation_total' => (float) ($payment['amount'] ?? 0),
                    'total_cost' => 0,
                    'products_profit' => 0,
                    'total_profit' => (float) ($profitBreakdown['recognized_profit'] ?? 0),
                    'recognized_profit' => (float) ($profitBreakdown['recognized_profit'] ?? 0),
                    'deferred_profit' => (float) ($profitBreakdown['deferred_profit_remaining'] ?? 0),
                    'profit_is_deferred' => (bool) ($profitBreakdown['has_deferred_profit'] ?? false),
                    'payment_label' => 'تحصيل',
                    'employee' => null,
                    'accountant' => null,
                    'created_at' => Carbon::parse($payment['date']),
                    'updated_at' => Carbon::parse($payment['date']),
                    'display_time' => Carbon::parse($payment['date']),
                ];

                $operation->shift_key = $shiftWindowService->resolveShiftKey($operation, $shiftWindows);

                return $operation;
            });
        })->values();
    }

    private function extractCollectionPayments($collection): array
    {
        $payments = [];
        $partialPayments = $collection->partial_payments;

        if (is_string($partialPayments)) {
            $partialPayments = json_decode($partialPayments, true);
        }

        if (is_array($partialPayments) && !empty($partialPayments)) {
            foreach ($partialPayments as $payment) {
                $paymentDate = isset($payment['date']) ? Carbon::parse($payment['date']) : null;
                if (!$paymentDate) {
                    continue;
                }

                $payments[] = [
                    'amount' => (float) ($payment['amount'] ?? 0),
                    'date' => $paymentDate,
                ];
            }
        }

        if (empty($payments)) {
            $updatedAt = Carbon::parse($collection->updated_at);
            $payments[] = [
                'amount' => (float) $collection->amount - (float) $collection->remaining_amount,
                'date' => $updatedAt,
            ];
        }

        usort($payments, fn ($a, $b) => Carbon::parse($a['date'])->getTimestamp() <=> Carbon::parse($b['date'])->getTimestamp());

        return array_values(array_filter($payments, fn ($payment) => ($payment['amount'] ?? 0) > 0));
    }

    private function calculateCollectionProfitBreakdown(int $storeId, $collection, array $targetPayment): array
    {
        $linkedSaleId = $this->extractLinkedSaleId((string) ($collection->description ?? ''));
        if (!$linkedSaleId) {
            return [
                'cost_component' => 0,
                'recognized_profit' => 0,
                'deferred_profit_remaining' => 0,
                'has_deferred_profit' => false,
            ];
        }

        $sale = $this->getProcessedSaleById($storeId, $linkedSaleId);
        if (!$sale) {
            return [
                'cost_component' => 0,
                'recognized_profit' => 0,
                'deferred_profit_remaining' => 0,
                'has_deferred_profit' => false,
            ];
        }

        $allPayments = $this->extractCollectionPayments($collection);
        $initialCollectedAmount = max(0, (float) (($sale->cash_amount ?? 0) + ($sale->card_amount ?? 0)));
        $totalCost = max(0, (float) ($sale->total_cost ?? 0));
        $finalProfit = max(0, (float) ($sale->operation_total ?? 0) - $totalCost);

        $collectedBefore = $initialCollectedAmount;
        foreach ($allPayments as $payment) {
            $samePayment = (float) ($payment['amount'] ?? 0) === (float) ($targetPayment['amount'] ?? 0)
                && Carbon::parse($payment['date'])->equalTo(Carbon::parse($targetPayment['date']));

            if ($samePayment) {
                break;
            }

            $collectedBefore += (float) ($payment['amount'] ?? 0);
        }

        $collectedAfter = $collectedBefore + (float) ($targetPayment['amount'] ?? 0);

        $coveredCostBefore = min($totalCost, $collectedBefore);
        $coveredCostAfter = min($totalCost, $collectedAfter);
        $costComponent = max(0, $coveredCostAfter - $coveredCostBefore);

        $recognizedProfitBefore = max(0, $collectedBefore - $totalCost);
        $recognizedProfitAfter = max(0, $collectedAfter - $totalCost);
        $recognizedProfit = max(0, min($finalProfit, $recognizedProfitAfter) - min($finalProfit, $recognizedProfitBefore));
        $deferredProfitRemaining = max(0, $finalProfit - min($finalProfit, $recognizedProfitAfter));

        return [
            'cost_component' => $costComponent,
            'recognized_profit' => $recognizedProfit,
            'deferred_profit_remaining' => $deferredProfitRemaining,
            'has_deferred_profit' => $deferredProfitRemaining > 0,
        ];
    }

    private function extractLinkedSaleId(string $description): ?int
    {
        if (preg_match('/#(\d+)/', $description, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function getProcessedSaleById(int $storeId, int $saleId)
    {
        if (array_key_exists($saleId, $this->processedSalesCache)) {
            return $this->processedSalesCache[$saleId];
        }

        $saleItemRows = Sale::where('sales.store_id', $storeId)
            ->where('sales.id', $saleId)
            ->where(function ($salesDescriptionQuery) {
                $salesDescriptionQuery->whereNull('sales.description')
                    ->orWhere('sales.description', '!=', 'manual_invoice_entry');
            })
            ->leftJoin('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
            ->select(
                'sales.*',
                'sale_items.id as item_id',
                'sale_items.product_id',
                'sale_items.quantity as item_quantity',
                'sale_items.price as item_price',
                'sale_items.total as item_total',
                'sale_items.cost_price as item_cost_price',
                'sale_items.total_cost as item_total_cost',
                'sale_items.is_custom',
                'sale_items.custom_name',
                'sale_items.custom_consumption',
                'sale_items.custom_meters',
                'products.name as product_name',
                'products.cost_price as product_cost_price',
                'products.product_type as product_type',
                'products.is_splittable as product_is_splittable',
                'products.items_per_unit as product_items_per_unit',
                'products.roll_length as product_roll_length'
            )
            ->orderBy('sale_items.id')
            ->get();

        if ($saleItemRows->isEmpty()) {
            return $this->processedSalesCache[$saleId] = null;
        }

        $processedSaleWithItems = clone $saleItemRows->first();
        $processedSaleWithItems->items = collect();
        $processedSaleWithItems->total_cost = 0;
        $processedSaleWithItems->total_profit = 0;
        $processedSaleWithItems->products_total_value = 0;

        foreach ($saleItemRows as $saleItemRow) {
            if (!$saleItemRow->item_id) {
                continue;
            }

            $processedSaleWithItems->items->push((object) [
                'id' => $saleItemRow->item_id,
                'product_id' => $saleItemRow->product_id,
                'quantity' => $saleItemRow->item_quantity,
                'price' => $saleItemRow->item_price,
                'total' => $saleItemRow->item_total,
                'cost_price_at_sale' => $saleItemRow->item_cost_price,
                'total_cost_at_sale' => $saleItemRow->item_total_cost,
                'is_custom' => $saleItemRow->is_custom,
                'custom_name' => $saleItemRow->custom_name,
                'custom_consumption' => $saleItemRow->custom_consumption,
                'custom_meters' => $saleItemRow->custom_meters,
                'product_name' => $saleItemRow->product_name ?? 'منتج غير معروف',
                'cost_price' => $saleItemRow->product_cost_price ?? 0,
                'product_type' => $saleItemRow->product_type,
                'is_splittable' => (bool) ($saleItemRow->product_is_splittable ?? false),
                'items_per_unit' => (float) ($saleItemRow->product_items_per_unit ?? 0),
                'roll_length' => (float) ($saleItemRow->product_roll_length ?? 0),
            ]);
        }

        return $this->processedSalesCache[$saleId] = $this->processSale($processedSaleWithItems);
    }

    public function update(Store $store, Sale $sale, Request $request)
    {
        if ($sale->store_id !== $store->id) {
            abort(403, 'هذه العملية لا تنتمي لهذا المتجر');
        }

        $validated = $request->validate([
            'sale_type'   => 'required|in:cash,card,credit,mixed',
            'paid_amount' => 'required|numeric|min:0',
            'labor_total' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:1000',
            'cash_amount' => 'nullable|numeric|min:0',
            'card_amount' => 'nullable|numeric|min:0',
            'employee_id' => 'nullable|exists:employees,id',
            'debt_amount' => 'nullable|numeric|min:0',
            'item_ids' => 'nullable|array',
            'item_ids.*' => 'required|integer|distinct',
            'item_quantities' => 'nullable|array',
            'item_quantities.*' => 'required|numeric|min:0.01',
            'item_prices' => 'nullable|array',
            'item_prices.*' => 'required|numeric|min:0',
        ]);

        $originalSaleType = $sale->sale_type;
        $submittedItemIds = array_values($validated['item_ids'] ?? []);
        $submittedQuantities = array_values($validated['item_quantities'] ?? []);
        $submittedPrices = array_values($validated['item_prices'] ?? []);
        $hasItemEdits = count($submittedItemIds) > 0;
        $itemEditPlan = collect();
        $productsTotal = (float) ($sale->products_total ?? 0);
        $productsCost = 0.0;

        if ($hasItemEdits) {
            if (count($submittedItemIds) !== count($submittedQuantities)
                || count($submittedItemIds) !== count($submittedPrices)) {
                return back()->withErrors([
                    'item_ids' => 'بيانات المنتجات المرسلة غير مكتملة. أعد فتح نافذة التعديل وحاول مرة أخرى.',
                ])->withInput()->with('edit_sale_modal', $sale->id);
            }

            $saleItems = $sale->items()
                ->with('product')
                ->whereIn('id', $submittedItemIds)
                ->get()
                ->keyBy('id');

            if ($saleItems->count() !== count($submittedItemIds)) {
                return back()->withErrors([
                    'item_ids' => 'يوجد منتج لا يتبع هذه العملية أو لم يعد موجودًا.',
                ])->withInput()->with('edit_sale_modal', $sale->id);
            }

            foreach ($submittedItemIds as $index => $itemId) {
                $item = $saleItems->get((int) $itemId);
                $product = $item?->product;
                $newQuantity = (float) $submittedQuantities[$index];
                $newPrice = round((float) $submittedPrices[$index], 2);
                $isFractional = ($product?->product_type ?? null) === 'fractional';

                if (!$product || (int) $product->store_id !== (int) $store->id) {
                    return back()->withErrors([
                        'item_ids' => 'تعذر العثور على المنتج المرتبط بأحد أسطر العملية داخل هذا المتجر.',
                    ])->withInput()->with('edit_sale_modal', $sale->id);
                }

                if ($isFractional && abs($newQuantity - (float) $item->quantity) > 0.0001) {
                    return back()->withErrors([
                        'item_quantities' => 'لا يمكن تغيير كمية منتج رول/تضليل من هذه النافذة لأن استهلاكه محفوظ بالأمتار. يمكن تعديل سعر البيع فقط.',
                    ])->withInput()->with('edit_sale_modal', $sale->id);
                }

                if (!$isFractional && abs($newQuantity - round($newQuantity)) > 0.0001) {
                    return back()->withErrors([
                        'item_quantities' => 'كمية المنتج العادي يجب أن تكون عددًا صحيحًا.',
                    ])->withInput()->with('edit_sale_modal', $sale->id);
                }

                $storedQuantity = $isFractional ? (float) $item->quantity : (int) round($newQuantity);
                $oldStoredQuantity = max((float) ($item->quantity ?? 0), 0.0001);
                $oldStockQuantity = (float) ($item->custom_consumption ?? $item->quantity);
                $stockPerSaleUnit = $oldStockQuantity / $oldStoredQuantity;
                $stockQuantity = $isFractional
                    ? $oldStockQuantity
                    : $stockPerSaleUnit * $storedQuantity;
                $lineTotal = round($newPrice * $storedQuantity, 2);
                $lineCost = round(ProductProfitCostCalculator::calculateItemCost($product, [
                    'quantity' => $storedQuantity,
                    'custom_consumption' => $isFractional ? $stockQuantity : null,
                    'unit_type' => $item->unit_type ?? 'unit',
                ]), 2);

                $itemEditPlan->push([
                    'item' => $item,
                    'product' => $product,
                    'quantity' => $storedQuantity,
                    'old_stock_quantity' => $oldStockQuantity,
                    'new_stock_quantity' => $stockQuantity,
                    'price' => $newPrice,
                    'total' => $lineTotal,
                    'cost_price' => (float) ($product->cost_price ?? 0),
                    'total_cost' => $lineCost,
                ]);
            }

            $productsTotal = round($itemEditPlan->sum('total'), 2);
            $productsCost = round($itemEditPlan->sum('total_cost'), 2);
        }

        $taxRate = (float) ($sale->tax_rate ?? 0);
        $laborTotal = (float) ($validated['labor_total'] ?? 0);

        $taxAmount = $productsTotal * ($taxRate / 100);
        $finalTotal = $productsTotal + $taxAmount + $laborTotal;

        $enteredAmount = (float) $validated['paid_amount'];
        $enteredDebtAmount = (float) ($validated['debt_amount'] ?? 0);
        $selectedEmployeeId = $validated['employee_id'] ?? $sale->employee_id;
        $paidAmount = $enteredAmount;
        $cashAmount = 0.0;
        $cardAmount = 0.0;
        $storedOperationAmount = (float) (($sale->paid_amount ?? 0) + ($sale->remaining_amount ?? 0));
        $operationAmountBeforePaymentEdit = $hasItemEdits ? $finalTotal : max($finalTotal, $storedOperationAmount);
        $hasCollectedCreditConversion = $originalSaleType === 'credit'
            && (float) ($sale->paid_amount ?? 0) > 0
            && (float) ($sale->remaining_amount ?? 0) > 0
            && $validated['sale_type'] !== 'credit';
        $alreadyCollectedAmount = $hasCollectedCreditConversion ? (float) ($sale->paid_amount ?? 0) : 0.0;
        $editableOperationAmount = $hasCollectedCreditConversion ? (float) ($sale->remaining_amount ?? 0) : $operationAmountBeforePaymentEdit;
        $protectedCashAmount = $hasCollectedCreditConversion ? $alreadyCollectedAmount : 0.0;

        if (!empty($validated['employee_id'])) {
            $employeeBelongsToStore = $store->employees()->where('id', $validated['employee_id'])->exists();
            if (!$employeeBelongsToStore) {
                return back()->withErrors(['employee_id' => 'الموظف المختار لا يتبع هذا المتجر.'])->withInput()->with('edit_sale_modal', $sale->id);
            }
        }

        if ($validated['sale_type'] === 'cash') {
            $cashEditableAmount = $hasCollectedCreditConversion ? $editableOperationAmount : $enteredAmount;
            $paidAmount = $alreadyCollectedAmount + $cashEditableAmount;
            $remainingAmount = 0;
            $cashAmount = $protectedCashAmount + $cashEditableAmount;
        } elseif ($validated['sale_type'] === 'card') {
            $cardEditableAmount = $hasCollectedCreditConversion ? $editableOperationAmount : $enteredAmount;
            $paidAmount = $alreadyCollectedAmount + $cardEditableAmount;
            $remainingAmount = 0;
            $cashAmount = $protectedCashAmount;
            $cardAmount = $cardEditableAmount;
        } elseif ($validated['sale_type'] === 'mixed') {
            $hasCashInput = $request->filled('cash_amount');
            $hasCardInput = $request->filled('card_amount');
            $hasDebtInput = $request->filled('debt_amount');
            $isCreditToMixedConversion = $originalSaleType === 'credit';
            $debtAmount = max(0, $enteredDebtAmount);

            if ($isCreditToMixedConversion && !($hasCashInput || $hasCardInput)) {
                return back()->withErrors([
                    'sale_type' => 'عند التحويل من آجل إلى ميكس يجب إدخال توزيع الكاش/الشبكة صراحة.'
                ])->withInput()->with('edit_sale_modal', $sale->id);
            }

            if ($hasCashInput || $hasCardInput) {
                $newCashAmount = (float) ($validated['cash_amount'] ?? 0);
                $newCardAmount = (float) ($validated['card_amount'] ?? 0);
                $cashAmount = $protectedCashAmount + $newCashAmount;
                $cardAmount = $newCardAmount;
                $paidAmount = $alreadyCollectedAmount + $newCashAmount + $newCardAmount;

                if (($newCashAmount + $newCardAmount) <= 0) {
                    return back()->withErrors([
                        'paid_amount' => 'في عملية الميكس يجب أن يكون مجموع الكاش والشبكة أكبر من صفر.'
                    ])->withInput()->with('edit_sale_modal', $sale->id);
                }
            } else {
                // fallback محافظ للحالات غير الآجلة القديمة فقط
                $cashAmount = $protectedCashAmount + $paidAmount;
                $cardAmount = 0;
            }

            if ($debtAmount < 0 || $debtAmount > $editableOperationAmount) {
                return back()->withErrors(['debt_amount' => 'قيمة المديونية يجب أن تكون بين صفر وقيمة العملية الأساسية.'])->withInput()->with('edit_sale_modal', $sale->id);
            }

            $enteredMixedTotal = max(0, $cashAmount - $protectedCashAmount) + max(0, $cardAmount);

            if (!$hasDebtInput && abs($enteredMixedTotal - $editableOperationAmount) > 0.01) {
                return back()->withErrors([
                    'debt_amount' => 'عند تعديل العملية إلى ميكس يجب أن يساوي (كاش + شبكة) قيمة العملية، أو يتم إدخال المديونية صراحة.'
                ])->withInput()->with('edit_sale_modal', $sale->id);
            }

            $remainingAmount = $debtAmount > 0 ? $debtAmount : ($editableOperationAmount - $enteredMixedTotal);

            if (abs(($enteredMixedTotal + $remainingAmount) - $editableOperationAmount) > 0.01) {
                return back()->withErrors([
                    'debt_amount' => 'في الميكس يجب أن يساوي (كاش + شبكة + مديونية) قيمة العملية الأساسية.'
                ])->withInput()->with('edit_sale_modal', $sale->id);
            }
        } else {
            $remainingAmount = max($enteredDebtAmount, $operationAmountBeforePaymentEdit);

            if (abs($remainingAmount - $operationAmountBeforePaymentEdit) > 0.01) {
                return back()->withErrors([
                    'debt_amount' => 'في الآجل الكامل يجب أن تساوي قيمة المديونية كامل العملية. إذا أردت آجلًا جزئيًا استخدم ميكس.'
                ])->withInput()->with('edit_sale_modal', $sale->id);
            }

            $paidAmount = 0;
            $remainingAmount = $operationAmountBeforePaymentEdit;
        }

        $hasPartialCredit = in_array($validated['sale_type'], ['credit', 'mixed'], true) && $remainingAmount > 0;
        $creditDescriptionSuffix = $validated['sale_type'] === 'credit' ? '' : ' (آجل جزئي)';

        if (($validated['sale_type'] === 'credit' || $remainingAmount > 0) && !$selectedEmployeeId) {
            return back()->withErrors(['employee_id' => 'يجب اختيار الموظف الذي ستضاف عليه المديونية.'])->withInput()->with('edit_sale_modal', $sale->id);
        }

        if ($hasPartialCredit) {
            $hasExistingCredit = CreditSale::where('store_id', $store->id)
                ->where('description', 'like', '%#' . $sale->id . '%')
                ->exists();

            if (!$selectedEmployeeId && !$hasExistingCredit) {
                return back()->withErrors(['sale_type' => 'لا يمكن التحويل إلى ميكس/آجل جزئي بدون موظف مرتبط بهذه العملية.'])->withInput()->with('edit_sale_modal', $sale->id);
            }
        }

        try {
            DB::transaction(function () use ($sale, $store, $validated, $laborTotal, $productsTotal, $productsCost, $finalTotal, $paidAmount, $remainingAmount, $cashAmount, $cardAmount, $hasPartialCredit, $selectedEmployeeId, $creditDescriptionSuffix, $itemEditPlan, $hasItemEdits) {
            foreach ($itemEditPlan as $plannedItem) {
                $item = $plannedItem['item'];
                $product = Product::whereKey($plannedItem['product']->id)->lockForUpdate()->first();

                if (!$product || (int) $product->store_id !== (int) $store->id) {
                    throw ValidationException::withMessages([
                        'item_ids' => 'تعذر قفل المنتج المرتبط بالعملية للتعديل.',
                    ]);
                }

                $stockDifference = round(
                    (float) $plannedItem['new_stock_quantity'] - (float) $plannedItem['old_stock_quantity'],
                    4
                );

                if ($stockDifference > 0 && (float) $product->quantity + 0.0001 < $stockDifference) {
                    throw ValidationException::withMessages([
                        'item_quantities' => 'الكمية المتاحة من المنتج «' . $product->name . '» لا تكفي لزيادة كمية العملية.',
                    ]);
                }

                if ($stockDifference > 0) {
                    $product->decrement('quantity', $stockDifference);
                    $movementType = 'decrease';
                    $movementQuantity = $stockDifference;
                } elseif ($stockDifference < 0) {
                    $movementQuantity = abs($stockDifference);
                    $product->increment('quantity', $movementQuantity);
                    $movementType = 'increase';
                } else {
                    $movementType = null;
                    $movementQuantity = 0;
                }

                if ($movementType) {
                    $product->stockMovements()->create([
                        'store_id' => $store->id,
                        'user_id' => auth()->id(),
                        'product_id' => $product->id,
                        'type' => $movementType,
                        'quantity' => $movementQuantity,
                        'note' => 'تعديل كمية منتج في عملية مبيعات #' . $sale->id,
                    ]);
                }

                $item->update([
                    'quantity' => $plannedItem['quantity'],
                    'price' => $plannedItem['price'],
                    'total' => $plannedItem['total'],
                    'custom_consumption' => $plannedItem['new_stock_quantity'],
                    'cost_price' => $plannedItem['cost_price'],
                    'total_cost' => $plannedItem['total_cost'],
                ]);
            }

            $saleProfit = $hasItemEdits
                ? round($finalTotal - $productsCost, 2)
                : (float) ($sale->profit ?? 0);

            $sale->update([
                'sale_type'          => $validated['sale_type'],
                'products_total'     => $productsTotal,
                'labor_total'        => $laborTotal,
                'description'        => $validated['description'] ?? null,
                'final_total'        => $finalTotal,
                'total'              => $finalTotal,
                'paid_amount'        => $paidAmount,
                'remaining_amount'   => $remainingAmount,
                'cash_amount'        => $cashAmount,
                'card_amount'        => $cardAmount,
                'has_partial_credit' => $hasPartialCredit,
                'profit'             => $saleProfit,
                'employee_id'        => ($validated['sale_type'] === 'credit' || $remainingAmount > 0)
                    ? $selectedEmployeeId
                    : null,
            ]);

            $creditRows = CreditSale::where('store_id', $store->id)
                ->where('description', 'like', '%#' . $sale->id . '%')
                ->orderBy('id')
                ->get();

            if ($hasPartialCredit) {
                $personId = $sale->employee_id ?: optional($creditRows->first())->person_id;

                if (!$personId) {
                    // حارس إضافي، من المفترض تم التحقق منه قبل المعاملة
                    return;
                }

                if ($creditRows->isNotEmpty()) {
                    $first = $creditRows->first();
                    $alreadyCollectedOnCredit = max(
                        0,
                        (float) ($first->amount ?? 0) - (float) ($first->remaining_amount ?? 0)
                    );
                    $updatedCreditAmount = $alreadyCollectedOnCredit + $remainingAmount;

                    $first->update([
                        'person_id' => $personId,
                        'person_type' => \App\Models\Employee::class,
                        'amount' => $updatedCreditAmount,
                        'remaining_amount' => $remainingAmount,
                        'description' => 'مديونية من فاتورة رقم #' . $sale->id . $creditDescriptionSuffix,
                        'date' => now()->format('Y-m-d'),
                        'status' => 'pending',
                        'month' => now()->format('m-Y'),
                        'added_by' => $sale->accountant_id,
                    ]);

                    if ($creditRows->count() > 1) {
                        CreditSale::whereIn('id', $creditRows->slice(1)->pluck('id'))->delete();
                    }
                } else {
                    CreditSale::create([
                        'person_id' => $personId,
                        'person_type' => \App\Models\Employee::class,
                        'store_id' => $store->id,
                        'amount' => $remainingAmount,
                        'remaining_amount' => $remainingAmount,
                        'description' => 'مديونية من فاتورة رقم #' . $sale->id . $creditDescriptionSuffix,
                        'date' => now()->format('Y-m-d'),
                        'status' => 'pending',
                        'month' => now()->format('m-Y'),
                        'added_by' => $sale->accountant_id,
                    ]);
                }
            } else {
                if ($creditRows->isNotEmpty()) {
                    CreditSale::whereIn('id', $creditRows->pluck('id'))->delete();
                }
            }
            });
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->withInput()
                ->with('edit_sale_modal', $sale->id);
        }

        return back()->with('success', 'تم تعديل العملية بنجاح.');
    }

    public function destroy(Store $store, Sale $sale)
    {
        if ($sale->store_id !== $store->id) {
            abort(403, 'هذه العملية لا تنتمي لهذا المتجر');
        }

        DB::transaction(function () use ($sale, $store) {
            $sale->loadMissing(['items.product', 'invoice']);

            foreach ($sale->items as $item) {
                if (!$item->product || $item->product->store_id !== $store->id) {
                    continue;
                }

                $restoreQty = (float) ($item->custom_consumption ?? $item->quantity ?? 0);
                if ($restoreQty <= 0) {
                    continue;
                }

                $product = Product::query()
                    ->whereKey($item->product_id)
                    ->where('store_id', $store->id)
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    continue;
                }

                $product->increaseStock(
                    $restoreQty,
                    'استرجاع مخزون بعد حذف عملية مبيعات #' . $sale->id,
                    auth()->id(),
                    'normalized'
                );
            }

            // حذف الفاتورة المرتبطة إن وجدت
            if ($sale->invoice) {
                $sale->invoice->delete();
            }

            // حذف ناعم للمديونية المرتبطة للحفاظ على سجل التحصيلات الجزئية والتاريخ المالي.
            CreditSale::where('store_id', $store->id)
                ->where('description', 'like', '%#' . $sale->id . '%')
                ->delete();

            $sale->items()->delete();
            // Sale لا يستخدم SoftDeletes حالياً؛ delete هنا حذف نهائي من جدول sales.
            $sale->delete();
        });

        return back()->with('success', 'تم حذف العملية واسترجاع المخزون بنجاح.');
    }

    /**
     * معالجة عملية بيع وحساب التكاليف والأرباح بشكل صحيح
     */
    private function processSale($sale)
    {
        $totalCost = 0;
        $productsProfit = 0;
        $sale->tint_operation_name = $this->extractTintOperationName((string) ($sale->description ?? ''));

        foreach ($sale->items as $item) {
            // اسم المنتج
            if ($item->is_custom && $item->custom_name) {
                $item->display_name = $item->custom_name;
            } else {
                $item->display_name = $item->product_name ?? 'منتج غير معروف';
            }

            // الكمية الأساسية المحسوبة للمخزون
            $stockQuantity = (float) ($item->custom_consumption ?? $item->quantity ?? 0);

            // الكمية/الوحدة المعروضة للمستخدم
            $displayQuantity = (float) ($item->quantity ?? 0);
            $displayUnit = 'وحدة';

            if (!empty($item->custom_meters)) {
                $displayQuantity = (float) $item->custom_meters;
                $displayUnit = 'متر';
            } elseif (($item->product_type ?? null) === 'fractional') {
                $displayUnit = ((float) ($item->roll_length ?? 0) > 0) ? 'رول' : 'متر';
            } elseif (!empty($item->is_splittable)) {
                $itemsPerUnit = (float) ($item->items_per_unit ?? 0);
                $displayUnit = ($itemsPerUnit > 1 && abs($displayQuantity - $stockQuantity) > 0.0001) ? 'حبة' : 'طقم';
            }

            // إجمالي المنتج
            $itemTotal = $item->total ?? ($item->price * $item->quantity);

            // تكلفة المنتج
            $costPrice = (float) (((float) ($item->cost_price_at_sale ?? 0) > 0)
                ? $item->cost_price_at_sale
                : ($item->cost_price ?? 0));

            if ((float) ($item->total_cost_at_sale ?? 0) > 0) {
                // التكلفة حُسبت وحُفظت وقت البيع؛ لا نعيد تفسيرها في صفحة المبيعات.
                $itemCost = (float) $item->total_cost_at_sale;
            } elseif (($item->product_type ?? null) === 'fractional') {
                // fallback للعمليات القديمة فقط التي لا تحتوي total_cost.
                $itemCost = ProductProfitCostCalculator::calculateItemCost([
                    'cost_price' => $costPrice,
                    'product_type' => $item->product_type,
                    'roll_length' => $item->roll_length,
                ], [
                    'quantity' => $item->quantity,
                    'custom_consumption' => $stockQuantity,
                    'unit_type' => 'meter',
                ]);
            } else {
                // العمليات القديمة قبل أعمدة التكلفة تُحسب بالطريقة السابقة: تكلفة الوحدة × الكمية.
                $itemCost = $costPrice * $stockQuantity;
            }

            // ربح المنتج
            $itemProfit = $itemTotal - $itemCost;

            // تخزين القيم المحسوبة
            $item->calculated_cost = $itemCost;
            $item->calculated_profit = $itemProfit;
            $item->display_quantity = $displayQuantity;
            $item->display_unit = $displayUnit;

            $totalCost += $itemCost;
            $productsProfit += $itemProfit;
        }

        // ✅ حساب الربح بناءً على القيمة الأساسية الفعلية للعملية
        $operationTotal = max(
            (float) ($sale->final_total ?? 0),
            (float) (($sale->paid_amount ?? 0) + ($sale->remaining_amount ?? 0))
        );

        $hasOutstandingCredit = ((float) ($sale->remaining_amount ?? 0)) > 0
            && ($sale->sale_type === 'credit' || (int) ($sale->has_partial_credit ?? 0) === 1 || $sale->sale_type === 'mixed');

        $sale->total_cost = $totalCost;
        $sale->products_profit = $productsProfit;
        $sale->operation_total = $operationTotal;
        $sale->total_profit = $operationTotal - $totalCost;
        $sale->recognized_profit = $hasOutstandingCredit ? 0 : ((float) ($sale->paid_amount ?? 0) - $totalCost);
        $sale->deferred_profit = $hasOutstandingCredit ? ($operationTotal - $totalCost) : 0;
        $sale->profit_is_deferred = $hasOutstandingCredit;
        $sale->shift_key = 'default_shift';
        $sale->cash_paid = (float) ($sale->cash_amount ?? 0);
        $sale->card_paid = (float) ($sale->card_amount ?? 0);
        $sale->payment_label = PaymentTypeLabel::dailySalesLabel(
            $sale->sale_type,
            (float) ($sale->remaining_amount ?? 0)
        );

        // ✅ للتأكد: إجمالي المبيعات يجب أن يساوي (المنتجات + شغل اليد + الضريبة)
        // final_total = products_total + labor_total + tax

        return $sale;
    }

    /**
     * استخراج اسم عملية التضليل المحفوظ في وصف البيع لعرضه كاسم العملية.
     */
    private function extractTintOperationName(string $description): ?string
    {
        $tintParts = collect(explode(' - ', trim($description)))
            ->map(fn ($part) => trim($part))
            ->filter(function ($part) {
                return mb_stripos($part, 'تضليل') !== false
                    || mb_stripos($part, 'تظليل') !== false;
            })
            ->values();

        return $tintParts->isEmpty() ? null : $tintParts->implode(' - ');
    }
}