<?php

namespace Hata\Enums;

enum OptimizeOutcome: string
{
    case Optimized = 'optimized';
    case AlreadyOptimal = 'already_optimal';
    case TooLarge = 'too_large';
    case Unreadable = 'unreadable';
    case Missing = 'missing';

    /** The file was cropped or replaced while the batch processed it; that change is kept. */
    case Changed = 'changed';
}
