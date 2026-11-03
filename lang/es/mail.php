<?php

declare(strict_types=1);

// Correos de cuentas y claves de API (es): los asuntos, construidos por las
// clases de correo del paquete twstec/kit-accounts, y los textos del cuerpo
// (resources/views/mail/messages del paquete). La aplicación gana: la misma
// clave en su lang/ prevalece (ver docs/personalizacao.md).

return [

    'api_key_inactivity' => [
        'subject' => ':platform — Tu clave de API se desactivará por inactividad',

        // Aviso previo de expiración de clave de API por inactividad.
        'preheader' => 'Una clave sin uso se desactivará en :days días.',
        'heading' => 'Una de tus claves de API está por desactivarse',
        'intro' => 'La clave de abajo no se está usando y se desactivará automáticamente por inactividad.',
        'name_label' => 'Nombre de la clave',
        'code_label' => 'Código público',
        'key_label' => 'Clave pública',
        'expires' => 'La desactivación ocurre en :days días.',
        'action' => 'Para mantenerla activa, basta con hacer una solicitud autenticada con ella. Si ya no la necesitas, te recomendamos revocarla en el panel.',
        'cta' => 'Abrir mis claves',
        'ignore' => 'Si no reconoces esta clave, revócala de inmediato y cambia tus credenciales.',
    ],

    'account_invitation' => [
        'subject' => ':platform — Invitación a la cuenta :account',

        // Invitación a una cuenta (twstec/kit-accounts). El asunto es del paquete.
        'preheader' => ':inviter lo invitó a la cuenta :account.',
        'heading' => 'Lo invitaron a la cuenta :account',
        'intro' => ':inviter lo invitó a trabajar en la cuenta :account en :platform.',
        'account_label' => 'Cuenta',
        'role_label' => 'Rol',
        'expires_label' => 'Válida hasta',
        'action' => 'Abrir la invitación',
        'how' => 'Si todavía no tiene acceso a la plataforma, créelo en la propia pantalla de la invitación. Si ya lo tiene, entre y acepte.',
        'fallback' => 'Si el botón no funciona, copie y pegue esta dirección en el navegador:',
        'ignore' => 'Si no esperaba esta invitación, ignore este correo: sin la aceptación, no pasa nada.',
    ],

    'orphaned_api_keys' => [
        'subject' => '{1} :platform — Una clave de API de la cuenta :account se quedó sin quien la creó|[2,*] :platform — Claves de API de la cuenta :account se quedaron sin quien las creó',

        // Aviso de clave huérfana (twstec/kit-accounts). El asunto es del paquete.
        'preheader' => '{1} :count clave de API de la cuenta :account sigue funcionando.|[2,*] :count claves de API de la cuenta :account siguen funcionando.',
        'heading' => '{1} Una clave de API se quedó sin quien la creó|[2,*] Claves de API se quedaron sin quien las creó',
        'intro_removed' => '{1} :name fue quitado de la cuenta :account y había creado la clave de abajo.|[2,*] :name fue quitado de la cuenta :account y había creado las claves de abajo.',
        'intro_deleted' => '{1} El acceso de :name a la plataforma fue eliminado, y esa persona había creado la clave de abajo en la cuenta :account.|[2,*] El acceso de :name a la plataforma fue eliminado, y esa persona había creado las claves de abajo en la cuenta :account.',
        'intro_left' => '{1} :name salió de la cuenta :account y había creado la clave de abajo.|[2,*] :name salió de la cuenta :account y había creado las claves de abajo.',
        'name_label' => 'Nombre de la clave',
        'code_label' => 'Código público',
        'key_label' => 'Clave pública',
        'still_valid' => '{1} La clave es de la cuenta, no de la persona: sigue funcionando. Si quien la usaba ya no debe tener acceso, rótela o revóquela.|[2,*] Las claves son de la cuenta, no de la persona: siguen funcionando. Si quien las usaba ya no debe tener acceso, rótelas o revóquelas.',
        'cta' => 'Revisar las claves de la cuenta',
        'why' => 'Recibe este aviso porque es propietario o administrador de la cuenta.',
    ],

];
