<?php

declare(strict_types=1);

return [
    'personal_account' => 'Cuenta personal',

    'roles' => [
        'owner' => 'Propietario',
        'admin' => 'Administrador',
        'member' => 'Miembro',
    ],

    'context' => [
        'missing' => 'Consulta de :model sin cuenta actual. Los datos de cuenta solo se leen con una cuenta definida (sesión del panel o clave de API) o en modo sistema explícito: Accounts::asSystem(\'motivo\', fn () => ...) o Accounts::actingAs($cuenta, fn () => ...).',
        'system_write_without_account' => 'En modo sistema, :model debe guardarse con la cuenta informada (account_id).',
        'cross_account_write' => 'Se rechazó guardar :model en una cuenta que no es la actual.',
        'account_change' => 'La cuenta de :model no cambia después de guardada.',
    ],

    'ownership' => [
        'second_owner' => 'La cuenta ya tiene un propietario. La propiedad cambia por transferencia.',
        'role_change' => 'El rol de propietario no se concede ni se retira aquí. La propiedad cambia por transferencia.',
        'owner_leaves' => 'El propietario no sale de la cuenta. Transfiera la propiedad antes.',
        'moves_account' => 'Un vínculo de miembro no cambia de cuenta.',
        'invalid_transfer' => 'La transferencia debe ir del propietario actual a otro miembro de la cuenta.',
    ],

    'members' => [
        'already_member' => 'Esta persona ya es miembro de la cuenta.',
        'cannot_change_role' => 'Su rol no permite cambiar el rol de esta persona. El propietario no se cambia aquí, y solo el propietario cambia a otro administrador.',
        'cannot_remove' => 'Su rol no permite quitar a esta persona. El propietario no se quita, y solo el propietario quita a un administrador.',
        'owner_cannot_leave' => 'El propietario no sale de la cuenta. Transfiera la propiedad antes.',
    ],

    'deletion' => [
        'owner_has_members' => '{1} Esta persona es propietaria de una cuenta con otros miembros (:accounts). Transfiera la propiedad antes de eliminarla.|[2,*] Esta persona es propietaria de cuentas con otros miembros (:accounts). Transfiera la propiedad antes de eliminarla.',
    ],

    'authorization' => [
        'denied' => 'Su rol en esta cuenta no permite esta acción.',
        'not_member' => 'Usted no es miembro de esta cuenta.',
        // Motivo gravado na trilha (`denied`) quando a tela procura, na conta
        // atual, um recurso que não está nela — a resposta continua o 404 comum.
        'not_found' => 'Recurso no encontrado en la cuenta actual (inexistente o de otra cuenta).',
    ],

    'account' => [
        'name_invalid' => 'Indique un nombre de hasta :max caracteres.',
        'limit_reached' => 'Llegó al límite de :max cuentas de empresa como propietario.',
        'personal_rename' => 'La cuenta personal usa su nombre — no se renombra aquí.',
        'personal_delete' => 'La cuenta personal no se elimina aquí: sale junto con su cuenta de acceso.',
    ],

    'sensitive' => [
        'required' => 'Esta acción necesita la confirmación con la contraseña de transacción y el código enviado por correo.',
    ],

    'transfer' => [
        'personal' => 'La cuenta personal no se transfiere: es de la persona.',
        'self' => 'Elija otro miembro de la cuenta para recibir la propiedad.',
    ],

    'switch' => [
        'switched' => 'Ahora está en la cuenta :account.',
    ],

    'invitations' => [
        'status' => [
            'pending' => 'Pendiente',
            'accepted' => 'Aceptada',
            'revoked' => 'Revocada',
            'declined' => 'Rechazada',
            'expired' => 'Vencida',
        ],
        'unavailable' => [
            'not_found' => 'Esta invitación no existe o el enlace está incompleto. Pida una nueva invitación a quien lo invitó.',
            'expired' => 'Esta invitación venció. Pida a quien lo invitó que la reenvíe.',
            'revoked' => 'Esta invitación fue cancelada por quien la envió.',
            'accepted' => 'Esta invitación ya fue usada.',
            'declined' => 'Esta invitación fue rechazada.',
            'wrong_email' => 'Esta invitación se envió a otro correo. Cierre sesión y entre con la cuenta de ese correo para aceptarla.',
            'already_member' => 'Usted ya es miembro de esta cuenta.',
            'has_account' => 'Este correo ya tiene una cuenta. Entre con ella para aceptar la invitación.',
        ],
        'owner_role' => 'La invitación es para administrador o miembro. La propiedad solo cambia por transferencia.',
        'email_invalid' => 'Indique un correo válido.',
        'already_member' => 'Esta persona ya es miembro de la cuenta.',
        'already_pending' => 'Ya existe una invitación pendiente para este correo. Reenvíela en lugar de crear otra.',
        'too_many_pending' => 'La cuenta llegó al límite de :max invitaciones pendientes. Revoque alguna o espere a que sean aceptadas.',
        'throttled' => 'Demasiadas invitaciones en poco tiempo. Intente de nuevo en :minutes min.',
        'not_resendable' => 'Solo una invitación pendiente o vencida puede reenviarse.',
        'not_revocable' => 'Solo una invitación pendiente o vencida puede revocarse.',
        'accepted' => 'Invitación aceptada. Bienvenido a la cuenta :account.',
        'declined' => 'Invitación rechazada.',
        'fields' => [
            'name' => 'nombre',
            'password' => 'contraseña',
        ],
    ],
];
