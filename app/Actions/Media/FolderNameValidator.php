<?php

namespace App\Actions\Media;

use App\Models\MediaFolder;
use Illuminate\Validation\ValidationException;

/**
 * Shared name rules for creating and renaming folders.
 */
class FolderNameValidator
{
    /**
     * @return string The trimmed name.
     *
     * @throws ValidationException
     */
    public function validate(string $name, ?int $parentId, ?int $ignoreId = null): string
    {
        $name = (string) preg_replace('/^[\s\x{00A0}\x{200B}]+|[\s\x{00A0}\x{200B}]+$/u', '', $name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => __('media.errors.folder_name')]);
        }

        $exists = MediaFolder::query()
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => __('media.errors.folder_exists')]);
        }

        return $name;
    }
}
