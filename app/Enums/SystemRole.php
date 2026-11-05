<?php

namespace App\Enums;

/**
 * Roles created by the seeder. They are flagged as is_system and cannot be deleted.
 */
enum SystemRole: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Editor = 'editor';
    case Author = 'author';
}
