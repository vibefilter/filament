<?php

return [

    /*
    | The decision driver used to answer questions about rows: "typesafe" for
    | TypeSafe's own API, or "openrouter" for Jev through OpenRouter.
    */
    'driver' => env('VIBEFILTER_DRIVER', 'typesafe'),

    'drivers' => [
        'typesafe' => [
            'api_key' => env('TYPESAFE_API_KEY'),
            'base_url' => env('TYPESAFE_BASE_URL', 'https://api.typesafe.ai/v1'),
            // A fixed version rather than "jev-latest": cached scores are kept per
            // model, and an alias would silently mix scores from two versions.
            'model' => env('TYPESAFE_MODEL', 'jev-1.13.0'),
            'timeout' => 60,
            // Pauses (ms) before retrying a batch that hit a rate limit or a server error.
            'retry_delays' => [500, 2000, 5000],
        ],

        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            // OpenRouter's name for Jev; unlike TypeSafe it has no "latest" alias.
            'model' => env('OPENROUTER_MODEL', 'typesafe/jev-1.13'),
            'timeout' => 60,
            'retry_delays' => [500, 2000, 5000],
            // OpenRouter's app attribution headers. The URL is what creates an app page
            // and puts the usage in OpenRouter's rankings; set it to your own app's URL,
            // or empty to send no attribution. "hidden" keeps a newly created app out of
            // the public rankings; it only counts on the very first request.
            'attribution' => [
                'url' => env('VIBEFILTER_OPENROUTER_APP_URL', 'https://vibefilter.dev'),
                'title' => env('VIBEFILTER_OPENROUTER_APP_TITLE', 'Vibefilter'),
                'visibility' => env('VIBEFILTER_OPENROUTER_APP_VISIBILITY'),
            ],
        ],
    ],

    /*
    | Rows scoring at or above this probability pass the filter.
    */
    'threshold' => 0.8,

    /*
    | Above this many rows without a cached score, the filter doesn't run on its
    | own: it tells the user how many rows it would send and offers to narrow
    | the table down or to run anyway. Cached rows don't count.
    */
    'max_unscored_rows' => (int) env('VIBEFILTER_MAX_UNSCORED_ROWS', 1000),

    /*
    | Rows sent to the driver in one request, and requests sent in parallel.
    */
    'batch_size' => 100,
    'concurrency' => 10,

];
