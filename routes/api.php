<?php

use App\Http\Controllers\Workspace\IntegrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants/{tenant:slug}/ims/case/v1p1')->name('tenant.integrations.case.')
    ->middleware(['throttle:integration-api', 'integration.token:case.read', 'tenant.entitlement:integrations'])
    ->group(function (): void {
        Route::get('/CFDocuments', [IntegrationController::class, 'caseDocuments'])->name('documents.index');
        Route::get('/CFDocuments/{sourcedId}', [IntegrationController::class, 'caseDocument'])->name('documents.show');
        Route::get('/CFItems/{sourcedId}', [IntegrationController::class, 'caseItem'])->name('items.show');
        Route::get('/CFPackages/{sourcedId}', [IntegrationController::class, 'casePackage'])->name('packages.show');
    });
