<?php

declare(strict_types=1);

return [
    'problems' => [
        'validation' => 'Algunos campos de la solicitud no son válidos.',
        'authentication' => 'La solicitud no está autenticada.',
        'server' => 'No se pudo completar la solicitud.',
    ],
    'idempotency-key-missing' => [
        'title' => 'Falta la clave de idempotencia',
        'detail' => 'Esta solicitud modifica algo y necesita una cabecera Idempotency-Key.',
    ],
    'idempotency-key-malformed' => [
        'title' => 'Clave de idempotencia no válida',
        'detail' => 'La cabecera Idempotency-Key es demasiado larga o contiene caracteres que no son ASCII imprimible.',
    ],
    'idempotency-key-in-flight' => [
        'title' => 'Solicitud aún en curso',
        'detail' => 'Una solicitud con esta Idempotency-Key todavía se está procesando. Puede enviarse de nuevo cuando haya terminado.',
    ],
    'idempotency-answer-not-kept' => [
        'title' => 'Respuesta no conservada',
        'detail' => 'La solicitud con esta Idempotency-Key se ejecutó, pero su respuesta no pudo conservarse. Su resultado puede consultarse en lugar de enviar la solicitud de nuevo.',
    ],
    'idempotency-key-reused' => [
        'title' => 'Clave de idempotencia reutilizada',
        'detail' => 'Esta Idempotency-Key ya se usó para otra solicitud.',
    ],
    'idempotency-scope-missing' => [
        'title' => 'Clave de idempotencia no retenida',
        'detail' => 'El servidor no puede saber quién envió esta solicitud, por lo que no puede retener su Idempotency-Key.',
    ],
];
