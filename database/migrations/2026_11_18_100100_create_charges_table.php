<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            // unique: the database itself refuses a second charge for the same generation.
            // restrictOnDelete: a generation that has a charge can never be deleted.
            $table->foreignUuid('generation_id')->unique()->constrained('generations')->restrictOnDelete();
            // Nullable: the charge survives a deleted account, so admin totals still match /v1/usage.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Picodollars. These two columns are the only place the final cost and price live.
            $table->bigInteger('provider_cost_pico');
            $table->bigInteger('user_price_pico');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
