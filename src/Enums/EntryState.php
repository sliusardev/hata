<?php

namespace App\Enums;

/**
 * What a record is right now: a draft, published for a future moment, or live.
 */
enum EntryState
{
    case Draft;
    case Scheduled;
    case Published;
}
