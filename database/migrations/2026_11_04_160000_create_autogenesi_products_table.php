<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autogenesi_products', function (Blueprint $table) {
            $table->id();
            $table->string('source_id')->unique();
            $table->text('source_url');
            $table->string('handle')->unique();
            $table->string('status')->default('draft');
            $table->string('brand');
            $table->string('model');
            $table->unsignedSmallInteger('year_from');
            $table->unsignedSmallInteger('year_to');
            $table->text('title');
            $table->longText('body_html');
            $table->json('variants');
            $table->json('images');
            $table->json('metadata');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autogenesi_products');
    }
};
