<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Mail;

use Carbon\CarbonInterface;
use Twstec\Kit\Foundation\Mail\KitMailable;

/**
 * O CONVITE para entrar numa conta — o link de aceite, com o token em claro
 * (a única vez que ele existe fora da memória; no banco, só o hash).
 *
 * SEMPRE enfileirado e com o payload do job CRIPTOGRAFADO (KitMailable): o
 * token não fica legível no Redis, no Horizon nem em `failed_jobs`.
 *
 * O mesmo e-mail para quem já tem conta na plataforma e para quem não tem:
 * a tela do link decide (entrar ou criar a conta). Quem convida não fica
 * sabendo se o e-mail já existe.
 *
 * O assunto vem do lang/ do pacote (mail.account_invitation.subject); o
 * corpo (`mail.messages.account-invitation`) e as strings dele são do front —
 * a view monta o link com a rota de aceite do aplicativo.
 */
final class AccountInvitationMail extends KitMailable
{
    public function __construct(
        public readonly string $accountName,
        public readonly string $inviterName,
        public readonly string $roleLabel,
        #[\SensitiveParameter] public readonly string $token,
        public readonly CarbonInterface $expiresAt,
    ) {}

    protected function subjectLine(): string
    {
        return __('mail.account_invitation.subject', [
            'platform' => platform()->name,
            'account' => $this->accountName,
        ]);
    }

    protected function messageView(): string
    {
        return 'mail.messages.account-invitation';
    }
}
