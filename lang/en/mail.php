<?php

declare(strict_types=1);

// Account and API key e-mails (en): the subjects, built by the
// twstec/kit-accounts mail classes, and the body texts
// (resources/views/mail/messages of the package). The application wins: the
// same key in its lang/ takes precedence (see docs/personalizacao.md).

return [

    'api_key_inactivity' => [
        'subject' => ':platform — Your API key will be deactivated due to inactivity',

        // Advance warning of API key expiration by inactivity.
        'preheader' => 'An unused key will be deactivated in :days days.',
        'heading' => 'One of your API keys is about to be deactivated',
        'intro' => 'The key below has not been used and will be automatically deactivated due to inactivity.',
        'name_label' => 'Key name',
        'code_label' => 'Public code',
        'key_label' => 'Public key',
        'expires' => 'The deactivation happens in :days days.',
        'action' => 'To keep it active, simply make an authenticated request with it. If you no longer need it, we recommend revoking it in the dashboard.',
        'cta' => 'Open my keys',
        'ignore' => 'If you do not recognize this key, revoke it immediately and rotate your credentials.',
    ],

    'account_invitation' => [
        'subject' => ':platform — Invitation to the :account account',

        // Invitation to an account (twstec/kit-accounts). The subject is the package's.
        'preheader' => ':inviter invited you to the :account account.',
        'heading' => 'You were invited to the :account account',
        'intro' => ':inviter invited you to work in the :account account on :platform.',
        'account_label' => 'Account',
        'role_label' => 'Role',
        'expires_label' => 'Valid until',
        'action' => 'Open the invitation',
        'how' => 'If you do not have access to the platform yet, create yours on the invitation page. If you do, sign in and accept.',
        'fallback' => 'If the button does not work, copy and paste this address into your browser:',
        'ignore' => 'If you were not expecting this invitation, ignore this e-mail: without acceptance, nothing happens.',
    ],

    'orphaned_api_keys' => [
        'subject' => '{1} :platform — An API key of the :account account lost its creator|[2,*] :platform — API keys of the :account account lost their creator',

        // Orphaned API key notice (twstec/kit-accounts). The subject is the package's.
        'preheader' => '{1} :count API key of the :account account keeps working.|[2,*] :count API keys of the :account account keep working.',
        'heading' => '{1} An API key lost the person who created it|[2,*] API keys lost the person who created them',
        'intro_removed' => '{1} :name was removed from the :account account and had created the key below.|[2,*] :name was removed from the :account account and had created the keys below.',
        'intro_deleted' => '{1} :name\'s access to the platform was deleted, and this person had created the key below in the :account account.|[2,*] :name\'s access to the platform was deleted, and this person had created the keys below in the :account account.',
        'intro_left' => '{1} :name left the :account account and had created the key below.|[2,*] :name left the :account account and had created the keys below.',
        'name_label' => 'Key name',
        'code_label' => 'Public code',
        'key_label' => 'Public key',
        'still_valid' => '{1} The key belongs to the account, not to the person: it keeps working. If whoever used it should no longer have access, rotate or revoke it.|[2,*] The keys belong to the account, not to the person: they keep working. If whoever used them should no longer have access, rotate or revoke them.',
        'cta' => 'Review the account\'s keys',
        'why' => 'You get this notice because you are an owner or administrator of the account.',
    ],

];
