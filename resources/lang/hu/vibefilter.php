<?php

return [

    'number' => [
        'decimal' => ',',
        // A non-breaking space, so a number never wraps between its digits.
        'thousands' => "\u{00A0}",
    ],

    'filter' => [
        'label' => 'Vibe',
        'statement' => 'Azok a sorok, ahol…',
        'placeholder' => 'Az ügyfél dühös',
        'indicator' => 'Vibe: :statement',
    ],

    'column' => [
        'label' => 'Pontszám',
    ],

    'limit' => [
        'title' => ':count sor vár pontozásra',
        'body' => 'A többi szűrő és a keresés után :total sor maradt a táblában, és ebből :unscored sornak még nincs mentett pontszáma erre az állításra. A korlát :limit.',
        'hint' => 'Szűkítsd a táblát más szűrővel vagy kereséssel, hogy beleférjen, vagy futtasd le most mindegyikre. Addig a tábla nincs szűrve.',
        'run_anyway' => 'Futtasd mégis',
    ],

    'progress' => [
        'scoring' => ':count sor pontozása',
        'requests' => ':done / :total kérés kész',
        'retried' => ':count újrapróbálva',
        'label' => 'A Vibefilter haladása',
    ],

    'report' => [
        'title' => ':total sorból :matching illik a vibe-hoz',
        'scored' => ':count sor pontozva',
        'scored_in' => ':count sor pontozva :requests',
        'requests' => ':count kérésben',
        'retried' => ':count újrapróbálva, mert az API nem válaszolt',
        'cost' => "Költség:\u{00A0}\$:amount",
        'seconds' => "Idő:\u{00A0}:seconds\u{00A0}mp",
        'cached' => ':count a cache-ből',
    ],

    'failure' => [
        'title' => 'A Vibefilter nem tudott lefutni',
        'unfiltered' => 'A tábla nincs szűrve.',
        'partial_title' => ':total sorból :scored pontozva',
        'partial_body' => ':count sorra nem válaszolt az API, ezért a tábla csak a pontozott sorok közül mutatja a találatokat. Újrapróbáláskor csak a hiányzó :count sor megy ki.',
        'try_again' => 'Újrapróbálás',
    ],

];
