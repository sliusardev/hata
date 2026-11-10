<?php

return [

    'index' => [
        'title' => 'Media library',
        'description' => 'Images and documents for pages and posts.',
        'search' => 'Search by name or alt text',
        'file_count' => ':count file|:count files',
        'unused' => 'unused',
        'show_more' => 'Show :count more',
        'no_alt' => 'No alt text',
        'nowhere' => 'Nowhere',
        'used_count' => ':count place|:count places',
        'open' => 'Open file',
        'filters' => [
            'type' => 'File type',
            'all_types' => 'All types',
            'month' => 'Date',
            'any_date' => 'Any date',
            'usage' => 'Usage',
            'all_files' => 'All files',
            'unused' => 'Not used anywhere',
        ],
        'types' => [
            'image' => 'Images',
            'document' => 'Documents',
            'other' => 'Other',
        ],
        'views' => [
            'label' => 'View',
            'grid' => 'Grid',
            'list' => 'List',
        ],
        'folders' => [
            'label' => 'Folders',
            'all' => 'All files',
            'none' => 'No folder',
            'heading' => 'Folders',
            'path' => 'Folder path',
        ],
        'columns' => [
            'file' => 'File',
            'type' => 'Type',
            'size' => 'Size',
            'usage' => 'Where it’s used',
            'uploaded' => 'Uploaded',
            'actions' => 'Actions',
        ],
        'empty' => [
            'title' => 'No files yet',
            'description' => 'Upload images and documents, and they will appear here.',
        ],
        'no_results' => [
            'title' => 'Nothing found',
            'description' => 'Try another search or clear the filters.',
        ],
    ],

    'errors' => [
        'unsupported' => 'The file ":name" is not supported.',
        'too_large' => 'File is too large: :size MB, the limit is :limit MB.',
        'heic_unsupported' => 'HEIC can’t be converted on this server, export the photo as JPG.',
        'folder_name' => 'Folder name must be 1–100 characters.',
        'limit_above_server' => 'The limit cannot be higher than the server allows (:size).',
        'folder_exists' => 'A folder with this name already exists here.',
    ],

];
