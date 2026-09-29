<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Support;

use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;

/**
 * A regra de QUEM MEXE EM QUEM entre os membros de uma conta — num lugar só,
 * para a Action recusar e a tela esconder o botão pela MESMA regra.
 *
 * | Quem age | Convidar | Mudar papel (member ↔ admin) | Remover | Sair |
 * | --- | --- | --- | --- | --- |
 * | owner | admin ou member | de qualquer admin/member | qualquer admin/member | não (transfere antes) |
 * | admin | admin ou member | só de member (promove a admin) | só member | sim |
 * | member | — | — | — | sim |
 *
 * - Ninguém mexe no DONO (nem papel, nem remoção): a propriedade só muda por
 *   transferência, que é do próprio dono.
 * - Admin não mexe em OUTRO admin: rebaixar ou remover um admin é decisão do
 *   dono. (Um admin pode promover um member — depois disso, só o dono mexe
 *   nele.)
 * - Ninguém muda o PRÓPRIO papel nem se remove: para sair, "sair da conta".
 * - Ninguém vira dono por convite nem por mudança de papel.
 */
final class MemberRules
{
    public static function canInvite(?AccountRole $actor, AccountRole $role): bool
    {
        return $actor !== null
            && $actor->allows(AccountAbility::ManageMembers)
            && $role !== AccountRole::Owner;
    }

    public static function canChangeRole(?AccountRole $actor, AccountRole $target, AccountRole $new, bool $self): bool
    {
        if ($actor === null || $self || ! $actor->allows(AccountAbility::ManageMembers)) {
            return false;
        }

        if ($target === AccountRole::Owner || $new === AccountRole::Owner || $target === $new) {
            return false;
        }

        return $actor === AccountRole::Owner || $target === AccountRole::Member;
    }

    public static function canRemove(?AccountRole $actor, AccountRole $target, bool $self): bool
    {
        if ($actor === null || $self || ! $actor->allows(AccountAbility::ManageMembers) || $target === AccountRole::Owner) {
            return false;
        }

        return $actor === AccountRole::Owner || $target === AccountRole::Member;
    }

    public static function canLeave(?AccountRole $role): bool
    {
        return $role !== null && $role !== AccountRole::Owner;
    }
}
