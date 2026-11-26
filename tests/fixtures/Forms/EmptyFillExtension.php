<?php

namespace Tests\Fixtures\Forms;

use Hata\Support\Admin\Forms\FormContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only form card whose fill() names none of its fields.
 */
class EmptyFillExtension extends SubtitleExtension
{
    public function fill(?Model $record, FormContext $context): array
    {
        return [];
    }
}
