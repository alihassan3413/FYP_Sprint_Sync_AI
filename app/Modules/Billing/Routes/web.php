<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\BillingPlanController;
use App\Modules\Billing\Http\Controllers\ClientController;
use App\Modules\Billing\Http\Controllers\ClientLogoController;
use App\Modules\Billing\Http\Controllers\InvoiceController;
use App\Modules\Billing\Http\Controllers\InvoicingSettingsController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('clients', 'workspace.clients.', function () {
    Route::get('/', [ClientController::class, 'index'])->name('index');
    Route::post('/', [ClientController::class, 'store'])->name('store');
    Route::get('{client}', [ClientController::class, 'show'])->name('show');
    Route::put('{client}', [ClientController::class, 'update'])->name('update');
    Route::post('{client}/archive', [ClientController::class, 'archive'])->name('archive');
    Route::post('{client}/restore', [ClientController::class, 'restore'])->name('restore');

    Route::get('{client}/logo', [ClientLogoController::class, 'show'])->name('logo.show');
    Route::post('{client}/logo', [ClientLogoController::class, 'store'])->name('logo.store');
    Route::delete('{client}/logo', [ClientLogoController::class, 'destroy'])->name('logo.destroy');

    // Recurring invoices ("billing plans") are edited on the client page.
    Route::post('{client}/recurring-invoices', [BillingPlanController::class, 'store'])->name('plans.store');
    Route::put('{client}/recurring-invoices/{billingPlan}', [BillingPlanController::class, 'update'])->name('plans.update');
    Route::post('{client}/recurring-invoices/{billingPlan}/pause', [BillingPlanController::class, 'pause'])->name('plans.pause');
    Route::post('{client}/recurring-invoices/{billingPlan}/resume', [BillingPlanController::class, 'resume'])->name('plans.resume');
    Route::post('{client}/recurring-invoices/{billingPlan}/invoices', [BillingPlanController::class, 'generateInvoice'])->name('plans.invoices.store');
});

TenantRoute::prefixed('invoices', 'workspace.invoices.', function () {
    Route::get('/', [InvoiceController::class, 'index'])->name('index');
    Route::get('{invoice}', [InvoiceController::class, 'show'])->name('show');
    Route::put('{invoice}/lines', [InvoiceController::class, 'updateLines'])->name('lines.update');
    Route::post('{invoice}/approve', [InvoiceController::class, 'approve'])->name('approve');
    Route::post('{invoice}/cancel-sending', [InvoiceController::class, 'cancelSending'])->name('cancel-sending');
    Route::post('{invoice}/sender', [InvoiceController::class, 'refreshSender'])->name('sender.refresh');
    Route::get('{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('pdf');
    Route::get('{invoice}/logo', [InvoiceController::class, 'logo'])->name('logo');
});

TenantRoute::prefixed('settings/invoicing', 'workspace.invoicing.', function () {
    Route::get('/', [InvoicingSettingsController::class, 'edit'])->name('edit');
    Route::put('/', [InvoicingSettingsController::class, 'update'])->name('update');
    Route::get('logo', [InvoicingSettingsController::class, 'showLogo'])->name('logo.show');
    Route::post('logo', [InvoicingSettingsController::class, 'storeLogo'])->name('logo.store');
    Route::delete('logo', [InvoicingSettingsController::class, 'destroyLogo'])->name('logo.destroy');
});
