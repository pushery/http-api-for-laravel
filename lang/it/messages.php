<?php

declare(strict_types=1);

return [
    'problems' => [
        'validation' => 'Alcuni campi della richiesta non sono validi.',
        'authentication' => 'La richiesta non è autenticata.',
        'server' => 'Non è stato possibile completare la richiesta.',
    ],
    'idempotency-key-missing' => [
        'title' => 'Chiave di idempotenza mancante',
        'detail' => 'Questa richiesta modifica qualcosa e richiede un\'intestazione Idempotency-Key.',
    ],
    'idempotency-key-malformed' => [
        'title' => 'Chiave di idempotenza non valida',
        'detail' => 'L\'intestazione Idempotency-Key è troppo lunga o contiene caratteri diversi dall\'ASCII stampabile.',
    ],
    'idempotency-key-in-flight' => [
        'title' => 'Richiesta ancora in corso',
        'detail' => 'Una richiesta con questa Idempotency-Key è ancora in elaborazione. Potrà essere inviata di nuovo quando sarà terminata.',
    ],
    'idempotency-answer-not-kept' => [
        'title' => 'Risposta non conservata',
        'detail' => 'La richiesta con questa Idempotency-Key è stata eseguita, ma la sua risposta non è stata conservata. Il suo risultato può essere consultato invece di inviare di nuovo la richiesta.',
    ],
    'idempotency-key-reused' => [
        'title' => 'Chiave di idempotenza riutilizzata',
        'detail' => 'Questa Idempotency-Key è già stata usata per un\'altra richiesta.',
    ],
    'idempotency-scope-missing' => [
        'title' => 'Chiave di idempotenza non mantenuta',
        'detail' => 'Il server non riesce a capire chi ha inviato questa richiesta, quindi non può mantenere la sua Idempotency-Key.',
    ],
];
