<?php

declare(strict_types=1);

use Twstec\Kit\Accounts\ApiKeys\Mail\ApiKeyInactivityWarningMail;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Foundation\Mail\MailPreview;

// =============================================================================
// E-mails do módulo de chaves de API na galeria /mail-preview (ver
// Twstec\Kit\Foundation\Mail\MailPreview). Carregado via composer.json → autoload.files.
//
// A chave de exemplo NÃO é salva: é pré-visualização, não seed.
// =============================================================================

MailPreview::register('api-key-inactivity', static function (): ApiKeyInactivityWarningMail {
    $key = new ApiKey;
    $key->name = 'Integração — faturamento';
    $key->codigo_publico = 'AK-7F3D-9K2M';
    $key->public_key = 'pk_live_3f9a2c81b7d4e6520a1c8f37';

    return new ApiKeyInactivityWarningMail($key, 7);
});
