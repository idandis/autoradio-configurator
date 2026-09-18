<?php

use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\BrandsController;
use App\Http\Controllers\ConfigurationStatisticController;
use App\Http\Controllers\ConfigurationStatisticsController;
use App\Http\Controllers\ConfiguratorController;
use App\Http\Controllers\ConfiguratorImportController;
use App\Http\Controllers\ConfiguratorPostalCodeController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseMigrationController;
use App\Http\Controllers\DismissPostImportTasksController;
use App\Http\Controllers\ImportedProductsController;
use App\Http\Controllers\InstallationZonesController;
use App\Http\Controllers\ItalianCheckoutController;
use App\Http\Controllers\ItalianCheckoutMaintenanceController;
use App\Http\Controllers\ItalianOrdersController;
use App\Http\Controllers\MissingVehicleRequestsController;
use App\Http\Controllers\ModelsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuoteNumberController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\SharedConfigurationController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\VisitorStatisticsController;
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

Route::prefix('checkout/italiano')->name('italian-checkout.')->middleware('throttle:30,1')->group(function () {
    Route::post('/', [ItalianCheckoutController::class, 'start'])->name('start');
    Route::get('/{token}', [ItalianCheckoutController::class, 'show'])->whereUuid('token')->name('show');
    Route::post('/{token}', [ItalianCheckoutController::class, 'store'])->whereUuid('token')->name('store');
    Route::get('/{token}/conferma', [ItalianCheckoutController::class, 'confirmation'])->whereUuid('token')->name('confirmation');
    Route::get('/{token}/pagamento', [ItalianCheckoutController::class, 'payment'])->whereUuid('token')->name('payment');
    Route::post('/{token}/stripe-session', [ItalianCheckoutController::class, 'stripeSession'])->whereUuid('token')->name('stripe-session');
});

Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

Route::middleware('extra-eu')->group(function () {
    Route::get('/', ConfiguratorController::class);

    Route::get('/configurator', ConfiguratorController::class)->name('configurator.show');
    Route::get('/configurator/catalog/specific-screens', [ConfiguratorController::class, 'specificScreens'])
        ->name('configurator.catalog.specific-screens');
    Route::get('/configurator/catalog/universal-screens', [ConfiguratorController::class, 'universalScreens'])
        ->name('configurator.catalog.universal-screens');
    Route::get('/configurator/catalog/cameras', [ConfiguratorController::class, 'cameras'])
        ->name('configurator.catalog.cameras');
    Route::get('/configurator/catalog/speakers', [ConfiguratorController::class, 'speakers'])
        ->name('configurator.catalog.speakers');
    Route::post('/configurator/missing-vehicle', [ConfiguratorController::class, 'missingVehicle'])->name('configurator.missing-vehicle');
    Route::get('/configurator/postal-code/{postalCode}', ConfiguratorPostalCodeController::class)
        ->where('postalCode', '\\d{5}')
        ->name('configurator.postal-code');
    Route::post('/configurator/quote-number', QuoteNumberController::class)
        ->middleware('throttle:20,1')
        ->name('configurator.quote-number');
    Route::post('/configurator/statistics', [ConfigurationStatisticController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('configurator.statistics.store');
});

Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::delete('/italian-orders/test-orders', [ItalianOrdersController::class, 'destroyTests'])->name('italian-orders.destroy-tests');
    Route::delete('/italian-orders/{italianOrder}', [ItalianOrdersController::class, 'destroy'])->whereNumber('italianOrder')->name('italian-orders.destroy');
    Route::get('/italian-orders', [ItalianOrdersController::class, 'index'])->name('italian-orders.index');
    Route::get('/italian-orders/{italianOrder}', [ItalianOrdersController::class, 'show'])->whereNumber('italianOrder')->name('italian-orders.show');
    Route::get('/italian-orders/{italianOrder}/purchase-email', [ItalianOrdersController::class, 'purchaseEmail'])->whereNumber('italianOrder')->name('italian-orders.purchase-email');
    Route::post('/italian-orders/{italianOrder}/purchase-email/test', [ItalianOrdersController::class, 'testPurchaseEmail'])->whereNumber('italianOrder')->middleware('throttle:3,10')->name('italian-orders.purchase-email.test');
    Route::post('/italian-orders/{italianOrder}/refund', [ItalianOrdersController::class, 'refund'])->whereNumber('italianOrder')->name('italian-orders.refund');
    Route::post('/italian-orders/{italianOrder}/refunds/sync', [ItalianOrdersController::class, 'syncRefunds'])->whereNumber('italianOrder')->name('italian-orders.refunds-sync');
    Route::post('/italian-orders/{italianOrder}/cancel', [ItalianOrdersController::class, 'cancel'])->whereNumber('italianOrder')->name('italian-orders.cancel');
    Route::patch('/italian-orders/{italianOrder}', [ItalianOrdersController::class, 'update'])->whereNumber('italianOrder')->name('italian-orders.update');
    Route::get('/configuration-statistics', ConfigurationStatisticsController::class)
        ->name('configuration-statistics.index');
    Route::get('/visitor-statistics', VisitorStatisticsController::class)
        ->name('visitor-statistics.index');
    Route::delete('/visitor-statistics/selected', [VisitorStatisticsController::class, 'destroySelected'])
        ->name('visitor-statistics.destroy-selected');
    Route::delete('/visitor-statistics/{visitor}', [VisitorStatisticsController::class, 'destroy'])
        ->whereNumber('visitor')
        ->name('visitor-statistics.destroy');
    Route::get('/configuration-statistics/export/{format}', [ConfigurationStatisticsController::class, 'export'])
        ->whereIn('format', ['csv', 'xlsx', 'pdf'])
        ->name('configuration-statistics.export');
    Route::delete('/configuration-statistics/selected', [ConfigurationStatisticsController::class, 'destroySelected'])
        ->name('configuration-statistics.destroy-selected');
    Route::delete('/configuration-statistics/all', [ConfigurationStatisticsController::class, 'destroyAll'])
        ->name('configuration-statistics.destroy-all');
    Route::delete('/configuration-statistics/{configurationStatistic}', [ConfigurationStatisticsController::class, 'destroy'])
        ->whereNumber('configurationStatistic')
        ->name('configuration-statistics.destroy');
    Route::post('/configurator/shared-configurations', [SharedConfigurationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('configurator.shared-configurations.store');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/configurator/catalog/custom-products', [ConfiguratorController::class, 'customProducts'])
        ->name('configurator.catalog.custom-products');
    Route::post('/dashboard/database/migrate', DatabaseMigrationController::class)
        ->middleware('throttle:3,10')
        ->name('dashboard.database.migrate');
    Route::post('/dashboard/checkout-italia/update', ItalianCheckoutMaintenanceController::class)
        ->middleware('throttle:3,10')
        ->name('dashboard.italian-checkout.update');
    Route::post('/dashboard/import-csv', [ConfiguratorImportController::class, 'store'])->name('dashboard.import');
    Route::post('/dashboard/post-import-tasks/dismiss', DismissPostImportTasksController::class)
        ->name('dashboard.post-import-tasks.dismiss');
    Route::get('/brands', BrandsController::class)->name('brands.index');
    Route::get('/models', ModelsController::class)->name('models.index');
    Route::get('/models/edit', [ModelsController::class, 'edit'])->name('models.edit');
    Route::put('/models', [ModelsController::class, 'update'])->name('models.update');
    Route::get('/imported-products', ImportedProductsController::class)->name('imported-products.index');
    Route::get('/customers', [CustomersController::class, 'index'])->name('customers.index');
    Route::post('/customers/import', [CustomersController::class, 'import'])->name('customers.import');
    Route::post('/customers/import-orders', [CustomersController::class, 'importOrders'])->name('customers.import-orders');
    Route::patch('/customers/column-order', [CustomersController::class, 'updateColumnOrder'])->name('customers.column-order');
    Route::patch('/customers/{customer}/note', [CustomersController::class, 'updateNote'])->name('customers.note');
    Route::post('/customers/{customer}/contacts', [CustomersController::class, 'storeContact'])->name('customers.contacts.store');
    Route::patch('/customers/{customer}/contacts/{contact}', [CustomersController::class, 'updateContact'])->name('customers.contacts.update');
    Route::delete('/customers/{customer}/contacts/{contact}', [CustomersController::class, 'destroyContact'])->name('customers.contacts.destroy');
    Route::patch('/customers/{customer}/attention-color', [CustomersController::class, 'updateAttentionColor'])->name('customers.attention-color');
    Route::post('/customers/{customer}/costs', [CustomersController::class, 'storeCost'])->name('customers.costs.store');
    Route::patch('/customers/{customer}/costs/{cost}', [CustomersController::class, 'updateCost'])->name('customers.costs.update');
    Route::delete('/customers/{customer}/costs/{cost}', [CustomersController::class, 'destroyCost'])->name('customers.costs.destroy');
    Route::post('/customers/{customer}/supplier-refunds', [CustomersController::class, 'storeSupplierRefund'])->name('customers.supplier-refunds.store');
    Route::patch('/customers/{customer}/supplier-refunds/{supplierRefund}', [CustomersController::class, 'updateSupplierRefund'])->name('customers.supplier-refunds.update');
    Route::delete('/customers/{customer}/supplier-refunds/{supplierRefund}', [CustomersController::class, 'destroySupplierRefund'])->name('customers.supplier-refunds.destroy');
    Route::get('/missing-vehicle-requests', MissingVehicleRequestsController::class)->name('missing-vehicle-requests.index');
    Route::get('/missing-vehicle-requests/{missingVehicleRequest}/photo', [MissingVehicleRequestsController::class, 'photo'])->name('missing-vehicle-requests.photo');
    Route::delete('/missing-vehicle-requests/{missingVehicleRequest}', [MissingVehicleRequestsController::class, 'destroy'])->name('missing-vehicle-requests.destroy');
    Route::patch('/imported-products/{product}/price', [ImportedProductsController::class, 'updatePrice'])->name('imported-products.price');
    Route::patch('/imported-products/{product}/titles', [ImportedProductsController::class, 'updateTitles'])->name('imported-products.titles');
    Route::delete('/imported-products/{product}', [ImportedProductsController::class, 'destroy'])->name('imported-products.destroy');
    Route::get('/installation-zones', [InstallationZonesController::class, 'index'])->name('installation-zones.index');
    Route::post('/installation-zones', [InstallationZonesController::class, 'store'])->name('installation-zones.store');
    Route::put('/installation-zones/{installationZone}', [InstallationZonesController::class, 'update'])->name('installation-zones.update');
    Route::delete('/installation-zones/{installationZone}', [InstallationZonesController::class, 'destroy'])->name('installation-zones.destroy');
    Route::post('/installation-zones/{installationZone}/postal-codes', [InstallationZonesController::class, 'storePostalCode'])->name('installation-zones.postal-codes.store');
    Route::put('/installation-zones/{installationZone}/postal-codes/{postalCode}', [InstallationZonesController::class, 'updatePostalCode'])->name('installation-zones.postal-codes.update');
    Route::delete('/installation-zones/{installationZone}/postal-codes/{postalCode}', [InstallationZonesController::class, 'destroyPostalCode'])->name('installation-zones.postal-codes.destroy');
    Route::post('/installation-zones/{installationZone}/services', [InstallationZonesController::class, 'storeService'])->name('installation-zones.services.store');
    Route::put('/installation-zones/{installationZone}/services/{service}', [InstallationZonesController::class, 'updateService'])->name('installation-zones.services.update');
    Route::delete('/installation-zones/{installationZone}/services/{service}', [InstallationZonesController::class, 'destroyService'])->name('installation-zones.services.destroy');
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
