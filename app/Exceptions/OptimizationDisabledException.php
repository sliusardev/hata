<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a run of "Optimize existing files" is started while image optimization is off: such a run could
 * never move. Nothing was saved. Callers check `media.optimize.enabled` first and show their own message.
 */
class OptimizationDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Image optimization is off, so existing files can not be optimized.');
    }
}
