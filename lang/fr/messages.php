<?php

declare(strict_types=1);

return [
    'problems' => [
        'validation' => 'Certains champs de la requête ne sont pas valides.',
        'authentication' => 'La requête n\'est pas authentifiée.',
        'server' => 'La requête n\'a pas pu aboutir.',
    ],
    'idempotency-key-missing' => [
        'title' => 'Clé d\'idempotence manquante',
        'detail' => 'Cette requête modifie quelque chose et nécessite un en-tête Idempotency-Key.',
    ],
    'idempotency-key-malformed' => [
        'title' => 'Clé d\'idempotence invalide',
        'detail' => 'L\'en-tête Idempotency-Key est trop long ou contient des caractères autres que l\'ASCII imprimable.',
    ],
    'idempotency-key-in-flight' => [
        'title' => 'Requête toujours en cours',
        'detail' => 'Une requête avec cette Idempotency-Key est encore en cours de traitement. Elle pourra être renvoyée une fois celle-ci terminée.',
    ],
    'idempotency-answer-not-kept' => [
        'title' => 'Réponse non conservée',
        'detail' => 'La requête avec cette Idempotency-Key a été exécutée, mais sa réponse n\'a pas pu être conservée. Son résultat peut être consulté au lieu de renvoyer la requête.',
    ],
    'idempotency-key-reused' => [
        'title' => 'Clé d\'idempotence réutilisée',
        'detail' => 'Cette Idempotency-Key a déjà servi pour une autre requête.',
    ],
    'idempotency-scope-missing' => [
        'title' => 'Clé d\'idempotence non retenue',
        'detail' => 'Le serveur ne peut pas savoir qui a envoyé cette requête et ne peut donc pas retenir son Idempotency-Key.',
    ],
];
