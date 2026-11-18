<?php

namespace App\Support;

use RuntimeException;

/** Thrown when an amount or rate cannot be represented exactly as integer picodollars. */
class InvalidMoneyException extends RuntimeException {}
