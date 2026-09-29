<?php

declare(strict_types=1);

// Assunto do aviso de inatividade de chave de API (pt-BR), montado pela classe
// de e-mail do pacote twstec/kit-accounts. O corpo do e-mail e as strings dele
// são do front. O aplicativo vence: a mesma chave no lang/ dele prevalece.

return [

    'api_key_inactivity' => [
        'subject' => ':platform — Sua chave de API será desativada por inatividade',
    ],

    'account_invitation' => [
        'subject' => ':platform — Convite para a conta :account',
    ],

    'orphaned_api_keys' => [
        'subject' => '{1} :platform — Uma chave de API da conta :account ficou sem quem a criou|[2,*] :platform — Chaves de API da conta :account ficaram sem quem as criou',
    ],

];
