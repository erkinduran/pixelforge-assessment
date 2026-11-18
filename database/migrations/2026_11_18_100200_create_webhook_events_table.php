<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            // unique: the database itself refuses to record the same Pixelforge event twice.
            $table->string('event_id')->unique();
            $table->string('type');
            $table->string('generation_pixelforge_id')->nullable();
            // No payload column on purpose: it holds a signed image URL.
            $table->timestampTz('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
