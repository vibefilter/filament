<?php

return [

    'number' => [
        'decimal' => ',',
        'thousands' => '.',
    ],

    'filter' => [
        'label' => 'Vibe',
        'statement' => 'Mostrar filas donde…',
        'placeholder' => 'El cliente está enojado',
        'indicator' => 'Vibe: :statement',
    ],

    'limit' => [
        'title' => ':count fila necesita una puntuación nueva|:count filas necesitan una puntuación nueva',
        'body' => 'La tabla tiene :total filas con los demás filtros y la búsqueda aplicados, y :unscored de ellas aún no tienen una puntuación guardada para esta afirmación. El límite es :limit.',
        'hint' => 'Reduce la tabla con otros filtros o una búsqueda para quedar por debajo, o ejecútalo ahora en todas. La tabla no se filtra hasta entonces.',
        'run_anyway' => 'Ejecutar de todos modos',
    ],

    'progress' => [
        'scoring' => 'Puntuando :count fila|Puntuando :count filas',
        'requests' => ':done / :total solicitud completada|:done / :total solicitudes completadas',
        'retried' => ':count reintentadas',
        'label' => 'Progreso de Vibefilter',
    ],

    'report' => [
        'title' => ':matching de :total filas coinciden con el vibe',
        'scored' => ':count fila puntuada|:count filas puntuadas',
        'scored_in' => ':count fila puntuada en :requests|:count filas puntuadas en :requests',
        'requests' => ':count solicitud|:count solicitudes',
        'retried' => ':count reintentadas porque la API no respondió',
        'cost' => "Costo:\u{00A0}\$:amount",
        'seconds' => "Tiempo:\u{00A0}:seconds\u{00A0}s",
        'cached' => ':count desde la caché',
    ],

    'failure' => [
        'title' => 'Vibefilter no pudo ejecutarse',
        'unfiltered' => 'La tabla no está filtrada.',
        'partial_title' => ':scored de :total filas puntuadas',
        'partial_body' => 'La API no respondió para :count fila, así que la tabla solo muestra coincidencias entre las puntuadas. Al reintentar se envía solo la fila que falta.|La API no respondió para :count filas, así que la tabla solo muestra coincidencias entre las puntuadas. Al reintentar se envían solo las :count que faltan.',
        'try_again' => 'Reintentar',
    ],

];
