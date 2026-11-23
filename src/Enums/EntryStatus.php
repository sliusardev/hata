<?php

namespace Hata\Enums;

/**
 * The stored status of a content record. "Scheduled" is not stored: see EntryState.
 */
enum EntryStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
