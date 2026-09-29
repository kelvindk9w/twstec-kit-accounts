<?php

declare(strict_types=1);

use Twstec\Kit\Accounts\Account\Mail\AccountInvitationMail;
use Twstec\Kit\Accounts\Account\Mail\OrphanedApiKeysMail;
use Twstec\Kit\Foundation\Mail\MailPreview;

// =============================================================================
// E-mails das contas com membros na galeria /mail-preview (ver
// Twstec\Kit\Foundation\Mail\MailPreview). Carregado via composer.json →
// autoload.files. Dados de exemplo — nada é salvo; o token é fictício.
// =============================================================================

MailPreview::register('account-invitation', static fn (): AccountInvitationMail => new AccountInvitationMail(
    accountName: 'Acme Pagamentos',
    inviterName: 'Maria Souza',
    roleLabel: __('accounts.roles.admin'),
    token: str_repeat('0', 64),
    expiresAt: now()->addDays(7),
));

MailPreview::register('orphaned-api-keys', static fn (): OrphanedApiKeysMail => new OrphanedApiKeysMail(
    accountName: 'Acme Pagamentos',
    accountUuid: '00000000-0000-7000-8000-000000000000',
    departedName: 'João Lima',
    removed: true,
    keys: [
        ['name' => 'Integração — faturamento', 'code' => 'AK-7F3D-9K2M', 'public_key' => 'pk_live_3f9a2c81b7d4e6520a1c8f37'],
        ['name' => 'Webhook do ERP', 'code' => 'AK-2B8C-4N1Q', 'public_key' => 'pk_live_8c1e04d97a2b35f6e0d4a918'],
    ],
));
