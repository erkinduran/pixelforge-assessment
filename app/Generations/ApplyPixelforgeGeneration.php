<?php

namespace App\Generations;

use App\Models\Charge;
use App\Models\Generation;
use App\Pricing\CostCalculator;
use App\Pricing\Rates;
use App\Pricing\TokenCounts;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The one place a Pixelforge generation object changes our local row.
 * The POST response, the webhook and the poller all call apply(), so they cannot disagree.
 */
final class ApplyPixelforgeGeneration
{
    // succeeded and failed share a rank: once one of them is set, the other cannot replace it.
    private const RANK = [
        Generation::QUEUED => 0,
        Generation::PROCESSING => 1,
        Generation::SUCCEEDED => 2,
        Generation::FAILED => 2,
    ];

    public function __construct(private readonly CostCalculator $calculator = new CostCalculator) {}

    /**
     * Returns our row (also when the object changed nothing), or null if the object is not ours.
     * Throws when the object is unusable; the transaction then rolls back and the row stays as it was.
     *
     * @param  array<string, mixed>  $object  A Pixelforge generation object.
     */
    public function apply(array $object): ?Generation
    {
        $status = $object['status'] ?? null;
        $pixelforgeId = $object['id'] ?? null;

        if (! is_string($pixelforgeId) || ! is_string($status) || ! isset(self::RANK[$status])) {
            throw new InvalidArgumentException('Generation object has no valid "id" or "status"');
        }

        // Nests as a savepoint inside the webhook's transaction; works on its own too.
        return DB::transaction(function () use ($object, $status, $pixelforgeId): ?Generation {
            $generation = $this->findAndLock($object, $pixelforgeId);

            if ($generation === null) {
                return null;
            }

            if ($generation->pixelforge_id !== null && $generation->pixelforge_id !== $pixelforgeId) {
                throw new RuntimeException("Generation {$generation->id} belongs to {$generation->pixelforge_id}, not {$pixelforgeId}");
            }

            // succeeded and failed are final: nothing may change them.
            if ($generation->isFinal()) {
                if ($status !== $generation->status && self::RANK[$status] === self::RANK[$generation->status]) {
                    Log::warning("Ignored {$status} for generation {$generation->id}: it is already {$generation->status}");
                }

                return $generation;
            }

            // Fill the id and rates before looking at the status, so a queued object
            // (the POST response) still stores them.
            $generation->pixelforge_id = $pixelforgeId;
            $this->fillRates($generation, $object);

            if (self::RANK[$status] > self::RANK[$generation->status]) {
                if ($status === Generation::PROCESSING) {
                    $generation->status = Generation::PROCESSING;
                } elseif ($status === Generation::SUCCEEDED) {
                    $this->succeed($generation, $object);
                } else {
                    $this->fail($generation, $object);
                }
            }

            $generation->save();

            return $generation;
        });
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function findAndLock(array $object, string $pixelforgeId): ?Generation
    {
        $metadata = $object['metadata'] ?? null;
        $localId = is_array($metadata) ? ($metadata['local_id'] ?? null) : null;
        $generation = null;

        // A malformed uuid makes Postgres raise an error, which would abort the whole transaction.
        if (is_string($localId) && Str::isUuid($localId)) {
            $generation = Generation::query()->whereKey($localId)->lockForUpdate()->first();
        }

        return $generation ?? Generation::query()->where('pixelforge_id', $pixelforgeId)->lockForUpdate()->first();
    }

    /**
     * The first object we see sets the rates; they are never overwritten (Pixelforge fixes them at creation).
     *
     * @param  array<string, mixed>  $object
     */
    private function fillRates(Generation $generation, array $object): void
    {
        if ($generation->rate_text !== null || ! is_array($object['rates'] ?? null)) {
            return;
        }

        $rates = Rates::fromPixelforge($object['rates']);

        $generation->rate_text = $rates->text;
        $generation->rate_image = $rates->image;
        $generation->rate_output = $rates->output;
        $generation->rate_reasoning = $rates->reasoning;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function succeed(Generation $generation, array $object): void
    {
        if (! is_array($object['usage'] ?? null)) {
            throw new InvalidArgumentException("Succeeded generation {$generation->id} has no usage");
        }

        if ($generation->rate_text === null || $generation->rate_image === null
            || $generation->rate_output === null || $generation->rate_reasoning === null) {
            throw new RuntimeException("Succeeded generation {$generation->id} has no rates");
        }

        $completedAt = $this->parseTime($object['completed_at'] ?? null);

        // The report groups by Pixelforge's completion day; our receive time would be wrong.
        if ($completedAt === null) {
            throw new InvalidArgumentException("Succeeded generation {$generation->id} has no completed_at");
        }

        $tokens = TokenCounts::fromPixelforge($object['usage']);
        $rates = new Rates(
            text: $generation->rate_text,
            image: $generation->rate_image,
            output: $generation->rate_output,
            reasoning: $generation->rate_reasoning,
        );

        $providerCost = $this->calculator->providerCost($tokens, $rates);
        $userPrice = Money::withMarkup($providerCost);

        $generation->status = Generation::SUCCEEDED;
        $generation->usage_text = $tokens->text;
        $generation->usage_image = $tokens->image;
        $generation->usage_output = $tokens->output;
        $generation->usage_reasoning = $tokens->reasoning;
        $generation->completed_at = $completedAt;
        $generation->image_url = is_string($object['image_url'] ?? null) ? $object['image_url'] : null;
        $generation->image_url_expires_at = $this->parseTime($object['image_url_expires_at'] ?? null);

        // ON CONFLICT DO NOTHING: a duplicate is skipped silently. A caught unique violation
        // would leave the Postgres transaction aborted.
        Charge::query()->insertOrIgnore([
            'generation_id' => $generation->id,
            'user_id' => $generation->user_id,
            'provider_cost_pico' => $providerCost,
            'user_price_pico' => $userPrice,
            'created_at' => now(),
        ]);
    }

    /**
     * Failed generations are never billed: no charge is written here.
     *
     * @param  array<string, mixed>  $object
     */
    private function fail(Generation $generation, array $object): void
    {
        $error = is_array($object['error'] ?? null) ? $object['error'] : [];

        $generation->status = Generation::FAILED;
        $generation->failure_code = is_string($error['code'] ?? null) ? $error['code'] : null;
        $generation->failure_message = is_string($error['message'] ?? null) ? $error['message'] : null;
        $generation->completed_at = $this->parseTime($object['completed_at'] ?? null);
    }

    private function parseTime(mixed $value): ?Carbon
    {
        return is_string($value) ? Carbon::parse($value)->utc() : null;
    }
}
