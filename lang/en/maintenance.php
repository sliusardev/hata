<?php

return [
    'title' => 'Maintenance',
    'description' => 'Service actions for after a deploy. They change the site for everyone, so each one asks before it runs.',
    'tabs' => [
        'actions' => 'Actions',
        'journal' => 'Journal',
    ],
    'actions' => [
        'migrate' => [
            'title' => 'Run migrations',
            'description' => 'Applies the database changes that came with new code. Only forward: nothing is rolled back.',
            'pending' => 'Not applied yet',
            'none' => 'Everything is applied.',
            'button' => 'Run migrations',
            'confirm' => '{1} :count migration will run now. This changes the database and cannot be undone from here.|[2,*] :count migrations will run now. This changes the database and cannot be undone from here.',
        ],
        'seed' => [
            'title' => 'Run a seeder',
            'description' => 'Runs one seeder from database/seeders. A seeder may add or change data.',
            'select' => 'Seeder',
            'none' => 'The project has no seeders.',
            'button' => 'Run seeder',
            'confirm' => 'The seeder :seeder will run now. It may add or change data, and this cannot be undone from here.',
        ],
        'clear_caches' => [
            'title' => 'Clear caches',
            'description' => 'Removes the cached config, routes, views, events and application cache. The site is a little slower until the caches are rebuilt.',
            'button' => 'Clear caches',
            'confirm' => 'All caches will be cleared. The site keeps working, a little slower, until you rebuild them.',
        ],
        'rebuild_caches' => [
            'title' => 'Rebuild caches',
            'description' => 'Caches the config, routes, views and events so every request is faster. Run it again after changing .env or routes.',
            'button' => 'Rebuild caches',
            'confirm' => 'The caches will be rebuilt. Later changes to .env and routes are not picked up until you rebuild or clear them again.',
            'confirm_local' => 'This is a local environment: with cached config and routes your code changes to .env and routes stop showing until you clear the caches.',
        ],
    ],
    'confirm' => [
        'heading' => 'Run this action?',
        'running' => 'Running… do not close the page.',
        'button' => 'Run',
    ],
    'result' => [
        'heading' => 'Last result',
        'duration' => ':seconds s',
        'no_output' => 'The command printed nothing.',
        'truncated' => 'The output was long; only its end is kept.',
        'all_runs' => 'All runs are in the journal',
    ],
    'journal' => [
        'description' => 'The latest :count runs are kept.',
        'empty_title' => 'Nothing has been run yet',
        'empty_description' => 'Runs will appear here with who started them and how they ended.',
        'when' => 'When',
        'who' => 'Who',
        'action' => 'Action',
        'status' => 'Status',
        'duration' => 'Duration',
        'actions' => 'Actions',
        'view_output' => 'Show output',
        'output_heading' => 'Output',
    ],
    'statuses' => [
        'running' => 'Running',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'interrupted' => 'Interrupted',
    ],
    'messages' => [
        'succeeded' => ':action: done.',
        'failed' => ':action: failed. See the output below.',
    ],
    'errors' => [
        'migrate_first' => 'Run the migrations first: the journal table of this page has not been created yet.',
        'busy' => 'Another maintenance action is running. Wait until it finishes.',
        'unknown_seeder' => 'This seeder is not in the list.',
        'media_batch_running' => 'A media optimization or move is running. Clear the caches after it finishes.',
        'interrupted' => 'The process stopped before the command finished (time or memory limit).',
        'nothing_pending' => 'There are no migrations to run.',
    ],
];
