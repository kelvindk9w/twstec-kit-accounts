<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Invitations;

use Carbon\CarbonInterface;
use Twstec\Kit\Accounts\Account\Enums\InvitationStatus;
use Twstec\Kit\Accounts\Account\Exceptions\InvitationUnavailableException;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;

/**
 * O que a TELA do link de convite pode mostrar — calculado aqui, para todo
 * front mostrar a mesma coisa pela mesma regra.
 *
 * - `state`: `pending` ou o motivo de não dar (os de
 *   InvitationUnavailableException: not_found, expired, revoked, accepted,
 *   declined, wrong_email).
 * - `mode` (só com `pending`): `accept` (logado com o MESMO e-mail do
 *   convite), `login` (deslogado, o e-mail já tem conta — entrar e aceitar)
 *   ou `register` (deslogado, o e-mail não tem conta — o aceite cria a
 *   conta).
 * - Os dados do convite (conta, quem convidou, papel, e-mail, validade) só
 *   saem com o convite PENDENTE e para quem pode vê-los: nunca para quem está
 *   logado com OUTRO e-mail (`wrong_email` não diz de que conta é o convite)
 *   nem com o convite fora de validade.
 */
final readonly class InvitationPreview
{
    public const MODE_ACCEPT = 'accept';

    public const MODE_LOGIN = 'login';

    public const MODE_REGISTER = 'register';

    private function __construct(
        public string $state,
        public ?string $mode = null,
        public ?string $accountName = null,
        public ?string $inviterName = null,
        public ?string $roleLabel = null,
        public ?string $email = null,
        public ?CarbonInterface $expiresAt = null,
    ) {}

    public static function for(string $token, ?AuthUser $viewer): self
    {
        $invitation = InvitationTokens::find($token);

        if ($invitation === null) {
            return new self(InvitationUnavailableException::NOT_FOUND);
        }

        if ($viewer !== null && ! $invitation->isFor($viewer)) {
            return new self(InvitationUnavailableException::WRONG_EMAIL);
        }

        $status = $invitation->status();

        if ($status !== InvitationStatus::Pending) {
            return new self(InvitationUnavailableException::forStatus($status, $invitation)->reason);
        }

        if ($viewer !== null && $invitation->account?->hasMember($viewer)) {
            return new self(InvitationUnavailableException::ALREADY_MEMBER);
        }

        return new self(
            state: 'pending',
            mode: match (true) {
                $viewer !== null => self::MODE_ACCEPT,
                self::emailHasAccount($invitation) => self::MODE_LOGIN,
                default => self::MODE_REGISTER,
            },
            accountName: $invitation->account?->displayName(),
            inviterName: $invitation->creator?->getAttribute('name'),
            roleLabel: $invitation->role->label(),
            email: $invitation->email,
            expiresAt: $invitation->expires_at,
        );
    }

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }

    public static function emailHasAccount(AccountInvitation $invitation): bool
    {
        return UserModel::name()::query()->whereRaw('lower(email) = ?', [AccountInvitation::normalizeEmail($invitation->email)])->exists();
    }
}
