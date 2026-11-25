<?php

return [

    /*
     * Pages live in resources/js/Pages (capital P). The package default is
     * js/pages, which only matches on case-insensitive filesystems (macOS),
     * so page lookups failed on Linux (CI, production).
     */
    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('js/Pages'),
        ],

        'extensions' => ['tsx', 'ts'],

    ],

];
