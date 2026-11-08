<?php

declare(strict_types=1);

return [
    'problems' => [
        'validation' => 'Alguns campos do pedido não são válidos.',
        'authentication' => 'O pedido não está autenticado.',
        'server' => 'Não foi possível concluir o pedido.',
    ],
    'idempotency-key-missing' => [
        'title' => 'Chave de idempotência em falta',
        'detail' => 'Este pedido altera algo e precisa de um cabeçalho Idempotency-Key.',
    ],
    'idempotency-key-malformed' => [
        'title' => 'Chave de idempotência inválida',
        'detail' => 'O cabeçalho Idempotency-Key é demasiado longo ou contém caracteres que não são ASCII imprimível.',
    ],
    'idempotency-key-in-flight' => [
        'title' => 'Pedido ainda em curso',
        'detail' => 'Um pedido com esta Idempotency-Key ainda está a ser processado. Pode ser enviado novamente quando terminar.',
    ],
    'idempotency-answer-not-kept' => [
        'title' => 'Resposta não guardada',
        'detail' => 'O pedido com esta Idempotency-Key foi executado, mas a sua resposta não pôde ser guardada. O seu resultado pode ser consultado em vez de enviar o pedido novamente.',
    ],
    'idempotency-key-reused' => [
        'title' => 'Chave de idempotência reutilizada',
        'detail' => 'Esta Idempotency-Key já foi usada para outro pedido.',
    ],
    'idempotency-scope-missing' => [
        'title' => 'Chave de idempotência não mantida',
        'detail' => 'O servidor não consegue saber quem enviou este pedido, por isso não pode manter a sua Idempotency-Key.',
    ],
];
