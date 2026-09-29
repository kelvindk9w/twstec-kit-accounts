<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Mail;

use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Foundation\Mail\KitMailable;

/**
 * Aviso PRÉVIO de expiração por inatividade: enviado Y dias antes
 * da desativação automática (config api_keys.inactivity.warning_days).
 *
 * SEMPRE enfileirado (KitMailable). Contém apenas identificadores públicos
 * da chave (nome, código público, chave pública) — nunca a secreta.
 * Strings via __() (nada hardcoded, três idiomas): o assunto vem do lang/ do
 * pacote (mail.api_key_inactivity.subject); o corpo
 * (`mail.messages.api-key-inactivity-warning`) e as strings dele são do front.
 */
final class ApiKeyInactivityWarningMail extends KitMailable
{
    public function __construct(
        public readonly ApiKey $apiKey,
        public readonly int $expiresInDays,
    ) {}

    protected function subjectLine(): string
    {
        return __('mail.api_key_inactivity.subject', ['platform' => platform()->name]);
    }

    protected function messageView(): string
    {
        return 'mail.messages.api-key-inactivity-warning';
    }

    /**
     * @return array<string, mixed>
     */
    protected function messageData(): array
    {
        return [
            'keyName' => $this->apiKey->name,
            'keyPublicCode' => $this->apiKey->codigo_publico,
            'keyPublicKey' => $this->apiKey->public_key,
        ];
    }
}
