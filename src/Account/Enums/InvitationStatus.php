<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Enums;

/**
 * Estado de um convite (calculado das colunas — ver
 * Models\AccountInvitation::status()).
 *
 * Só `Pending` aceita. Os demais são os estados de erro da tela de aceite
 * (o link chegou, mas não vale mais) — cada um com a sua mensagem, porque
 * "expirou" pede um reenvio e "revogado" não.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Declined = 'declined';
    case Expired = 'expired';

    public function label(): string
    {
        return __('accounts.invitations.status.'.$this->value);
    }
}
