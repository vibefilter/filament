<?php

return [

    'number' => [
        'decimal' => '.',
        'thousands' => ',',
    ],

    'filter' => [
        'label' => 'Vibe',
        'statement' => 'Show rows where…',
        'placeholder' => 'The customer is angry',
        'indicator' => 'Vibe: :statement',
    ],

    'column' => [
        'label' => 'Score',
    ],

    'limit' => [
        'title' => ':count row needs a fresh score|:count rows need a fresh score',
        'body' => 'The table has :total rows with the other filters and the search applied, and :unscored of them have no cached score for this statement yet. The limit is :limit.',
        'hint' => 'Narrow the table down with other filters or a search to get under it, or run it on all of them now. The table isn\'t filtered until then.',
        'run_anyway' => 'Run anyway',
    ],

    'progress' => [
        'scoring' => 'Scoring :count row|Scoring :count rows',
        'requests' => ':done / :total request done|:done / :total requests done',
        'retried' => ':count retried',
        'label' => 'Vibefilter progress',
    ],

    'report' => [
        'title' => ':matching of :total rows match the vibe',
        'scored' => ':count row scored|:count rows scored',
        'scored_in' => ':count row scored in :requests|:count rows scored in :requests',
        'requests' => ':count request|:count requests',
        'retried' => ':count retried after the API didn\'t answer',
        'cost' => "Cost:\u{00A0}\$:amount",
        'seconds' => "Time:\u{00A0}:seconds\u{00A0}s",
        'cached' => ':count from the cache',
    ],

    'failure' => [
        'title' => 'Vibefilter could not run',
        'unfiltered' => 'The table isn\'t filtered.',
        'partial_title' => ':scored of :total rows scored',
        'partial_body' => 'The API didn\'t answer for :count row, so the table only shows matches among the scored ones. Trying again sends just the missing :count.|The API didn\'t answer for :count rows, so the table only shows matches among the scored ones. Trying again sends just the missing :count.',
        'try_again' => 'Try again',
    ],

];
