<?php

namespace App\Http\Controllers;

use App\Models\ConfiguratorProduct;
use App\Models\InstallationZone;
use App\Models\MissingVehicleRequest;
use App\Models\SharedConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ConfiguratorController extends Controller
{
    public function missingVehicle(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'province' => ['required', 'string', 'max:100'],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'between:1900,2100'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'photo' => ['nullable', 'image', 'max:2048'],
        ]);

        $photo = $request->file('photo');
        $photoPath = $photo?->store('missing-vehicle-requests', 'public');
        $storedPhotoPath = $photoPath ? Storage::disk('public')->path($photoPath) : null;
        MissingVehicleRequest::create([...$data, 'photo_path' => $photoPath]);
        unset($data['photo']);
        $body = view('emails.missing-vehicle-request', ['requestData' => $data])->render();

        try {
            Mail::html($body, function ($message) use ($data, $photo, $storedPhotoPath) {
                $message
                    ->to(config('mail.notification_to'))
                    ->replyTo($data['email'], $data['first_name'].' '.$data['last_name'])
                    ->subject('Nuova richiesta autoradio: '.$data['brand'].' '.$data['model']);

                if ($photo && $storedPhotoPath) {
                    $message->attach($storedPhotoPath, [
                        'as' => $photo->getClientOriginalName(),
                        'mime' => $photo->getMimeType(),
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Errore SMTP: '.$exception->getMessage()], 500);
        }

        return response()->json(['message' => 'ok']);
    }

    public function __invoke(Request $request): Response
    {
        $allProducts = ConfiguratorProduct::with('variants')
            ->whereIn('category', ['screen', 'camera', 'speaker'])
            ->orderBy('category')
            ->orderBy('title')
            ->get();

        $screenProducts = ConfiguratorProduct::with('variants')
            ->where('category', 'screen')
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->whereNotNull('model')
            ->where('model', '!=', '')
            ->whereNotNull('year_from')
            ->whereNotNull('year_to')
            ->orderBy('brand')
            ->orderBy('model')
            ->get();

        $universalScreenProducts = ConfiguratorProduct::with('variants')
            ->where('category', 'screen')
            ->where(function ($query) {
                $query->whereRaw('LOWER(model) = ?', ['universal'])
                    ->orWhere(function ($query) {
                        $query->where(function ($query) {
                            $query->whereNull('model')->orWhere('model', '');
                        })->where(function ($query) {
                            $query->whereRaw('LOWER(title) LIKE ?', ['%universal%'])
                                ->orWhereRaw('LOWER(handle) LIKE ?', ['%universal%']);
                        });
                    });
            })
            ->orderBy('price_min')
            ->get();

        $cameraProducts = ConfiguratorProduct::with('variants')
            ->where('category', 'camera')
            ->orderBy('price_min')
            ->get()
            ->values();

        $speakerProducts = ConfiguratorProduct::with('variants')
            ->where('category', 'speaker')
            ->orderBy('title')
            ->get();

        $installationZones = InstallationZone::query()
            ->where('active', true)
            ->with(['postalCodes', 'services'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Configurator', [
            'locale' => app()->getLocale(),
            'translations' => trans('configurator'),
            'sharedConfiguration' => $this->sharedConfiguration($request),
            'customProducts' => $allProducts->flatMap(function (ConfiguratorProduct $product) {
                $variants = $product->variants->filter(
                    fn ($variant) => $variant->price !== null || filled($variant->shopify_variant_id),
                );

                if ($variants->isEmpty()) {
                    return [[
                        'key' => 'product-'.$product->id,
                        'productId' => $product->id,
                        'variantId' => null,
                        'title' => $product->localizedTitle(),
                        'variantTitle' => null,
                        'category' => $product->category,
                        'sku' => null,
                        'shopifyVariantId' => null,
                        'price' => (float) ($product->price_min ?? 0),
                        'image' => $product->image_url,
                        'brand' => $product->brand,
                        'model' => $product->model,
                        'yearFrom' => $product->year_from,
                        'yearTo' => $product->year_to,
                    ]];
                }

                return $variants->map(fn ($variant) => [
                    'key' => 'variant-'.$variant->id,
                    'productId' => $product->id,
                    'variantId' => $variant->id,
                    'title' => $product->localizedTitle(),
                    'variantTitle' => $variant->option_value ?: $variant->title,
                    'category' => $product->category,
                    'sku' => $variant->sku,
                    'shopifyVariantId' => $variant->shopify_variant_id,
                    'price' => (float) ($variant->price ?? $product->price_min ?? 0),
                    'image' => $variant->image_url ?: $product->image_url,
                    'brand' => $product->brand,
                    'model' => $product->model,
                    'yearFrom' => $product->year_from,
                    'yearTo' => $product->year_to,
                ]);
            })->values(),
            'vehicles' => $this->screenOptions($screenProducts),
            'universalScreens' => $this->screenOptions($universalScreenProducts),
            'cameraOptions' => $this->cameraOptions($cameraProducts),
            'speakerOptions' => $this->speakerOptions($speakerProducts),
            'installationOptions' => $installationZones->flatMap(fn (InstallationZone $zone) =>
                $zone->services->map(fn ($service) => [
                    'key' => 'zone-service-'.$service->id,
                    'productId' => null,
                    'variantId' => null,
                    'title' => $service->name,
                    'productTitle' => $service->name,
                    'variantTitle' => null,
                    'price' => (float) $service->price,
                    'shopifyVariantId' => null,
                    'sku' => null,
                    'subtype' => 'zone-service',
                    'location' => $zone->name,
                    'installationRaw' => null,
                ])
            )->values(),
            'installationZones' => $installationZones
                ->map(fn (InstallationZone $zone) => [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'installerAddress' => $zone->installer_address,
                    'installerPhone' => $zone->installer_phone,
                    'postalRanges' => $zone->postalCodes->map(fn ($range) => [
                        'from' => $range->postal_code_from,
                        'to' => $range->postal_code_to,
                    ])->values(),
                    'productHandles' => $zone->services->map(fn ($service) => 'zone-service-'.$service->id)->values(),
                    'productPrices' => $zone->services->mapWithKeys(fn ($service) => ['zone-service-'.$service->id => (float) $service->price]),
                    'productTitles' => $zone->services->mapWithKeys(fn ($service) => ['zone-service-'.$service->id => $service->name]),
                ])->values(),
            'vehicleImages' => collect(glob(public_path('images/vehicles-dark/*.{png,jpg,jpeg,webp}'), GLOB_BRACE) ?: [])
                ->map(fn (string $path) => basename($path))
                ->sort()
                ->values(),
            'brandImages' => collect(glob(public_path('images/brands/*.{png,jpg,jpeg,webp}'), GLOB_BRACE) ?: [])
                ->map(fn (string $path) => basename($path))
                ->sort()
                ->values(),
        ]);
    }

    private function screenOptions($products)
    {
        return $products->map(function (ConfiguratorProduct $product) {
                $vehicleFields = $this->vehicleFields($product);

                return [
                'id' => $product->id,
                'handle' => $product->handle,
                'title' => $product->localizedTitle(),
                'brand' => $vehicleFields['brand'],
                'model' => $vehicleFields['model'],
                'yearFrom' => $product->year_from,
                'yearTo' => $product->year_to,
                'image' => $product->image_url,
                'originalDashboardImages' => $product->meta['original_dashboard_images'] ?? [],
                'variants' => $product->variants
                    ->filter(fn ($variant) => filled($variant->option_value))
                    ->groupBy(fn ($variant) => mb_strtolower(trim((string) $variant->option_value)))
                    ->map(function ($matchingVariants) use ($product) {
                        $choices = $matchingVariants->map(fn ($variant) => [
                            'id' => $variant->id,
                            'title' => $variant->option_value,
                            'color' => filled($variant->meta['option2'] ?? null)
                                ? trim((string) $variant->meta['option2'])
                                : null,
                            'sku' => $variant->sku,
                            'shopifyVariantId' => $variant->shopify_variant_id,
                            'price' => (float) $variant->price,
                            'image' => $variant->image_url ?: $product->image_url,
                            'dashboardVariant' => $this->variantSuffix($variant->option_value ?: $variant->title),
                        ])->values();
                        $default = $choices->first();

                        return [
                            ...$default,
                            'colorOptions' => $choices->filter(fn ($choice) => filled($choice['color']))->values(),
                        ];
                    })
                    ->sortBy('price')
                    ->values(),
                ];
            })->values();
    }

    private function variantSuffix(?string $title): ?string
    {
        preg_match('/(?:^|[\s_-])([a-z])\s*$/iu', trim((string) $title), $matches);

        return isset($matches[1]) ? mb_strtoupper($matches[1]) : null;
    }

    private function sharedConfiguration(Request $request): ?array
    {
        $uuid = $request->string('c')->toString();

        if (! Str::isUuid($uuid)) {
            return null;
        }

        return SharedConfiguration::query()
            ->where('uuid', $uuid)
            ->first()
            ?->configuration;
    }

    /** @return array{brand: ?string, model: ?string} */
    private function vehicleFields(ConfiguratorProduct $product): array
    {
        if (preg_match('/(?:^|\|)\s*\d+\s*[:：]/u', (string) $product->model)) {
            return ['brand' => $product->brand, 'model' => $product->model];
        }

        static $multibrandEntries = null;
        $multibrandEntries ??= require config_path('vehicle-multibrand.php');
        $entries = $multibrandEntries[$product->id] ?? [];
        if (! is_array($entries) || $entries === []) {
            return ['brand' => $product->brand, 'model' => $product->model];
        }

        $brands = collect($entries)->pluck('brand')->filter()->unique()->values();
        $models = collect($entries)
            ->filter(fn ($entry) => is_array($entry) && isset($entry['brand'], $entry['model']))
            ->map(function (array $entry) use ($brands) {
                $brandIndex = $brands->search($entry['brand']);

                return $brandIndex === false ? null : ($brandIndex + 1).':'.$entry['model'];
            })
            ->filter()
            ->unique()
            ->values();

        return [
            'brand' => $brands->implode(' | '),
            'model' => $models->implode(' | '),
        ];
    }

    private function cameraOptions($products): array
    {
        $options = [];

        foreach ($products as $product) {
            $cameraVariants = $product->variants
                ->filter(fn ($variant) => $variant->price !== null || filled($variant->shopify_variant_id))
                ->sortBy('price')
                ->values();
            $defaultVariant = $cameraVariants->first();
            $variantOptions = $cameraVariants->map(fn ($variant) => [
                'id' => $variant->id,
                'title' => $variant->option_value ?: $variant->title,
                'color' => null,
                'sku' => $variant->sku,
                'shopifyVariantId' => $variant->shopify_variant_id,
                'price' => (float) ($variant->price ?? $product->price_min),
                'image' => $variant->image_url ?: $product->image_url,
            ])->values();

            $options[] = [
                    'key' => $product->handle,
                    'productHandle' => $product->handle,
                    'productId' => $product->id,
                    'variantId' => $defaultVariant?->id,
                    'title' => $product->handle === 'camara-360-para-radios-de-coche-android-con-vista-de-ave'
                        ? 'Cámara 360° estandar'
                        : $product->localizedTitle(),
                    'productTitle' => $product->localizedTitle(),
                    'variantTitle' => $defaultVariant?->option_value ?: $defaultVariant?->title,
                    'price' => (float) ($defaultVariant?->price ?? $product->price_min),
                    'image' => $product->image_url,
                    'shopifyVariantId' => $defaultVariant?->shopify_variant_id,
                    'sku' => $defaultVariant?->sku,
                    'variants' => $variantOptions,
                    'isStandard' => in_array($product->handle, [
                        'camara-trasera-estandar',
                        'camara-trasera-frontal-ahd-1080p-gran-angular-con-vision-nocturna',
                        'camara-360-para-radios-de-coche-android-con-vista-de-ave',
                    ], true),
                    'isStandardFront' => $product->handle === 'camara-trasera-frontal-ahd-1080p-gran-angular-con-vision-nocturna',
                    'isFront' => $product->handle === 'camara-trasera-frontal-ahd-1080p-gran-angular-con-vision-nocturna' || $product->subtype === 'front',
                    'isRear' => $product->handle !== 'camara-360-para-radios-de-coche-android-con-vista-de-ave' &&
                        ($product->handle === 'camara-trasera-estandar'
                        || $product->subtype === 'rear'
                        || ($product->subtype === 'ahd' && preg_match('/traser|rear/', mb_strtolower($product->title)) === 1)),
                    'brand' => $product->brand,
                    'model' => $product->model,
                    'yearFrom' => $product->year_from,
                    'yearTo' => $product->year_to,
                ];
        }

        return $options;
    }

    private function speakerOptions($products): array
    {
        return $products
            ->groupBy(fn (ConfiguratorProduct $product) => mb_strtolower(trim($product->title)))
            ->map(function ($matchingProducts) {
                /** @var ConfiguratorProduct $product */
                $product = $matchingProducts->first();
                $variant = $matchingProducts
                    ->flatMap(fn (ConfiguratorProduct $matchingProduct) => $matchingProduct->variants)
                    ->filter(fn ($candidate) => filled($candidate->shopify_variant_id) && $candidate->price !== null)
                    ->sortBy('price')
                    ->first();

                if (! $variant) {
                    return null;
                }

                $sizes = $matchingProducts
                    ->flatMap(fn (ConfiguratorProduct $matchingProduct) => $matchingProduct->meta['speaker_sizes'] ?? [])
                    ->map(fn ($size) => trim((string) $size))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $categories = $matchingProducts
                    ->flatMap(fn (ConfiguratorProduct $matchingProduct) => $matchingProduct->meta['speaker_categories'] ?? [])
                    ->map(fn ($category) => trim((string) $category))
                    ->filter()
                    ->unique(fn ($category) => mb_strtolower($category))
                    ->values()
                    ->all();

                return [
                    'key' => 'speaker-'.$variant->id,
                    'productId' => $variant->configurator_product_id,
                    'variantId' => $variant->id,
                    'handle' => $product->handle,
                    'title' => $product->localizedTitle(),
                    'productTitle' => $product->localizedTitle(),
                    'variantTitle' => $variant->option_value ?: $variant->title,
                    'price' => (float) $variant->price,
                    'image' => $product->image_url ?: $variant->image_url,
                    'shopifyVariantId' => $variant->shopify_variant_id,
                    'sku' => $variant->sku,
                    'sizes' => $sizes,
                    'categories' => $categories,
                    'brand' => $product->brand,
                    'model' => $product->model,
                    'yearFrom' => $product->year_from,
                    'yearTo' => $product->year_to,
                ];
            })
            ->filter()
            ->sortBy('price')
            ->values()
            ->all();
    }
}
