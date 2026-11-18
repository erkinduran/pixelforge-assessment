<?php

namespace App\Models;

use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Money columns are integer picodollars (1 USD = 10^12); rates are picodollars per token.
 * The final cost and price live only on the charge, not here.
 * user_id is null after the user deleted their account: the generation (real money) stays.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property array<int, string> $reference_image_urls
 * @property string $status
 * @property string|null $pixelforge_id
 * @property int $estimate_min_pico
 * @property int $estimate_max_pico
 * @property int|null $rate_text
 * @property int|null $rate_image
 * @property int|null $rate_output
 * @property int|null $rate_reasoning
 * @property int|null $usage_text
 * @property int|null $usage_image
 * @property int|null $usage_output
 * @property int|null $usage_reasoning
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property Carbon|null $completed_at
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Charge|null $charge
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    // Only the request and the estimate can be mass-assigned. Status, rates, usage and the
    // rest are set one by one in ApplyPixelforgeGeneration.
    protected $fillable = ['prompt', 'size', 'quality', 'reference_image_urls', 'estimate_min_pico', 'estimate_max_pico'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reference_image_urls' => 'array',
            'estimate_min_pico' => 'integer',
            'estimate_max_pico' => 'integer',
            'rate_text' => 'integer',
            'rate_image' => 'integer',
            'rate_output' => 'integer',
            'rate_reasoning' => 'integer',
            'usage_text' => 'integer',
            'usage_image' => 'integer',
            'usage_output' => 'integer',
            'usage_reasoning' => 'integer',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasOne<Charge, $this> */
    public function charge(): HasOne
    {
        return $this->hasOne(Charge::class);
    }

    public function isFinal(): bool
    {
        return $this->status === self::SUCCEEDED || $this->status === self::FAILED;
    }
}
