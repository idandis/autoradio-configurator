<?php

use App\Support\ItalianOrderSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ItalianOrderSchema::create('italian_order_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('italian_order_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('recipient');
            $table->string('subject');
            $table->longText('html');
            $table->string('status')->default('pending');
            $table->timestamp('prepared_at')->nullable();
            $table->timestamps();
            $table->unique(['italian_order_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('italian_order_emails');
    }
};
