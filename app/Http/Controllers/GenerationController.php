<?php

namespace App\Http\Controllers;

use App\Generations\SubmitGeneration;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Pricing\Estimate;
use App\Pricing\Estimator;
use App\Pricing\PricingSource;
use App\Pricing\PricingUnavailableException;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every query goes through $request->user()->generations(), so a user can only ever reach their
 * own rows; someone else's id is a 404, the same as an id that does not exist.
 */
class GenerationController extends Controller
{
    /** History page: newest first. */
    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->with('charge')->latest()->paginate(20);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
        ]);
    }

    /** The generate form. The choices come from Pixelforge's pricing table. */
    public function create(PricingSource $pricing): Response
    {
        try {
            $table = $pricing->get()->table;
            $options = ['sizes' => $table->sizes(), 'qualities' => $table->qualities()];
            $error = null;
        } catch (PricingUnavailableException $e) {
            $options = ['sizes' => [], 'qualities' => []];
            $error = PricingUnavailableException::USER_MESSAGE;
        }

        return Inertia::render('generations/Create', [
            'options' => $options,
            'pricing_error' => $error,
            'limits' => ['prompt_max' => GenerationRequest::PROMPT_MAX, 'references_max' => GenerationRequest::REFERENCES_MAX],
        ]);
    }

    /** Price range for the form, before submitting. */
    public function estimate(GenerationRequest $request, Estimator $estimator): JsonResponse
    {
        return response()->json(['estimate' => $this->present($this->estimateFor($request, $estimator))]);
    }

    /**
     * Creates the row and queues the submit job, or does neither.
     *
     * The row id is the submission_id from the form, and the row id is also the Idempotency-Key
     * sent to Pixelforge. insertOrIgnore (ON CONFLICT DO NOTHING) makes a second post of the same
     * id a no-op: no second row, no second job. A caught unique violation would abort the Postgres
     * transaction, so none is used. An id that belongs to another user is ignored the same way and
     * looks exactly like a repeat, so it tells nothing about who owns it.
     */
    public function store(GenerationRequest $request, Estimator $estimator): RedirectResponse
    {
        $input = $request->generationInput();
        $submissionId = $request->submissionId();

        // Recomputed here: the number the browser showed is never trusted.
        $estimate = $this->estimateFor($request, $estimator);

        $created = DB::transaction(function () use ($request, $input, $submissionId, $estimate): bool {
            // insertOrIgnore skips the model, so the columns and the JSON are written out here.
            $inserted = Generation::query()->insertOrIgnore([
                'id' => $submissionId,
                'user_id' => $request->user()->id,
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_image_urls' => json_encode($input['reference_image_urls'], JSON_THROW_ON_ERROR),
                'status' => Generation::QUEUED,
                'estimate_min_pico' => $estimate->minUserPrice,
                'estimate_max_pico' => $estimate->maxUserPrice,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            // afterCommit: the job is only sent once the row is really committed, and not at all
            // if this transaction (or an outer one) rolls back.
            SubmitGeneration::dispatch($submissionId)->afterCommit();

            return true;
        });

        if (! $created) {
            // The kit shows this flash as a toast on whatever page comes next.
            Inertia::flash('toast', ['type' => 'info', 'message' => 'That form was already submitted. Open Generate again for a new image.']);
        }

        return redirect()->route('generations.index');
    }

    /** JSON for polling: the user's rows that Pixelforge has not finished yet. */
    public function pending(Request $request): AnonymousResourceCollection
    {
        $rows = $request->user()->generations()
            ->whereNotIn('status', [Generation::SUCCEEDED, Generation::FAILED])
            ->with('charge')->latest()->get();

        return GenerationResource::collection($rows);
    }

    /** JSON for one row (the poller asks for a row that has left the pending list). */
    public function show(Request $request, string $generation): GenerationResource
    {
        return new GenerationResource(
            $request->user()->generations()->with('charge')->whereKey($generation)->firstOrFail(),
        );
    }

    private function estimateFor(GenerationRequest $request, Estimator $estimator): Estimate
    {
        $input = $request->generationInput();
        $pricing = $request->pricing();

        return $estimator->estimate(
            $input['prompt'],
            $input['size'],
            $input['quality'],
            count($input['reference_image_urls']),
            $pricing->rates,
            $pricing->table,
        );
    }

    /**
     * What the form may show: the USER price range as strings, and how many tokens of each kind.
     * No provider cost, no markup, no rates.
     *
     * @return array<string, mixed>
     */
    private function present(Estimate $estimate): array
    {
        $tokens = fn ($t) => ['text' => $t->text, 'image' => $t->image, 'output' => $t->output, 'reasoning' => $t->reasoning];

        return [
            'min_price' => Money::formatUsd($estimate->minUserPrice),
            'max_price' => Money::formatUsd($estimate->maxUserPrice),
            'tokens' => ['min' => $tokens($estimate->minTokens), 'max' => $tokens($estimate->maxTokens)],
        ];
    }
}
