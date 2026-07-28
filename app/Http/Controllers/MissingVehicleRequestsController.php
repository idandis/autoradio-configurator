<?php
namespace App\Http\Controllers;
use App\Models\MissingVehicleRequest;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Storage;
class MissingVehicleRequestsController extends Controller { public function __invoke(): Response { return Inertia::render('MissingVehicleRequests', ['requests' => MissingVehicleRequest::latest()->paginate(25)->through(fn ($item) => [...$item->toArray(), 'photo_url' => $item->photo_path ? Storage::url($item->photo_path) : null])->withQueryString()]); } public function destroy(MissingVehicleRequest $missingVehicleRequest) { $missingVehicleRequest->delete(); return back(); } }
