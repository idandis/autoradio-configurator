<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_visitors', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('country_code', 2)->index();
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('browser_language', 100)->nullable();
            $table->text('referrer')->nullable();
            $table->string('requested_path', 500)->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_visitors');
    }
};
