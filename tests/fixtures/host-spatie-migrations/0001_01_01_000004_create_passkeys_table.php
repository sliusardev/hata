<?php

// A host that published laravel/passkeys' migration before Hata: the package's own migration, run before Hata's.
return require dirname(__DIR__, 3).'/vendor/laravel/passkeys/database/migrations/2024_01_01_000000_create_passkeys_table.php';
