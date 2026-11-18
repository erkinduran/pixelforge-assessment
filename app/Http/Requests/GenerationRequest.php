<?php

namespace App\Http\Requests;

use App\Pricing\Pricing;
use App\Pricing\PricingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The input of both the estimate call and the real submit, so they cannot disagree.
 * Limits are from the docs (create a generation). Sizes and qualities come from the pricing
 * table, the same place the estimator reads, so a choice that passes here can always be priced.
 */
class GenerationRequest extends FormRequest
{
    /** Docs limits. The create page shows the same numbers, so they live only here. */
    public const PROMPT_MAX = 4000;

    public const REFERENCES_MAX = 4;

    private ?Pricing $pricing = null;

    public function authorize(): bool
    {
        return true; // the route is behind the auth middleware
    }

    /** One pricing answer for the whole request (throws PricingUnavailableException). */
    public function pricing(): Pricing
    {
        return $this->pricing ??= app(PricingSource::class)->get();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $table = $this->pricing()->table;

        return [
            // Only the real submit carries one; the estimate call does not.
            ...($this->routeIs('generations.store') ? ['submission_id' => ['required', 'string', 'uuid']] : []),
            'prompt' => ['required', 'string', 'max:'.self::PROMPT_MAX],
            'size' => ['required', 'string', Rule::in($table->sizes())],
            'quality' => ['required', 'string', Rule::in($table->qualities())],
            // Docs: at most 4 http(s) URLs. The 2048 length limit is ours.
            'reference_image_urls' => ['nullable', 'array', 'max:'.self::REFERENCES_MAX],
            'reference_image_urls.*' => ['required', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /** The id the create page handed out for this one submit. It becomes the row id and the Idempotency-Key. */
    public function submissionId(): string
    {
        return (string) $this->validated('submission_id');
    }

    /** @return array{prompt: string, size: string, quality: string, reference_image_urls: list<string>} */
    public function generationInput(): array
    {
        /** @var array{prompt: string, size: string, quality: string, reference_image_urls?: array<array-key, string>|null} $data */
        $data = $this->validated();

        return [
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_image_urls' => array_values($data['reference_image_urls'] ?? []),
        ];
    }
}
