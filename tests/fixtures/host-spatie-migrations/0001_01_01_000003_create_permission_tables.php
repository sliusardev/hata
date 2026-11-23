<?php

// A host that installed spatie/laravel-permission before Hata: spatie's own published migration, run before Hata's.
return require dirname(__DIR__, 3).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
