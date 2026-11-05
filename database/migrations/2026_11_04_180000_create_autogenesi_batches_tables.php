<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autogenesi_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('total');
            $table->timestamps();
        });
        Schema::create('autogenesi_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('autogenesi_batches')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('query', 240);
            $table->json('vehicle');
            $table->string('status')->default('queued')->index();
            $table->text('source_url')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('autogenesi_products')->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autogenesi_batch_items');
        Schema::dropIfExists('autogenesi_batches');
    }
};
