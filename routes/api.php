<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\BranchController;
use App\Http\Controllers\API\ProjectController;
use App\Http\Controllers\API\ProjectBlockController;
use App\Http\Controllers\API\PropertyController;
use App\Http\Controllers\API\PropertyImageController;
use App\Http\Controllers\API\PropertyDocumentController;
use App\Http\Controllers\API\PropertyFeatureController;
use App\Http\Controllers\API\PropertyStatusController;
use App\Http\Controllers\API\CustomerController;
use App\Http\Controllers\API\LeadController;
use App\Http\Controllers\API\SiteVisitController;
use App\Http\Controllers\API\BookingController;
use App\Http\Controllers\API\BookingStatusController;
use App\Http\Controllers\API\InstallmentPlanController;
use App\Http\Controllers\API\InstallmentController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\CommissionController;
use App\Http\Controllers\API\SalesDashboardController;
use App\Http\Controllers\API\ExpenseController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\RoleController;
use App\Http\Controllers\API\FinancialAuditController;
use App\Http\Controllers\API\SalesReportController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\BookingDocumentController;
use App\Http\Controllers\API\CompanySettingController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\FinancialDocumentController;
use App\Http\Controllers\API\ChartOfAccountController;
use App\Http\Controllers\API\FiscalYearController;
use App\Http\Controllers\API\JournalEntryController;
use App\Http\Controllers\API\AccountingReportController;
use App\Http\Controllers\API\BankAccountController;
use App\Http\Controllers\API\BankTransactionController;
use App\Http\Controllers\API\BankStatementImportController;
use App\Http\Controllers\API\BankReconciliationController;
use App\Http\Controllers\API\BankReconciliationAdjustmentController;
use App\Http\Controllers\API\AccountsReceivableController;
use App\Http\Controllers\API\VendorController;
use App\Http\Controllers\API\AccountsPayableController;

Route::prefix('auth')->group(function () { Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1'); });
Route::get('app-settings', [CompanySettingController::class, 'publicSettings'])->middleware('throttle:60,1');
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('profile', [ProfileController::class, 'show']); Route::put('profile', [ProfileController::class, 'update']); Route::put('profile/password', [ProfileController::class, 'password']); Route::post('profile/photo', [ProfileController::class, 'photo']);
    Route::prefix('auth')->group(function () { Route::get('/me', [AuthController::class, 'me']); Route::post('/logout', [AuthController::class, 'logout']); });
    Route::get('sales-agents', [UserController::class, 'salesAgents'])->middleware('permission:sales.view|leads.create|leads.edit|site_visits.create|site_visits.edit|commissions.create|properties.create|properties.edit');
    Route::get('users/roles', [UserController::class, 'roles'])->middleware('permission:users.view');
    Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view'); Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create'); Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view'); Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit'); Route::patch('users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit'); Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view'); Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view'); Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view'); Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create'); Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.edit'); Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.edit'); Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
    Route::get('company-settings', [CompanySettingController::class, 'show'])->middleware('permission:settings.view'); Route::put('company-settings', [CompanySettingController::class, 'update'])->middleware('permission:settings.edit'); Route::post('company-settings/logo', [CompanySettingController::class, 'logo'])->middleware('permission:settings.edit');
    Route::get('branches', [BranchController::class, 'index'])->middleware('permission:dashboard.view'); Route::post('branches', [BranchController::class, 'store'])->middleware('permission:settings.edit'); Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('permission:dashboard.view'); Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('permission:settings.edit'); Route::patch('branches/{branch}', [BranchController::class, 'update'])->middleware('permission:settings.edit'); Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('permission:settings.edit');
    Route::get('projects', [ProjectController::class, 'index'])->middleware('permission:projects.view'); Route::post('projects', [ProjectController::class, 'store'])->middleware('permission:projects.create'); Route::get('projects/{project}', [ProjectController::class, 'show'])->middleware('permission:projects.view'); Route::put('projects/{project}', [ProjectController::class, 'update'])->middleware('permission:projects.edit'); Route::patch('projects/{project}', [ProjectController::class, 'update'])->middleware('permission:projects.edit'); Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->middleware('permission:projects.delete');
    Route::get('project-blocks', [ProjectBlockController::class, 'index'])->middleware('permission:projects.view'); Route::post('project-blocks', [ProjectBlockController::class, 'store'])->middleware('permission:projects.create'); Route::get('project-blocks/{projectBlock}', [ProjectBlockController::class, 'show'])->middleware('permission:projects.view'); Route::put('project-blocks/{projectBlock}', [ProjectBlockController::class, 'update'])->middleware('permission:projects.edit'); Route::patch('project-blocks/{projectBlock}', [ProjectBlockController::class, 'update'])->middleware('permission:projects.edit'); Route::delete('project-blocks/{projectBlock}', [ProjectBlockController::class, 'destroy'])->middleware('permission:projects.delete');
    Route::get('properties/inventory', [PropertyController::class, 'inventory'])->middleware('permission:properties.view'); Route::get('properties', [PropertyController::class, 'index'])->middleware('permission:properties.view'); Route::post('properties', [PropertyController::class, 'store'])->middleware('permission:properties.create'); Route::get('properties/{property}', [PropertyController::class, 'show'])->middleware('permission:properties.view'); Route::put('properties/{property}', [PropertyController::class, 'update'])->middleware('permission:properties.edit'); Route::patch('properties/{property}', [PropertyController::class, 'update'])->middleware('permission:properties.edit'); Route::delete('properties/{property}', [PropertyController::class, 'destroy'])->middleware('permission:properties.delete');
    Route::put('properties/{property}/status', [PropertyStatusController::class, 'update'])->middleware('permission:properties.edit'); Route::get('properties/{property}/status-history', [PropertyStatusController::class, 'history'])->middleware('permission:properties.view'); Route::post('properties/{property}/images', [PropertyImageController::class, 'store'])->middleware('permission:properties.create'); Route::delete('property-images/{image}', [PropertyImageController::class, 'destroy'])->middleware('permission:properties.delete'); Route::put('property-images/{image}/primary', [PropertyImageController::class, 'primary'])->middleware('permission:properties.edit'); Route::post('properties/{property}/documents', [PropertyDocumentController::class, 'store'])->middleware('permission:documents.create'); Route::get('property-documents/{document}/download', [PropertyDocumentController::class, 'download'])->middleware('permission:properties.view'); Route::delete('property-documents/{document}', [PropertyDocumentController::class, 'destroy'])->middleware('permission:documents.delete'); Route::post('properties/{property}/features', [PropertyFeatureController::class, 'store'])->middleware('permission:properties.create'); Route::delete('property-features/{feature}', [PropertyFeatureController::class, 'destroy'])->middleware('permission:properties.delete');
    Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view'); Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:customers.create'); Route::get('customers/{customer}/ledger', [CustomerController::class, 'ledger'])->middleware('permission:customers.view'); Route::get('customers/{customer}/statement', [CustomerController::class, 'statement'])->middleware('permission:customers.view'); Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view'); Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.edit'); Route::patch('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.edit'); Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete');
    Route::get('leads', [LeadController::class, 'index'])->middleware('permission:leads.view'); Route::post('leads', [LeadController::class, 'store'])->middleware('permission:leads.create'); Route::get('leads/{lead}', [LeadController::class, 'show'])->middleware('permission:leads.view'); Route::put('leads/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.edit'); Route::patch('leads/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.edit'); Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->middleware(['permission:leads.edit','permission:customers.create']); Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->middleware('permission:leads.delete');
    Route::get('site-visits', [SiteVisitController::class, 'index'])->middleware('permission:site_visits.view'); Route::post('site-visits', [SiteVisitController::class, 'store'])->middleware('permission:site_visits.create'); Route::get('site-visits/{siteVisit}', [SiteVisitController::class, 'show'])->middleware('permission:site_visits.view'); Route::put('site-visits/{siteVisit}', [SiteVisitController::class, 'update'])->middleware('permission:site_visits.edit'); Route::patch('site-visits/{siteVisit}', [SiteVisitController::class, 'update'])->middleware('permission:site_visits.edit'); Route::delete('site-visits/{siteVisit}', [SiteVisitController::class, 'destroy'])->middleware('permission:site_visits.delete');
    Route::get('bookings/{booking}/accounting', [\App\Http\Controllers\API\BookingAccountingController::class, 'show'])->middleware('permission:accounting.view');
    Route::post('bookings/{booking}/recognize-revenue', [\App\Http\Controllers\API\BookingAccountingController::class, 'recognize'])->middleware('permission:accounting.edit');
    Route::get('bookings', [BookingController::class, 'index'])->middleware('permission:sales.view'); Route::post('bookings', [BookingController::class, 'store'])->middleware('permission:sales.create'); Route::get('bookings/{booking}/documents', [BookingDocumentController::class, 'index'])->middleware('permission:sales.view'); Route::post('bookings/{booking}/documents', [BookingDocumentController::class, 'store'])->middleware('permission:documents.create'); Route::get('booking-documents/{document}/download', [BookingDocumentController::class, 'download'])->middleware('permission:sales.view'); Route::delete('booking-documents/{document}', [BookingDocumentController::class, 'destroy'])->middleware('permission:documents.delete'); Route::get('bookings/{booking}', [BookingController::class, 'show'])->middleware('permission:sales.view'); Route::put('bookings/{booking}', [BookingController::class, 'update'])->middleware('permission:sales.edit'); Route::patch('bookings/{booking}', [BookingController::class, 'update'])->middleware('permission:sales.edit'); Route::delete('bookings/{booking}', [BookingController::class, 'destroy'])->middleware('permission:sales.delete'); Route::post('bookings/{booking}/confirm', [BookingStatusController::class, 'confirm'])->middleware('permission:sales.edit'); Route::post('bookings/{booking}/cancel', [BookingStatusController::class, 'cancel'])->middleware('permission:sales.edit'); Route::post('bookings/{booking}/complete', [BookingStatusController::class, 'complete'])->middleware('permission:sales.edit');
    Route::get('notifications', [NotificationController::class, 'index'])->middleware('permission:dashboard.view|installments.view|leads.view|site_visits.view|commissions.view');
    Route::get('sales/dashboard', [SalesDashboardController::class, 'index'])->middleware('permission:dashboard.view'); Route::get('sales/receivables', [SalesDashboardController::class, 'receivables'])->middleware('permission:reports.view');
    Route::get('installment-plans', [InstallmentPlanController::class, 'index'])->middleware('permission:installments.view'); Route::post('installment-plans', [InstallmentPlanController::class, 'store'])->middleware('permission:installments.create'); Route::get('installment-plans/{installmentPlan}', [InstallmentPlanController::class, 'show'])->middleware('permission:installments.view'); Route::put('installment-plans/{installmentPlan}', [InstallmentPlanController::class, 'update'])->middleware('permission:installments.edit'); Route::patch('installment-plans/{installmentPlan}', [InstallmentPlanController::class, 'update'])->middleware('permission:installments.edit'); Route::delete('installment-plans/{installmentPlan}', [InstallmentPlanController::class, 'destroy'])->middleware('permission:installments.delete');
    Route::get('installments', [InstallmentController::class, 'index'])->middleware('permission:installments.view'); Route::get('installments/{installment}', [InstallmentController::class, 'show'])->middleware('permission:installments.view');
    Route::get('payments/posting-accounts', [PaymentController::class, 'postingAccounts'])->middleware('permission:payments.create|accounting.view');
    Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payments.view'); Route::post('payments', [PaymentController::class, 'store'])->middleware('permission:payments.create'); Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payments.view'); Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->middleware('permission:payments.view'); Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('permission:payments.edit');
    Route::get('financial-audits/export/{format}', [FinancialAuditController::class, 'export'])->middleware('permission:reports.view');
    Route::get('financial-audits', [FinancialAuditController::class, 'index'])->middleware('permission:reports.view');

    Route::get('accounting/bank-accounts/options', [BankAccountController::class, 'options'])->middleware('permission:accounting.view');
    Route::get('accounting/bank-accounts', [BankAccountController::class, 'index'])->middleware('permission:accounting.view');
    Route::post('accounting/bank-accounts', [BankAccountController::class, 'store'])->middleware('permission:accounting.create');
    Route::put('accounting/bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->middleware('permission:accounting.edit');
    Route::patch('accounting/bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->middleware('permission:accounting.edit');
    Route::delete('accounting/bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])->middleware('permission:accounting.delete');

    Route::get('accounting/bank-transactions/options', [BankTransactionController::class, 'options'])->middleware('permission:accounting.view');
    Route::get('accounting/bank-transactions', [BankTransactionController::class, 'index'])->middleware('permission:accounting.view');
    Route::post('accounting/bank-transactions', [BankTransactionController::class, 'store'])->middleware('permission:accounting.create');
    Route::put('accounting/bank-transactions/{bankTransaction}', [BankTransactionController::class, 'update'])->middleware('permission:accounting.edit');
    Route::patch('accounting/bank-transactions/{bankTransaction}', [BankTransactionController::class, 'update'])->middleware('permission:accounting.edit');

    Route::get('accounting/bank-statement-imports', [BankStatementImportController::class, 'index'])->middleware('permission:accounting.view');
    Route::post('accounting/bank-statement-imports/preview', [BankStatementImportController::class, 'preview'])->middleware('permission:accounting.create');
    Route::post('accounting/bank-statement-imports/{bankStatementImport}/preview', [BankStatementImportController::class, 'refreshPreview'])->middleware('permission:accounting.create');
    Route::post('accounting/bank-statement-imports/{bankStatementImport}/commit', [BankStatementImportController::class, 'commit'])->middleware('permission:accounting.create');

    Route::get('accounting/bank-reconciliations/options', [BankReconciliationController::class, 'options'])->middleware('permission:accounting.view');
    Route::get('accounting/bank-reconciliations/workspace', [BankReconciliationController::class, 'workspace'])->middleware('permission:accounting.view');
    Route::get('accounting/bank-reconciliations', [BankReconciliationController::class, 'index'])->middleware('permission:accounting.view');
    Route::post('accounting/bank-statement-imports/{bankStatementImport}/auto-match', [BankReconciliationController::class, 'autoMatch'])->middleware('permission:accounting.edit');
    Route::get('accounting/bank-transactions/{bankTransaction}/reconciliation-candidates', [BankReconciliationController::class, 'candidates'])->middleware('permission:accounting.view');
    Route::post('accounting/bank-transactions/{bankTransaction}/reconciliation-match', [BankReconciliationController::class, 'match'])->middleware('permission:accounting.edit');
    Route::delete('accounting/bank-transactions/{bankTransaction}/reconciliation-match', [BankReconciliationController::class, 'unmatch'])->middleware('permission:accounting.edit');
    Route::post('accounting/bank-reconciliations/finalize', [BankReconciliationController::class, 'finalize'])->middleware('permission:accounting.edit');
    Route::post('accounting/bank-reconciliations/{bankReconciliation}/reopen', [BankReconciliationController::class, 'reopen'])->middleware('permission:accounting.edit');

    Route::get('accounting/bank-transactions/{bankTransaction}/reconciliation-adjustment-options', [BankReconciliationAdjustmentController::class, 'options'])->middleware('permission:accounting.view');
    Route::post('accounting/bank-transactions/{bankTransaction}/reconciliation-adjustment', [BankReconciliationAdjustmentController::class, 'store'])->middleware('permission:accounting.edit');
    Route::post('accounting/bank-reconciliation-adjustments/{bankReconciliationAdjustment}/reverse', [BankReconciliationAdjustmentController::class, 'reverse'])->middleware('permission:accounting.edit');

    Route::get('accounting/accounts-receivable/options', [AccountsReceivableController::class, 'options'])->middleware('permission:accounting.view');
    Route::get('accounting/accounts-receivable/aging', [AccountsReceivableController::class, 'aging'])->middleware('permission:accounting.view');
    Route::get('accounting/accounts-receivable/customers/{customer}', [AccountsReceivableController::class, 'customer'])->middleware('permission:accounting.view');

    Route::get('vendors', [VendorController::class, 'index'])->middleware('permission:vendors.view');
    Route::post('vendors', [VendorController::class, 'store'])->middleware('permission:vendors.create');
    Route::put('vendors/{vendor}', [VendorController::class, 'update'])->middleware('permission:vendors.edit');
    Route::patch('vendors/{vendor}', [VendorController::class, 'update'])->middleware('permission:vendors.edit');

    Route::get('accounting/accounts-payable/options', [AccountsPayableController::class, 'options'])->middleware('permission:accounting.view');
    Route::get('accounting/accounts-payable/aging', [AccountsPayableController::class, 'aging'])->middleware('permission:accounting.view');
    Route::get('accounting/accounts-payable', [AccountsPayableController::class, 'index'])->middleware('permission:accounting.view');
    Route::post('accounting/accounts-payable', [AccountsPayableController::class, 'store'])->middleware('permission:accounting.create');
    Route::post('accounting/accounts-payable/{vendorBill}/payments', [AccountsPayableController::class, 'pay'])->middleware('permission:accounting.edit');
    Route::post('accounting/accounts-payable/payments/{vendorBillPayment}/reverse', [AccountsPayableController::class, 'reversePayment'])->middleware('permission:accounting.edit');
    Route::post('accounting/accounts-payable/{vendorBill}/cancel', [AccountsPayableController::class, 'cancel'])->middleware('permission:accounting.edit');

    Route::get('accounting/report-options', [AccountingReportController::class, 'options'])->middleware('permission:accounting.view');
    Route::get('accounting/general-ledger', [AccountingReportController::class, 'ledger'])->middleware('permission:accounting.view');
    Route::get('accounting/statement-options', [\App\Http\Controllers\API\FinancialStatementController::class, 'options'])->middleware('permission:accounting.view');
    Route::get('accounting/statements/export', [\App\Http\Controllers\API\FinancialStatementController::class, 'export'])->middleware('permission:accounting.view');
    Route::get('accounting/statements', [\App\Http\Controllers\API\FinancialStatementController::class, 'show'])->middleware('permission:accounting.view');
    Route::get('accounting/trial-balance', [AccountingReportController::class, 'trialBalance'])->middleware('permission:accounting.view');
    Route::get('accounting/chart-of-accounts', [ChartOfAccountController::class, 'index'])->middleware('permission:accounting.view');
    Route::get('accounting/fiscal-years', [FiscalYearController::class, 'index'])->middleware('permission:accounting.view');
    Route::post('accounting/fiscal-years', [FiscalYearController::class, 'store'])->middleware('permission:accounting.create');
    Route::patch('accounting/fiscal-years/{fiscalYear}/status', [FiscalYearController::class, 'status'])->middleware('permission:accounting.edit');
    Route::patch('accounting/periods/{accountingPeriod}/status', [FiscalYearController::class, 'periodStatus'])->middleware('permission:accounting.edit');
    Route::get('accounting/journal-entries', [JournalEntryController::class, 'index'])->middleware('permission:accounting.view');
    Route::post('accounting/journal-entries', [JournalEntryController::class, 'store'])->middleware('permission:accounting.create');
    Route::get('accounting/journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])->middleware('permission:accounting.view');
    Route::post('accounting/journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])->middleware('permission:accounting.edit');
    Route::post('accounting/journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->middleware('permission:accounting.edit');
    Route::post('accounting/chart-of-accounts', [ChartOfAccountController::class, 'store'])->middleware('permission:accounting.create');
    Route::put('accounting/chart-of-accounts/{chartOfAccount}', [ChartOfAccountController::class, 'update'])->middleware('permission:accounting.edit');
    Route::patch('accounting/chart-of-accounts/{chartOfAccount}', [ChartOfAccountController::class, 'update'])->middleware('permission:accounting.edit');
    Route::delete('accounting/chart-of-accounts/{chartOfAccount}', [ChartOfAccountController::class, 'destroy'])->middleware('permission:accounting.delete');
    Route::get('financial-documents/{type}/{id}', [FinancialDocumentController::class, 'index'])
        ->where(['type'=>'payment|installment|commission|expense','id'=>'[0-9]+']);
    Route::post('financial-documents/{type}/{id}', [FinancialDocumentController::class, 'store'])
        ->where(['type'=>'payment|installment|commission|expense','id'=>'[0-9]+']);
    Route::get('financial-document-files/{document}', [FinancialDocumentController::class, 'download'])
        ->whereNumber('document');
    Route::delete('financial-documents/{document}', [FinancialDocumentController::class, 'destroy'])
        ->whereNumber('document');

    Route::prefix('reports')->middleware('permission:reports.view')->group(function () {
        Route::get('projects', [SalesReportController::class, 'projects']);
        Route::get('summary', [SalesReportController::class, 'summary']);
        Route::get('sales', [SalesReportController::class, 'sales']);
        Route::get('collections', [SalesReportController::class, 'collections']);
        Route::get('installments', [SalesReportController::class, 'installments']);
        Route::get('expenses', [SalesReportController::class, 'expenses']);
        Route::get('commissions', [SalesReportController::class, 'commissions']);
        Route::get('export/{type}/{format}', [SalesReportController::class, 'export']);
    });

    Route::get('commissions/posting-accounts', [CommissionController::class, 'postingAccounts'])->middleware('permission:commissions.edit|accounting.view');
    Route::post('commissions/{commission}/recognize', [CommissionController::class, 'recognize'])->middleware('permission:commissions.edit');
    Route::get('commissions', [CommissionController::class, 'index'])->middleware('permission:commissions.view'); Route::post('commissions', [CommissionController::class, 'store'])->middleware('permission:commissions.create'); Route::post('commissions/{commission}/reverse', [CommissionController::class, 'reverse'])->middleware('permission:commissions.edit'); Route::put('commissions/{commission}', [CommissionController::class, 'update'])->middleware('permission:commissions.edit'); Route::patch('commissions/{commission}', [CommissionController::class, 'update'])->middleware('permission:commissions.edit');
    Route::get('expenses/posting-accounts', [ExpenseController::class, 'postingAccounts'])->middleware('permission:expenses.create|expenses.edit|accounting.view');
    Route::post('expenses/{expense}/reverse', [ExpenseController::class, 'reverse'])->middleware('permission:expenses.delete');
    Route::get('expenses', [ExpenseController::class, 'index'])->middleware('permission:expenses.view'); Route::post('expenses', [ExpenseController::class, 'store'])->middleware('permission:expenses.create'); Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->middleware('permission:expenses.view'); Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->middleware('permission:expenses.edit'); Route::patch('expenses/{expense}', [ExpenseController::class, 'update'])->middleware('permission:expenses.edit'); Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('permission:expenses.delete');
});
