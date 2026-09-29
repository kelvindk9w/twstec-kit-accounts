<?php

declare(strict_types=1);

return [
    'personal_account' => 'Personal account',

    'roles' => [
        'owner' => 'Owner',
        'admin' => 'Administrator',
        'member' => 'Member',
    ],

    'context' => [
        'missing' => ':model queried without a current account. Account data is only read with an account in context (panel session or API key) or in explicit system mode: Accounts::asSystem(\'reason\', fn () => ...) or Accounts::actingAs($account, fn () => ...).',
        'system_write_without_account' => 'In system mode, :model must be saved with an explicit account (account_id).',
        'cross_account_write' => 'Saving :model into an account other than the current one was refused.',
        'account_change' => 'The account of :model cannot change once saved.',
    ],

    'ownership' => [
        'second_owner' => 'The account already has an owner. Ownership changes by transfer.',
        'role_change' => 'The owner role is neither granted nor removed here. Ownership changes by transfer.',
        'owner_leaves' => 'The owner cannot leave the account. Transfer ownership first.',
        'moves_account' => 'A membership never moves to another account.',
        'invalid_transfer' => 'A transfer must go from the current owner to another member of the account.',
    ],

    'members' => [
        'already_member' => 'This person is already a member of the account.',
        'cannot_change_role' => 'Your role does not allow changing this person\'s role. The owner is not changed here, and only the owner can change another administrator.',
        'cannot_remove' => 'Your role does not allow removing this person. The owner cannot be removed, and only the owner can remove an administrator.',
        'owner_cannot_leave' => 'The owner cannot leave the account. Transfer ownership first.',
    ],

    'deletion' => [
        'owner_has_members' => '{1} This person owns an account that has other members (:accounts). Transfer ownership before deleting them.|[2,*] This person owns accounts that have other members (:accounts). Transfer ownership before deleting them.',
    ],

    'authorization' => [
        'denied' => 'Your role in this account does not allow this action.',
        'not_member' => 'You are not a member of this account.',
        // Motivo gravado na trilha (`denied`) quando a tela procura, na conta
        // atual, um recurso que não está nela — a resposta continua o 404 comum.
        'not_found' => 'Resource not found in the current account (nonexistent or from another account).',
    ],

    'account' => [
        'name_invalid' => 'Enter a name of up to :max characters.',
        'limit_reached' => 'You have reached the limit of :max company accounts as owner.',
        'personal_rename' => 'The personal account uses your name — it is not renamed here.',
        'personal_delete' => 'The personal account is not deleted here: it goes away together with your login account.',
    ],

    'sensitive' => [
        'required' => 'This action needs confirmation with your transaction password and the code sent by e-mail.',
    ],

    'transfer' => [
        'personal' => 'The personal account cannot be transferred: it belongs to the person.',
        'self' => 'Choose another member of the account to receive ownership.',
    ],

    'switch' => [
        'switched' => 'You are now in the :account account.',
    ],

    'invitations' => [
        'status' => [
            'pending' => 'Pending',
            'accepted' => 'Accepted',
            'revoked' => 'Revoked',
            'declined' => 'Declined',
            'expired' => 'Expired',
        ],
        'unavailable' => [
            'not_found' => 'This invitation does not exist or the link is incomplete. Ask the person who invited you for a new invitation.',
            'expired' => 'This invitation has expired. Ask the person who invited you to resend it.',
            'revoked' => 'This invitation was cancelled by the person who sent it.',
            'accepted' => 'This invitation has already been used.',
            'declined' => 'This invitation was declined.',
            'wrong_email' => 'This invitation was sent to a different e-mail. Sign out and sign in with the account for that e-mail to accept it.',
            'already_member' => 'You are already a member of this account.',
            'has_account' => 'This e-mail already has an account. Sign in with it to accept the invitation.',
        ],
        'owner_role' => 'Invitations are for administrators or members. Ownership only changes by transfer.',
        'email_invalid' => 'Enter a valid e-mail.',
        'already_member' => 'This person is already a member of the account.',
        'already_pending' => 'There is already a pending invitation for this e-mail. Resend it instead of creating another one.',
        'too_many_pending' => 'The account has reached the limit of :max pending invitations. Revoke one or wait for them to be accepted.',
        'throttled' => 'Too many invitations in a short time. Try again in :minutes min.',
        'not_resendable' => 'Only a pending or expired invitation can be resent.',
        'not_revocable' => 'Only a pending or expired invitation can be revoked.',
        'accepted' => 'Invitation accepted. Welcome to the :account account.',
        'declined' => 'Invitation declined.',
        'fields' => [
            'name' => 'name',
            'password' => 'password',
        ],
    ],
];
