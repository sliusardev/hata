<?php

namespace App\Enums;

enum OptimizeOutcome: string
{
    case Optimized = 'optimized';
    case AlreadyOptimal = 'already_optimal';
    case TooLarge = 'too_large';
    case Unreadable = 'unreadable';
    case Missing = 'missing';
}
