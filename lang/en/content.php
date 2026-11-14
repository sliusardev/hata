<?php

return [
    'types' => [
        'pages' => [
            'label' => 'Page',
            'plural' => 'Pages',
        ],
    ],
    'fields' => [],
    'slug' => [
        'format' => 'The address part may contain only lowercase Latin letters, digits and single hyphens.',
        'reserved' => 'This address part is reserved. Choose another one.',
        'taken' => 'Another record already uses this address.',
        'too_long' => 'The full address would be longer than :max characters.',
    ],
];
