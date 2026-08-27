<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('installation_zone_services')->orderBy('id')->get(['id', 'name'])->each(
            function (object $service): void {
                DB::table('installation_zone_services')->where('id', $service->id)->update([
                    'name_it' => $this->translateName((string) $service->name, 'it'),
                    'name_en' => $this->translateName((string) $service->name, 'en'),
                ]);
            },
        );
    }

    public function down(): void
    {
        // The original translations remain valid; no destructive rollback is needed.
    }

    private function translateName(string $name, string $locale): string
    {
        $phrases = $locale === 'it'
            ? [
                'cámaras trasera y frontal' => 'telecamere posteriori e anteriori',
                'cámara trasera + frontal' => 'telecamere posteriori + anteriori',
                'cámara trasera' => 'telecamera posteriore',
                'camara trasera' => 'telecamera posteriore',
                'cámara frontal' => 'telecamera anteriore',
                'camara frontal' => 'telecamera anteriore',
            ]
            : [
                'cámaras trasera y frontal' => 'rear and front cameras',
                'cámara trasera + frontal' => 'rear + front cameras',
                'cámara trasera' => 'rear camera',
                'camara trasera' => 'rear camera',
                'cámara frontal' => 'front camera',
                'camara frontal' => 'front camera',
            ];
        $words = $locale === 'it'
            ? [
                'instalación' => 'installazione',
                'pantalla' => 'schermo',
                'altavoces' => 'altoparlanti',
                'cámaras' => 'telecamere',
                'cámara' => 'telecamera',
                'camara' => 'telecamera',
                'trasera' => 'posteriore',
                'frontal' => 'anteriore',
                'y' => 'e',
            ]
            : [
                'instalación' => 'installation',
                'pantalla' => 'screen',
                'altavoces' => 'speakers',
                'cámaras' => 'cameras',
                'cámara' => 'camera',
                'camara' => 'camera',
                'trasera' => 'rear',
                'frontal' => 'front',
                'y' => 'and',
            ];

        $translated = str_ireplace(array_keys($phrases), array_values($phrases), $name);
        foreach ($words as $source => $replacement) {
            $translated = preg_replace('/\b'.preg_quote($source, '/').'\b/iu', $replacement, $translated) ?? $translated;
        }

        return mb_strtoupper(mb_substr($translated, 0, 1)).mb_substr($translated, 1);
    }
};
