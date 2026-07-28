<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('missing_vehicle_requests', function (Blueprint $table) { $table->id(); $table->string('first_name'); $table->string('last_name'); $table->string('email'); $table->string('phone'); $table->string('province'); $table->string('brand'); $table->string('model'); $table->unsignedSmallInteger('year'); $table->text('comment')->nullable(); $table->string('photo_path')->nullable(); $table->timestamps(); }); }
    public function down(): void { Schema::dropIfExists('missing_vehicle_requests'); }
};
