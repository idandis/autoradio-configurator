<?php

use App\Http\Controllers\BrandsController;
use App\Http\Controllers\ConfiguratorController;
use App\Http\Controllers\ConfiguratorImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportedProductsController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});*/

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/configurator', ConfiguratorController::class)->name('configurator.show');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::post('/dashboard/import-csv', [ConfiguratorImportController::class, 'store'])->name('dashboard.import');
    Route::get('/brands', BrandsController::class)->name('brands.index');
    Route::get('/imported-products', ImportedProductsController::class)->name('imported-products.index');
    Route::redirect('/settings', '/settings/profile');
    Route::get('/settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/settings/security', [SecurityController::class, 'edit'])->name('security.edit');
    Route::put('/settings/password', [PasswordController::class, 'update'])->name('settings.password.update');
    Route::get('/settings/appearance', fn () => Inertia::render('settings/Appearance'))->name('appearance.edit');
});

require __DIR__.'/auth.php';
