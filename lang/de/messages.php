<?php

declare(strict_types=1);

return [
    'problems' => [
        'validation' => 'Einige Felder der Anfrage sind ungültig.',
        'authentication' => 'Die Anfrage ist nicht angemeldet.',
        'server' => 'Die Anfrage konnte nicht abgeschlossen werden.',
    ],
    'idempotency-key-missing' => [
        'title' => 'Idempotency-Key fehlt',
        'detail' => 'Diese Anfrage ändert etwas und braucht einen Idempotency-Key-Header.',
    ],
    'idempotency-key-malformed' => [
        'title' => 'Idempotency-Key ungültig',
        'detail' => 'Der Idempotency-Key-Header ist zu lang oder enthält andere Zeichen als druckbares ASCII.',
    ],
    'idempotency-key-in-flight' => [
        'title' => 'Anfrage läuft noch',
        'detail' => 'Eine Anfrage mit diesem Idempotency-Key wird noch verarbeitet. Sie kann erneut gesendet werden, sobald diese abgeschlossen ist.',
    ],
    'idempotency-answer-not-kept' => [
        'title' => 'Antwort nicht aufbewahrt',
        'detail' => 'Die Anfrage mit diesem Idempotency-Key wurde ausgeführt, ihre Antwort konnte aber nicht aufbewahrt werden. Ihr Ergebnis kann abgefragt werden, statt die Anfrage erneut zu senden.',
    ],
    'idempotency-key-reused' => [
        'title' => 'Idempotency-Key schon benutzt',
        'detail' => 'Dieser Idempotency-Key wurde bereits für eine andere Anfrage verwendet.',
    ],
    'idempotency-scope-missing' => [
        'title' => 'Idempotency-Key nicht gehalten',
        'detail' => 'Der Server kann nicht erkennen, wer diese Anfrage gesendet hat, und kann ihren Idempotency-Key deshalb nicht halten.',
    ],
];
