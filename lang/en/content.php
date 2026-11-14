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
    'entry' => [
        'locale_invalid' => 'This language is not on the site.',
        'parent_invalid' => 'Choose a parent of the same type and language that is not this record or one of its subpages.',
        'author_invalid' => 'This user cannot be the author of this content.',
        'body_too_long' => 'The text is too long.',
    ],
    'usage' => [
        'entry' => ':type: :title',
        'shared' => ':type: :title (all languages)',
    ],
];
