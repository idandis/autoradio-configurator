<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_chats', function (Blueprint $table) {
            $table->string('import_label_id', 100)->nullable();
            $table->string('import_label_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_chats', fn (Blueprint $table) => $table->dropColumn(['import_label_id', 'import_label_name']));
    }
};
