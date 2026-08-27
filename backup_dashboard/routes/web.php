<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WebsiteController;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Websites Resource and Action Routes
Route::prefix('websites')->name('websites.')->group(function () {
    Route::get('/', [WebsiteController::class, 'index'])->name('index');
    Route::get('/create', [WebsiteController::class, 'create'])->name('create');
    Route::post('/', [WebsiteController::class, 'store'])->name('store');
    Route::get('/{website}', [WebsiteController::class, 'show'])->name('show');
    Route::get('/{website}/edit', [WebsiteController::class, 'edit'])->name('edit');
    Route::put('/{website}', [WebsiteController::class, 'update'])->name('update');
    Route::delete('/{website}', [WebsiteController::class, 'destroy'])->name('destroy');

    // Individual & Diagnostic actions
    Route::post('/{website}/test-wp', [WebsiteController::class, 'testWordPressConnection'])->name('test-wp');
    Route::post('/{website}/test-sharepoint', [WebsiteController::class, 'testSharePointConnection'])->name('test-sharepoint');
    Route::post('/{website}/verify-backups', [WebsiteController::class, 'verifyRemoteBackups'])->name('verify-backups');
    Route::post('/{website}/test-all', [WebsiteController::class, 'testAllConnections'])->name('test-all');
    Route::post('/{website}/trigger-backup', [WebsiteController::class, 'triggerBackup'])->name('trigger-backup');
    Route::post('/{website}/trigger-restore', [WebsiteController::class, 'triggerRestore'])->name('trigger-restore');
    Route::get('/{website}/poll-status', [WebsiteController::class, 'pollStatus'])->name('poll-status');

    // Bulk Trigger Action
    Route::post('/bulk-trigger-backup', [WebsiteController::class, 'bulkTriggerBackup'])->name('bulk-trigger-backup');
});
