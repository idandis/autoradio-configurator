<?php

use App\Services\WhatsAppAssistantSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('whatsapp_assistant_settings')->where('id', 1)->exists()) return;
        $settings = app(WhatsAppAssistantSettings::class);
        $keywords = preg_split('/\R/', $settings->get()['handoff_keywords']);
        $settings->save(['welcome_messages' => $settings->defaults()['welcome_messages'],
            'handoff_keywords' => implode("\n", array_unique([...$keywords, 'operatore', 'operador', 'operator']))]);
    }

    public function down(): void {}
};
