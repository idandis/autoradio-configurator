<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_lessons', function (Blueprint $table) {
            $table->id();
            $table->string('session');
            $table->string('scope', 20);
            $table->string('target')->default('');
            $table->text('instruction');
            $table->string('locale', 2);
            $table->text('question')->nullable();
            $table->text('answer')->nullable();
            $table->timestamps();
            $table->index(['session', 'scope', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_lessons');
    }
};
