<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Set the first time a submit attempt had an unknown outcome (Pixelforge may have created
            // the generation and will bill it). Never cleared. While it is set we never write
            // `failed` ourselves: only a Pixelforge object with status failed can finish the row.
            $table->timestampTz('unknown_outcome_at')->nullable();

            // How many submit attempts failed in a way worth retrying (rate limit, overloaded,
            // timeout, 5xx). Concurrency waits are not counted. Kept on the row, not taken from the
            // queue's attempts(), because the queue counts every release() as an attempt.
            $table->unsignedSmallInteger('submit_attempts')->default(0);

            // What the latest failed submit attempt looked like: the Pixelforge error code, or
            // http_<status>, or no_response. Overwritten on every failed attempt. It lets the
            // poller tell a 409 row (never re-POST) from a timeout row (re-POST is safe).
            $table->string('last_submit_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['unknown_outcome_at', 'submit_attempts', 'last_submit_error']);
        });
    }
};
