<?php

declare(strict_types=1);

// Asunto del aviso de inactividad de clave de API (es), construido por la clase
// de correo del paquete twstec/kit-accounts. El cuerpo del correo y sus textos
// son del front. La aplicación gana: la misma clave en su lang/ prevalece.

return [

    'api_key_inactivity' => [
        'subject' => ':platform — Tu clave de API se desactivará por inactividad',
    ],

    'account_invitation' => [
        'subject' => ':platform — Invitación a la cuenta :account',
    ],

    'orphaned_api_keys' => [
        'subject' => '{1} :platform — Una clave de API de la cuenta :account se quedó sin quien la creó|[2,*] :platform — Claves de API de la cuenta :account se quedaron sin quien las creó',
    ],

];
