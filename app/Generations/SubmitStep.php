<?php

namespace App\Generations;

/** What the submit job does after a failed attempt. */
enum SubmitStep
{
    /** Put the job back on the queue and try again after N seconds. */
    case Release;

    /** Write a local failure on the row: nothing was created at Pixelforge. */
    case Fail;

    /** Stop and leave the row queued. The fallback poller picks it up later. */
    case GiveUp;
}
