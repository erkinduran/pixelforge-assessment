<?php

namespace App\Pixelforge;

/** What the caller (the submit job) should do after a Pixelforge call failed. */
enum Decision: string
{
    /** Concurrency limit: wait for Retry-After and try again, with a long time budget. */
    case Wait = 'wait';

    /** Transient problem: try again, with a capped number of attempts. */
    case Retry = 'retry';

    /** Permanent problem: never send this request again. */
    case Fail = 'fail';
}
