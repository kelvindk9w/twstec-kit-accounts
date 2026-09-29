<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Exceptions;

use RuntimeException;
use Twstec\Kit\Accounts\Account\Enums\InvitationStatus;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;

/**
 * O convite do link não pode ser usado AGORA — com o motivo, para a tela
 * dizer o que fazer (pedir um reenvio, entrar com a conta certa, entrar em
 * vez de cadastrar...).
 *
 * `wrong_email` NUNCA leva dado da conta para a tela: quem está logado com
 * outro e-mail não fica sabendo de que conta é o convite.
 */
final class InvitationUnavailableException extends RuntimeException
{
    public const NOT_FOUND = 'not_found';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const WRONG_EMAIL = 'wrong_email';

    public const ALREADY_MEMBER = 'already_member';

    public const HAS_ACCOUNT = 'has_account';

    public function __construct(
        public readonly string $reason,
        public readonly ?AccountInvitation $invitation = null,
    ) {
        parent::__construct(__('accounts.invitations.unavailable.'.$reason));
    }

    public static function forStatus(InvitationStatus $status, AccountInvitation $invitation): self
    {
        return new self(match ($status) {
            InvitationStatus::Expired => self::EXPIRED,
            InvitationStatus::Revoked => self::REVOKED,
            InvitationStatus::Accepted => self::ACCEPTED,
            InvitationStatus::Declined => self::DECLINED,
            InvitationStatus::Pending => self::NOT_FOUND,
        }, $invitation);
    }
}
