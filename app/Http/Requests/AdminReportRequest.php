<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The date range of the admin report: UTC days, inclusive. Default: the last 30 days ending today
 * (docs, GET /usage). At most 92 days, the same limit Pixelforge's usage endpoint has.
 */
class AdminReportRequest extends FormRequest
{
    public const MAX_DAYS = 92;

    private const DEFAULT_DAYS = 30;

    public function authorize(): bool
    {
        return true; // the route has the auth and admin middleware
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->to()->lt($this->from())) {
                $validator->errors()->add('to', 'The end date cannot be before the start date.');
            } elseif ($this->from()->diffInDays($this->to()) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('to', 'The range can be at most '.self::MAX_DAYS.' days.');
            }
        }];
    }

    public function to(): CarbonImmutable
    {
        $to = $this->query('to');

        return is_string($to) && $to !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC') : CarbonImmutable::instance(now()->utc()->startOfDay());
    }

    public function from(): CarbonImmutable
    {
        $from = $this->query('from');

        return is_string($from) && $from !== ''
            ? CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC')
            : $this->to()->subDays(self::DEFAULT_DAYS - 1);
    }
}
