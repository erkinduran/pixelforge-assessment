<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // This uuid is also the Idempotency-Key and metadata.local_id we send to Pixelforge.
            $table->uuid('id')->primary();
            // Nullable: when a user deletes their account the generation stays (its cost is real money).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // The request, kept on the row so every retry rebuilds the identical body (else 409).
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_image_urls');

            // queued | processing | succeeded | failed. Only moves forward.
            $table->string('status')->default('queued');
            $table->string('pixelforge_id')->nullable()->unique();

            // Price range shown before submitting, in picodollars (what the user would pay).
            $table->bigInteger('estimate_min_pico');
            $table->bigInteger('estimate_max_pico');

            // Rates in picodollars per token, copied from the first Pixelforge object we see.
            $table->bigInteger('rate_text')->nullable();
            $table->bigInteger('rate_image')->nullable();
            $table->bigInteger('rate_output')->nullable();
            $table->bigInteger('rate_reasoning')->nullable();

            // Token usage, known once the generation succeeded.
            $table->integer('usage_text')->nullable();
            $table->integer('usage_image')->nullable();
            $table->integer('usage_output')->nullable();
            $table->integer('usage_reasoning')->nullable();

            // The image URL is long (signed), so text. The expiry is Pixelforge's value (Pixelforge docs, "Images").
            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();

            // From the Pixelforge payload. timestampTz keeps the exact instant whatever the app timezone. The admin report groups by this.
            $table->timestampTz('completed_at')->nullable();

            // From the payload's `error` object on a failed generation.
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();

            $table->timestamps();

            // The poller looks for rows that are still queued or processing.
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
