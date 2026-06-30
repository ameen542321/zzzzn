<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InternalUseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Store\ExpenseController;
use App\Http\Controllers\Cashier\InvoiceController;
use App\Http\Controllers\Cashier\QuickSaleController;
use App\Http\Controllers\Accountant\DashboardController;
use App\Http\Controllers\Store\EmployeeFinanceController;
use App\Http\Controllers\Accountant\ProductSearchController;
use App\Http\Controllers\Accountant\StoreTransferController as AccountantStoreTransferController;

/*
|--------------------------------------------------------------------------
| مسارات عامة (غير محمية)
|--------------------------------------------------------------------------
*/

// رابط عرض التقرير العام
Route::get('/view-report/{filename}', function ($filename) {
    abort_unless(preg_match('/\A[\pL\pN._-]+\.(pdf|xlsx?|csv)\z/u', $filename), 404, 'الملف غير موجود');

    $reportsDir = realpath(storage_path('app/public/reports'));
    abort_unless($reportsDir, 404, 'الملف غير موجود');

    $path = realpath($reportsDir . DIRECTORY_SEPARATOR . $filename);
    abort_unless($path && str_starts_with($path, $reportsDir . DIRECTORY_SEPARATOR) && is_file($path), 404, 'الملف غير موجود');

    return response()->file($path);
})->where('filename', '[^/]+')->name('pdf.report.view');

// صفحة الإيقاف
Route::get('/suspended', fn() => view('accountant.suspended'))
    ->name('accountant.suspended');

// تسجيل الخروج (يجب أن يكون عامًا)
Route::post('/accountant/logout', [LoginController::class, 'logout'])
    ->name('accountant.logout');


/*
|--------------------------------------------------------------------------
| مسارات المحاسب المحمية
|--------------------------------------------------------------------------
*/
Route::middleware(['accountant.unified'])->group(function () {
    Route::prefix('accountant')->name('accountant.')->group(function () {

        // --- مسارات الفواتير ---
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/create', [InvoiceController::class, 'createInvoice'])->name('invoices.invoice.create');
        Route::post('/invoices/store', [InvoiceController::class, 'storeInvoice'])->name('invoices.store');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'edit'])->name('invoices.show'); // للتعديل والعرض
        Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

        // --- مسارات الاستخدام الداخلي ---
        Route::prefix('internal-use')->name('internal-use.')->group(function () {
            Route::get('/report', [InternalUseController::class, 'report'])->name('report');
            Route::get('/internal-use', [InternalUseController::class, 'create'])->name('create');
            Route::post('/internal-use', [InternalUseController::class, 'store'])->name('store');
            Route::get('/products/search', [InternalUseController::class, 'searchProducts'])->name('products.search');
        });

        // --- مسارات لوحة التحكم والديون العامة ---
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/shift-gaps/{log}/activate', [DashboardController::class, 'activateShiftGap'])->name('shift-gaps.activate');
        Route::delete('/shift-gaps/active', [DashboardController::class, 'clearShiftGap'])->name('shift-gaps.clear');
        Route::post('/balance/store', [DashboardController::class, 'storeBalance'])->name('balance.store');
        Route::get('/debts/{id}', [EmployeeFinanceController::class, 'getDebts'])->name('debts.list');

        // --- مسارات البيع السريع والتضليل ---
        Route::get('/quick-sale', [QuickSaleController::class, 'index'])->name('quick-sale.index');

        // مدخل محمي للمحاسب إلى صفحة معاينة التضليل المستقلة.
        Route::get('/quick-sale/tint-preview', function () {
            // نمرر رابط البيانات المولّد من Laravel بدل أن تخمّن صفحة HTML مسار التطبيق.
            // هذا يحافظ على المسار الصحيح حتى عند تشغيل المشروع داخل مجلد فرعي مثل /public.
            $previewUrl = asset('tint-sale-preview.html');
            $productsUrl = route('accountant.quick-sale.tint-preview-products', [], false);

            return redirect()->to($previewUrl . '?' . http_build_query([
                'products_url' => $productsUrl,
                'auto_open' => 1,
            ]));
        })->name('quick-sale.tint-preview');

        Route::get('/quick-sale/tint-preview-products', [QuickSaleController::class, 'tintPreviewProducts'])->name('quick-sale.tint-preview-products');
        Route::get('/quick-sale/credit-persons', [QuickSaleController::class, 'creditPersons'])->name('quick-sale.credit-persons');
        Route::post('/quick-sale/submit', [QuickSaleController::class, 'submit'])->name('quick-sale.submit');
        Route::get('/quick-sale/invoice/{sale}', [InvoiceController::class, 'create'])->name('quick-sale.invoice.create');
        Route::post('/quick-sale/invoice/{sale}/store', [InvoiceController::class, 'store'])->name('quick-sale.invoice.store');
        Route::get('/quick-sale/invoice/{invoice}/print', [InvoiceController::class, 'print'])->name('quick-sale.invoice.print');
        Route::get('quick-sale/invoice/{invoice}/pdf', [InvoiceController::class, 'downloadPDF'])->name('quick-sale.invoice.pdf');

        // --- مسارات التحويلات المخزنية ---
        Route::prefix('transfers')->name('transfers.')->group(function () {
            Route::get('/', [AccountantStoreTransferController::class, 'index'])->name('index');
            Route::get('/create', [AccountantStoreTransferController::class, 'create'])->name('create');
            Route::post('/', [AccountantStoreTransferController::class, 'store'])->name('store');
            Route::post('/{transfer}/approve', [AccountantStoreTransferController::class, 'approve'])->name('approve');
            Route::post('/{transfer}/reject', [AccountantStoreTransferController::class, 'reject'])->name('reject');
            Route::post('/{transfer}/cancel', [AccountantStoreTransferController::class, 'cancel'])->name('cancel');
            Route::post('/{transfer}/seen', [AccountantStoreTransferController::class, 'markSeen'])->name('seen');
        });

        // --- مسارات البحث عن المنتجات ---
        Route::get('/products/search', [App\Http\Controllers\Cashier\ProductSearchController::class, 'search'])->name('products.search');

        // --- مسارات قسم الـ POS (نقطة البيع والعمليات المالية للموظفين) ---
        Route::prefix('pos')->name('pos.')->group(function () {
            Route::get('/withdrawal', [EmployeeFinanceController::class, 'withdrawalPage'])->name('withdrawal.page');
            Route::post('/withdrawal/store/{employee}', [EmployeeFinanceController::class, 'storeWithdrawal'])->name('withdrawal.store');

            Route::get('/expense', [ExpenseController::class, 'index'])->name('expense.page');
            Route::post('/expense/store', [ExpenseController::class, 'store'])->name('expense.store');
            Route::get('/expense/export-pdf', [ExpenseController::class, 'exportPdf'])->name('expense.export-pdf');
            Route::delete('/expense/{id}', [ExpenseController::class, 'destroy'])->name('expense.destroy');

            Route::get('/credit-sale', [EmployeeFinanceController::class, 'creditSalePage'])->name('credit-sale.page');
            Route::post('/credit-sale/store/{employee}', [EmployeeFinanceController::class, 'storeCreditSale'])->name('credit-sale.store');

            Route::get('/collection', [EmployeeFinanceController::class, 'collectionPage'])->name('collection.page');
            Route::post('/collection/store/{sale}', [EmployeeFinanceController::class, 'storeCollection'])->name('collection.store');

            Route::get('/debt', [EmployeeFinanceController::class, 'debtPage'])->name('debt.page');
            Route::post('/debt/store/{employee}', [EmployeeFinanceController::class, 'storeDebt'])->name('debt.store');
            Route::get('/searchProduct', [ProductController::class, 'indexpos'])->name('searchProduct');

            Route::post('/debt/collect/full/{debt}', [EmployeeFinanceController::class, 'collectFull'])->name('debt.collect.full');
            Route::post('/debt/collect/partial/{debt}', [EmployeeFinanceController::class, 'collectPartial'])->name('debt.collect.partial');

            Route::get('/absence', [EmployeeFinanceController::class, 'absencePage'])->name('absence.page');
            Route::post('/absence/store/{employee}', [EmployeeFinanceController::class, 'storeAbsence'])->name('absence.store');
        });

        // --- مسارات إجراءات الموظفين ---
        Route::prefix('employees')->name('employees.')->group(function () {
            Route::get('/{employee}/actions', [App\Http\Controllers\EmployeeActionsController::class, 'index'])->name('actions');
            Route::post('/{employee}/absence', [App\Http\Controllers\EmployeeActionsController::class, 'storeAbsence'])->name('absence.store');
            Route::post('/{employee}/debt', [App\Http\Controllers\EmployeeActionsController::class, 'storeDebt'])->name('debt.store');
        });

        // --- مسارات الإشعارات ---
        Route::prefix('notifications')->name('notifications.')->group(function () {
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::get('/{id}', [NotificationController::class, 'show'])->name('show');
            Route::post('/{id}/toggle', [NotificationController::class, 'toggle'])->name('toggle');
            Route::post('/{id}/read', [NotificationController::class, 'markAsRead'])->name('read');
            Route::post('/mark-all', [NotificationController::class, 'markAll'])->name('markAll');
            Route::post('/mark-selected', [NotificationController::class, 'markSelected'])->name('markSelected');

            // تم تمييز روابط دالات الحذف لتعمل بشكل منفصل وبدون تعارض
            Route::delete('/{id}/delete', [NotificationController::class, 'delete'])->name('delete');
            Route::delete('/{id}/remove', [NotificationController::class, 'remov'])->name('remov');
        });
    });
});
