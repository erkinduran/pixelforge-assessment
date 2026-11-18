<?php

namespace App\Generations;

use App\Models\Generation;
use Illuminate\Support\Facades\DB;

/**
 * Writes a failure that WE decided on, because Pixelforge definitely rejected the request.
 * The row is locked first, and nothing is written unless it is still safe:
 * queued, no Pixelforge id, and no earlier attempt with an unknown outcome. A webhook may
 * have finished the row while we were deciding. No charge is written: failed costs nothing.
 */
final class RecordSubmitFailure
{
    /** Returns true when the failure was written. */
    public function record(string $generationId, string $code, string $message): bool
    {
        return DB::transaction(function () use ($generationId, $code, $message): bool {
            $generation = Generation::query()->whereKey($generationId)->lockForUpdate()->first();

            if ($generation === null
                || $generation->status !== Generation::QUEUED
                || $generation->pixelforge_id !== null
                || $generation->unknown_outcome_at !== null) {
                return false;
            }

            $generation->status = Generation::FAILED;
            $generation->failure_code = $code;
            $generation->failure_message = $message;
            // completed_at stays null: Pixelforge never completed anything.
            $generation->save();

            return true;
        });
    }
}
