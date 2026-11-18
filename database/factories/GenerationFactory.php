<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A freshly submitted generation: queued, no Pixelforge id and no rates yet.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_image_urls' => ['https://example.com/style.png'],
            'status' => Generation::QUEUED,
            'estimate_min_pico' => 100_000_000_000,
            'estimate_max_pico' => 467_343_750_000,
        ];
    }
}
