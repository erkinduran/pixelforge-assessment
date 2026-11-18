<?php

namespace Tests\Feature\Generations;

use App\Models\Generation;

/**
 * Pixelforge generation objects for tests.
 *
 * queued.json and succeeded.json are copied from the docs' examples (docs/pixelforge-api.md L144, L222).
 * The processing and failed objects are COMPOSED from those two: the docs show no processing example
 * and only the `error` fragment of a failed one (L258). The docs' metadata has no local_id, so each
 * object gets the id of our row, as the real submit job will send it.
 */
trait PixelforgeObjects
{
    /** @return array<string, mixed> */
    private function queuedObject(Generation $generation): array
    {
        return $this->withLocalId($this->readFixture('queued.json'), $generation);
    }

    /** @return array<string, mixed> */
    private function processingObject(Generation $generation): array
    {
        return [...$this->queuedObject($generation), 'status' => 'processing', 'started_at' => '2026-11-04T14:03:13Z'];
    }

    /** @return array<string, mixed> */
    private function succeededObject(Generation $generation): array
    {
        return $this->withLocalId($this->readFixture('succeeded.json'), $generation);
    }

    /** @return array<string, mixed> */
    private function failedObject(Generation $generation): array
    {
        return [
            ...$this->processingObject($generation),
            'status' => 'failed',
            'error' => [
                'code' => 'internal_error',
                'message' => 'Something went wrong on our side while generating. You were not charged; please retry.',
            ],
            'completed_at' => '2026-11-04T14:03:25Z',
        ];
    }

    /**
     * Objects captured from the real Pixelforge API on 2026-11-18 (real-*.json; image_url signature replaced
     * by a fake). $name is queued, processing, succeeded or failed. Every key is present, null when empty.
     *
     * @return array<string, mixed>
     */
    private function realObject(string $name, Generation $generation): array
    {
        return $this->withLocalId($this->readFixture("real-{$name}.json"), $generation);
    }

    /** @return array<string, mixed> */
    private function readFixture(string $name): array
    {
        $json = file_get_contents(__DIR__.'/fixtures/'.$name);

        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function withLocalId(array $object, Generation $generation): array
    {
        $object['metadata']['local_id'] = $generation->id;

        return $object;
    }
}
