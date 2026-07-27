<?php

use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\BrandsController;
use App\Http\Controllers\ConfiguratorController;
use App\Http\Controllers\ConfiguratorImportController;
use App\Http\Controllers\ConfiguratorPostalCodeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportedProductsController;
use App\Http\Controllers\InstallationZonesController;
use App\Http\Controllers\ModelsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuoteNumberController;
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
Route::post('/configurator/missing-vehicle', [ConfiguratorController::class, 'missingVehicle'])->name('configurator.missing-vehicle');
Route::get('/configurator/postal-code/{postalCode}', ConfiguratorPostalCodeController::class)
    ->where('postalCode', '\\d{5}')
    ->name('configurator.postal-code');

Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/admin/quote-number', QuoteNumberController::class)->name('admin.quote-number');
    Route::post('/dashboard/import-csv', [ConfiguratorImportController::class, 'store'])->name('dashboard.import');
    Route::get('/brands', BrandsController::class)->name('brands.index');
    Route::get('/models', ModelsController::class)->name('models.index');
    Route::get('/models/edit', [ModelsController::class, 'edit'])->name('models.edit');
    Route::put('/models', [ModelsController::class, 'update'])->name('models.update');
    Route::get('/imported-products', ImportedProductsController::class)->name('imported-products.index');
    Route::patch('/imported-products/{product}/price', [ImportedProductsController::class, 'updatePrice'])->name('imported-products.price');
    Route::get('/installation-zones', [InstallationZonesController::class, 'index'])->name('installation-zones.index');
    Route::post('/installation-zones', [InstallationZonesController::class, 'store'])->name('installation-zones.store');
    Route::put('/installation-zones/{installationZone}', [InstallationZonesController::class, 'update'])->name('installation-zones.update');
    Route::delete('/installation-zones/{installationZone}', [InstallationZonesController::class, 'destroy'])->name('installation-zones.destroy');
});

Route::middleware('auth')->group(function () {
    Route::redirect('/settings', '/settings/profile');
    Route::get('/settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/settings/security', [SecurityController::class, 'edit'])->name('security.edit');
    Route::put('/settings/password', [PasswordController::class, 'update'])->name('settings.password.update');
    Route::get('/settings/appearance', fn () => Inertia::render('settings/Appearance'))->name('appearance.edit');
});

require __DIR__.'/auth.php';
