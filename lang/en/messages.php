<?php

declare(strict_types=1);

return [
    'problems' => [
        'validation' => 'Some fields of the request are not valid.',
        'authentication' => 'The request is not authenticated.',
        'server' => 'The request could not be completed.',
    ],
    'idempotency-key-missing' => [
        'title' => 'Idempotency key missing',
        'detail' => 'This request changes something and needs an Idempotency-Key header.',
    ],
    'idempotency-key-malformed' => [
        'title' => 'Idempotency key malformed',
        'detail' => 'The Idempotency-Key header is too long or holds characters other than printable ASCII.',
    ],
    'idempotency-key-in-flight' => [
        'title' => 'Request still in progress',
        'detail' => 'A request with this Idempotency-Key is still being processed. Send it again once that one has finished.',
    ],
    'idempotency-answer-not-kept' => [
        'title' => 'Answer not kept',
        'detail' => 'The request with this Idempotency-Key was carried out, but its answer could not be kept. Look its result up rather than sending it again.',
    ],
    'idempotency-key-reused' => [
        'title' => 'Idempotency key reused',
        'detail' => 'This Idempotency-Key was already used for a different request.',
    ],
    'idempotency-scope-missing' => [
        'title' => 'Idempotency key not held',
        'detail' => 'The server cannot tell who sent this request, so it cannot hold its Idempotency-Key.',
    ],
];
