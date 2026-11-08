<?php

declare(strict_types=1);

return [
    'problems' => [
        'validation' => 'Sommige velden van het verzoek zijn ongeldig.',
        'authentication' => 'Het verzoek is niet geauthenticeerd.',
        'server' => 'Het verzoek kon niet worden voltooid.',
    ],
    'idempotency-key-missing' => [
        'title' => 'Idempotentiesleutel ontbreekt',
        'detail' => 'Dit verzoek wijzigt iets en heeft een Idempotency-Key-header nodig.',
    ],
    'idempotency-key-malformed' => [
        'title' => 'Idempotentiesleutel ongeldig',
        'detail' => 'De Idempotency-Key-header is te lang of bevat andere tekens dan afdrukbare ASCII.',
    ],
    'idempotency-key-in-flight' => [
        'title' => 'Verzoek nog bezig',
        'detail' => 'Een verzoek met deze Idempotency-Key wordt nog verwerkt. Het kan opnieuw worden verstuurd zodra dat klaar is.',
    ],
    'idempotency-answer-not-kept' => [
        'title' => 'Antwoord niet bewaard',
        'detail' => 'Het verzoek met deze Idempotency-Key is uitgevoerd, maar het antwoord kon niet worden bewaard. Het resultaat kan worden opgevraagd in plaats van het verzoek opnieuw te versturen.',
    ],
    'idempotency-key-reused' => [
        'title' => 'Idempotentiesleutel hergebruikt',
        'detail' => 'Deze Idempotency-Key is al gebruikt voor een ander verzoek.',
    ],
    'idempotency-scope-missing' => [
        'title' => 'Idempotentiesleutel niet vastgehouden',
        'detail' => 'De server kan niet vaststellen wie dit verzoek stuurde en kan de Idempotency-Key daarom niet vasthouden.',
    ],
];
