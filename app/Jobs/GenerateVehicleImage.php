<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GenerateVehicleImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public int $uniqueFor = 86400;

    /**
     * @param array{brand: string, model: string, year_from: int, year_to: int, stem: string} $vehicle
     */
    public function __construct(public array $vehicle)
    {
        $this->onQueue('vehicle-images');
    }

    public function uniqueId(): string
    {
        return $this->vehicle['stem'];
    }

    public function handle(): void
    {
        $destination = public_path('images/vehicles-dark/'.$this->vehicle['stem'].'.webp');

        if (File::exists($destination)) {
            return;
        }

        $response = Http::withToken((string) config('services.openai.api_key'))
            ->acceptJson()
            ->timeout(210)
            ->retry(
                2,
                1500,
                fn (\Throwable $exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
            )
            ->post('https://api.openai.com/v1/images/generations', [
                'model' => config('services.openai.image_model', 'gpt-image-1-mini'),
                'prompt' => sprintf(
                    'Studio product image of a %s %s, model years %d–%d. Accurate recognizable production vehicle, front three-quarter view, complete car visible, centered, realistic photography, soft natural shadow, plain solid #121212 dark background, no text, no badge close-up, no frame, no people.',
                    $this->vehicle['brand'],
                    $this->vehicle['model'],
                    $this->vehicle['year_from'],
                    $this->vehicle['year_to'],
                ),
                'size' => '1536x1024',
                'quality' => 'low',
                'output_format' => 'webp',
                'output_compression' => 72,
                'n' => 1,
            ])
            ->throw()
            ->json();

        $encoded = data_get($response, 'data.0.b64_json');
        $image = is_string($encoded) ? base64_decode($encoded, true) : false;

        if ($image === false || $image === '') {
            throw new RuntimeException('The image provider returned no valid image.');
        }

        File::ensureDirectoryExists(dirname($destination));
        $temporary = $destination.'.part';
        File::put($temporary, $image);
        File::move($temporary, $destination);
    }
}
