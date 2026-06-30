<?php

namespace App\Services\Stores;

use App\Models\Store;
use App\Models\Sale;
use App\Models\Category;
use App\Models\Employee;

class StoreDetailsService
{
    /**
     * يبني بيانات صفحة تفاصيل المتجر بعيدًا عن StoreController.
     */
    public function build(Store $store): array
    {
        $now = now();
        $currentMonthText = $now->format('Y-m');

        // ===== 1. إحصائيات المخزون =====
        $categoriesCount = $store->categories()->count();
        $productsCount = $store->products()->count();

        $lowStockProducts = $store->products()->lowStock()->get();
        $lowStockCount = $lowStockProducts->count();
        $trashedCount = $store->products()->onlyTrashed()->count();
        $latestProducts = $store->products()->latest()->take(5)->get();

        $latestMovements = \App\Models\StockMovement::where('store_id', $store->id)->latest()->take(5)->get();

        // قيمة المخزون: (الكمية / الطول) * السعر للمتري، أو (الكمية * السعر) للعادي
        $totalInventoryValue = $store->products()->selectRaw('SUM(
            CASE
                WHEN product_type = "fractional" AND roll_length > 0 THEN (quantity / roll_length) * price
                ELSE (quantity * price)
            END
        ) as total_value')->value('total_value') ?? 0;

        $metersAvailable = $store->products()->sum('quantity');

        // ===== 2. إحصائيات الموظفين والديون =====
        $totalEmployees = $store->employees()->count();
        $totalAccountants = $store->accountants()->count();
        $totalMonthlySalaries = $store->employees()->sum('salary') ?? 0;

        $monthlyWithdrawals = \App\Models\Withdrawal::where('store_id', $store->id)->where('month', $currentMonthText)->where('status', 'pending')->sum('amount') ?? 0;
        $monthlyDebts = \App\Models\Debt::where('store_id', $store->id)->where('month', $currentMonthText)->where('status', 'pending')->sum('amount') ?? 0;
        $monthlyAbsences = \App\Models\Absence::where('store_id', $store->id)->where('month', $currentMonthText)->where('status', 'pending')->count();

        $creditSales = \App\Models\CreditSale::where('store_id', $store->id)->where('status', 'pending')->sum('remaining_amount') ?? 0;
        $monthlyCollections = \App\Models\CreditSale::where('store_id', $store->id)->where('status', 'deducted')->where('deducted_month', $currentMonthText)->sum('amount') ?? 0;

        $activeEmployees = $store->employees()->where('status', 'active')->count();
        $suspendedEmployees = $store->employees()->where('status', 'suspended')->count();
        $topSalaries = $store->employees()->orderBy('salary', 'desc')->take(5)->get(['name', 'salary', 'status']);

        // الموظفون الأكثر مديونية وغياباً
        $mostDebtEmployees = \App\Models\Debt::where('store_id', $store->id)->where('status', 'pending')->where('person_type', 'App\\Models\\Employee')->selectRaw('person_id, SUM(amount) as total_debt')->groupBy('person_id')->orderBy('total_debt', 'desc')->take(5)->get()->map(fn($item) => ['name' => \App\Models\Employee::find($item->person_id)->name ?? 'غير معروف', 'total_debt' => $item->total_debt]);
        $mostAbsentEmployees = \App\Models\Absence::where('store_id', $store->id)->where('status', 'pending')->where('person_type', 'App\\Models\\Employee')->selectRaw('person_id, COUNT(*) as absence_count')->groupBy('person_id')->orderBy('absence_count', 'desc')->take(5)->get()->map(fn($item) => ['name' => \App\Models\Employee::find($item->person_id)->name ?? 'غير معروف', 'absence_count' => $item->absence_count]);

        // ===== 3. إحصائيات المبيعات والمصروفات (باستخدام paid_amount كأساس البيع) =====
        $monthlySales = Sale::where('store_id', $store->id)
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->sum('paid_amount');
        $todaySales = Sale::where('store_id', $store->id)
            ->whereDate('created_at', today())
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->sum('paid_amount');

        $cashSales = Sale::where('store_id', $store->id)->where('sale_type', 'cash')->whereMonth('created_at', $now->month)->whereYear('created_at', $now->year)->sum('paid_amount');
        $cardSales = Sale::where('store_id', $store->id)->where('sale_type', 'card')->whereMonth('created_at', $now->month)->whereYear('created_at', $now->year)->sum('paid_amount');
        $creditSalesToday = Sale::where('store_id', $store->id)->where('sale_type', 'credit')->whereMonth('created_at', $now->month)->whereYear('created_at', $now->year)->sum('paid_amount');

        $monthlyExpenses = \App\Models\Expense::where('store_id', $store->id)->whereMonth('created_at', $now->month)->whereYear('created_at', $now->year)->sum('amount');
        $todayExpenses = \App\Models\Expense::where('store_id', $store->id)->whereDate('created_at', today())->sum('amount');

        // ===== 4. إحصائيات الربحية =====
        $monthlyProfit = Sale::where('store_id', $store->id)
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->selectRaw('COALESCE(SUM(paid_amount - ((products_total + labor_total) - profit)), 0) as monthly_profit')
            ->value('monthly_profit');
        $monthlyOperatingExpenses = $monthlyExpenses;
        $totalMonthlyCosts = $totalMonthlySalaries + $monthlyOperatingExpenses;
        $monthlyNetProfit = $monthlyProfit - $totalMonthlyCosts;

        $profitMargin = ($monthlySales > 0) ? ($monthlyNetProfit / $monthlySales) * 100 : 0;
        $dailyAverageProfit = ($now->day > 0) ? ($monthlyNetProfit / $now->day) : $monthlyNetProfit;
        $costToRevenueRatio = ($monthlySales > 0) ? ($totalMonthlyCosts / $monthlySales) * 100 : 0;

        // ===== 5. الموازنات والبيانات الأخرى =====
        $lastBalance = \App\Models\DailyBalance::where('store_id', $store->id)->latest()->first();
        $monthlyDifferences = \App\Models\DailyBalance::where('store_id', $store->id)->whereMonth('created_at', $now->month)->whereYear('created_at', $now->year)->sum('difference');
        $monthlyShifts = \App\Models\DailyBalance::where('store_id', $store->id)->whereMonth('created_at', $now->month)->whereYear('created_at', $now->year)->count();

        $averageProductPrice = $store->products()->avg('price') ?? 0;
        $productsWithoutImages = $store->products()->where(fn($q) => $q->whereNull('image')->orWhere('image', ''))->count();
        $lowStockPercentage = $productsCount > 0 ? round(($lowStockCount / $productsCount) * 100, 2) : 0;
        $monthlyMovements = \App\Models\StockMovement::where('store_id', $store->id)->whereMonth('created_at', $now->month)->whereYear('created_at', $now->year)->count();
        $mostActiveProducts = $store->products()->withCount('stockMovements')->orderBy('stock_movements_count', 'desc')->take(5)->get();
        $categoryStats = $store->categories()->withCount('products')->orderBy('products_count', 'desc')->take(5)->get();
        $todayInvoices = \App\Models\Invoice::whereHas('sale', function ($q) use ($store) {
            $q->where('store_id', $store->id)
                ->where(function ($subQuery) {
                    $subQuery->whereNull('description')
                        ->orWhere('description', '!=', 'manual_invoice_entry');
                });
        })->whereDate('created_at', today())->count();
        $averageInvoiceValue = $todayInvoices > 0 ? $todaySales / $todayInvoices : 0;

        return compact(
            'store', 'categoriesCount', 'productsCount', 'lowStockProducts', 'lowStockCount', 'trashedCount', 'latestProducts', 'latestMovements',
            'totalInventoryValue', 'averageProductPrice', 'productsWithoutImages', 'lowStockPercentage', 'monthlyMovements', 'mostActiveProducts', 'categoryStats',
            'totalEmployees', 'totalAccountants', 'totalMonthlySalaries', 'monthlyWithdrawals', 'monthlyDebts', 'monthlyAbsences', 'creditSales', 'monthlyCollections',
            'activeEmployees', 'suspendedEmployees', 'topSalaries', 'mostDebtEmployees', 'mostAbsentEmployees',
            'todaySales', 'monthlySales', 'todayInvoices', 'averageInvoiceValue', 'cashSales', 'cardSales', 'creditSalesToday',
            'monthlyProfit', 'monthlyOperatingExpenses', 'monthlyNetProfit', 'profitMargin', 'dailyAverageProfit', 'totalMonthlyCosts', 'costToRevenueRatio',
            'monthlyExpenses', 'todayExpenses', 'lastBalance', 'monthlyDifferences', 'monthlyShifts', 'metersAvailable'
        );
    }
}
