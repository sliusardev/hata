<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One site setting stored by the admin. Read and written through App\Support\Settings\SiteSettings.
 *
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
