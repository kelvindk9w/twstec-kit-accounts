<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Exceptions;

use LogicException;

/**
 * Violação da regra do dono: exatamente um owner por conta, que não é
 * rebaixado nem sai enquanto a conta existir (a propriedade é transferida).
 */
final class AccountOwnershipException extends LogicException
{
    public static function secondOwner(): self
    {
        return new self(__('accounts.ownership.second_owner'));
    }

    public static function ownerRoleChange(): self
    {
        return new self(__('accounts.ownership.role_change'));
    }

    public static function ownerLeaves(): self
    {
        return new self(__('accounts.ownership.owner_leaves'));
    }

    public static function invalidTransfer(): self
    {
        return new self(__('accounts.ownership.invalid_transfer'));
    }

    public static function membershipMovesAccount(): self
    {
        return new self(__('accounts.ownership.moves_account'));
    }
}
