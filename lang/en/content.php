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
        'descendants_too_long' => 'The address of a subpage would be longer than :max characters.',
    ],
    'entry' => [
        'locale_invalid' => 'This language is not on the site.',
        'parent_invalid' => 'Choose a parent of the same type and language that is not this record or one of its subpages.',
        'author_invalid' => 'This user cannot be the author of this content.',
        'body_too_long' => 'The text is too long.',
        'publish_forbidden' => 'Publishing or scheduling needs the right to edit this content. Save it as a draft.',
        'trashed' => 'Restore the record from the trash before editing it.',
        'descendants_too_long' => 'The address of a subpage would be longer than :max characters.',
    ],
    'usage' => [
        'entry' => ':type: :title',
        'shared' => ':type: :title (all languages)',
    ],
];
