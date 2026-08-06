<?php

namespace App\Http\Controllers;

use App\Models\MissingVehicleRequest;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MissingVehicleRequestsController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('MissingVehicleRequests', [
            'requests' => MissingVehicleRequest::latest()
                ->paginate(25)
                ->through(fn ($item) => [
                    ...$item->toArray(),
                    'photo_url' => $item->photo_path
                        ? "/missing-vehicle-requests/{$item->id}/photo"
                        : null,
                ])
                ->withQueryString(),
        ]);
    }

    public function photo(MissingVehicleRequest $missingVehicleRequest): StreamedResponse
    {
        abort_unless(
            $missingVehicleRequest->photo_path
                && Storage::disk('public')->exists($missingVehicleRequest->photo_path),
            404,
        );

        return Storage::disk('public')->response(
            $missingVehicleRequest->photo_path,
            basename($missingVehicleRequest->photo_path),
            [],
            'inline',
        );
    }

    public function destroy(MissingVehicleRequest $missingVehicleRequest)
    {
        $missingVehicleRequest->delete();

        return back();
    }
}
