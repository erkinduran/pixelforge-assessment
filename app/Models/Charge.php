<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per succeeded generation, ever (unique generation_id). Amounts are picodollars.
 * user_id is null after the user deleted their account.
 *
 * @property int $id
 * @property string $generation_id
 * @property int|null $user_id
 * @property int $provider_cost_pico
 * @property int $user_price_pico
 * @property Carbon|null $created_at
 */
class Charge extends Model
{
    // A charge is written once and never changed, so there is no updated_at.
    public const UPDATED_AT = null;

    // Never mass-assigned: the only writer is ApplyPixelforgeGeneration.
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider_cost_pico' => 'integer',
            'user_price_pico' => 'integer',
        ];
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
