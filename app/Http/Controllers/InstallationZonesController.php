<?php

namespace App\Http\Controllers;

use App\Models\InstallationZone;
use App\Models\InstallationZonePostalCode;
use App\Models\InstallationZoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InstallationZonesController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('InstallationZones', [
            'zones' => InstallationZone::query()
                ->with(['postalCodes' => fn ($query) => $query->orderBy('postal_code_from'), 'services' => fn ($query) => $query->orderBy('name')])
                ->orderBy('name')
                ->get()
                ->map(fn (InstallationZone $zone) => [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'installer_address' => $zone->installer_address,
                    'installer_phone' => $zone->installer_phone,
                    'postal_ranges' => $zone->postalCodes->map(fn (InstallationZonePostalCode $range) => [
                        'id' => $range->id,
                        'from' => $range->postal_code_from,
                        'to' => $range->postal_code_to,
                    ])->values(),
                    'services' => $zone->services->map(fn (InstallationZoneService $service) => [
                        'id' => $service->id,
                        'name' => $service->name,
                        'price' => (float) $service->price,
                    ])->values(),
                ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->zoneData($request);
        InstallationZone::create([...$data, 'active' => true]);

        return back()->with('status', 'Zona creata.');
    }

    public function update(Request $request, InstallationZone $installationZone): RedirectResponse
    {
        $installationZone->update($this->zoneData($request, $installationZone));

        return back()->with('status', 'Zona aggiornata.');
    }

    public function destroy(InstallationZone $installationZone): RedirectResponse
    {
        $installationZone->delete();

        return back()->with('status', 'Zona, CAP e installazioni eliminati.');
    }

    public function storePostalCode(Request $request, InstallationZone $installationZone): RedirectResponse
    {
        $data = $this->postalCodeData($request);
        $installationZone->postalCodes()->create($data);

        return back()->with('status', 'Codice postale aggiunto.');
    }

    public function updatePostalCode(Request $request, InstallationZone $installationZone, InstallationZonePostalCode $postalCode): RedirectResponse
    {
        $this->ensureBelongsToZone($postalCode->installation_zone_id, $installationZone);
        $postalCode->update($this->postalCodeData($request));

        return back()->with('status', 'Codice postale aggiornato.');
    }

    public function destroyPostalCode(InstallationZone $installationZone, InstallationZonePostalCode $postalCode): RedirectResponse
    {
        $this->ensureBelongsToZone($postalCode->installation_zone_id, $installationZone);
        $postalCode->delete();

        return back()->with('status', 'Codice postale eliminato.');
    }

    public function storeService(Request $request, InstallationZone $installationZone): RedirectResponse
    {
        $installationZone->services()->create($this->serviceData($request));

        return back()->with('status', 'Installazione aggiunta.');
    }

    public function updateService(Request $request, InstallationZone $installationZone, InstallationZoneService $service): RedirectResponse
    {
        $this->ensureBelongsToZone($service->installation_zone_id, $installationZone);
        $service->update($this->serviceData($request));

        return back()->with('status', 'Installazione aggiornata.');
    }

    public function destroyService(InstallationZone $installationZone, InstallationZoneService $service): RedirectResponse
    {
        $this->ensureBelongsToZone($service->installation_zone_id, $installationZone);
        $service->delete();

        return back()->with('status', 'Installazione eliminata.');
    }

    private function postalCodeData(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required', 'regex:/^\d{5}$/'],
            'to' => ['nullable', 'regex:/^\d{5}$/'],
        ]);
        $to = $data['to'] ?: $data['from'];

        if ($data['from'] > $to) {
            throw ValidationException::withMessages(['to' => 'Il CAP finale non può essere inferiore a quello iniziale.']);
        }

        return ['postal_code_from' => $data['from'], 'postal_code_to' => $to];
    }

    private function zoneData(Request $request, ?InstallationZone $zone = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:installation_zones,name'.($zone ? ','.$zone->id : '')],
            'installer_address' => ['nullable', 'string', 'max:500'],
            'installer_phone' => ['nullable', 'string', 'max:50'],
        ]);

        return [
            'name' => trim($data['name']),
            'installer_address' => filled($data['installer_address'] ?? null) ? trim($data['installer_address']) : null,
            'installer_phone' => filled($data['installer_phone'] ?? null) ? trim($data['installer_phone']) : null,
        ];
    }

    private function serviceData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        return ['name' => trim($data['name']), 'price' => $data['price']];
    }

    private function ensureBelongsToZone(int $zoneId, InstallationZone $zone): void
    {
        abort_unless($zoneId === $zone->id, 404);
    }
}
