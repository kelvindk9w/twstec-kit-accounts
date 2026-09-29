<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Enums;

/**
 * Papel FIXO de uma pessoa numa conta.
 *
 * - owner: o dono — exatamente um por conta (o banco garante). Faz tudo,
 *   inclusive o que só ele pode: transferir a propriedade e excluir a conta.
 * - admin: administra a conta — projetos, chaves de API, membros (com o
 *   limite de Support\MemberRules: não mexe no dono nem em outro admin) e o
 *   nome da conta.
 * - member: trabalha na conta — vê tudo e cria/edita projetos; não gere
 *   chaves de API, não exclui projeto e não mexe em membros.
 *
 * A matriz completa está em allows() e em docs/tenancy.md. Papéis
 * customizáveis não existem nesta versão.
 */
enum AccountRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /**
     * O papel permite a ação?
     */
    public function allows(AccountAbility $ability): bool
    {
        return match ($ability) {
            AccountAbility::View,
            AccountAbility::CreateProjects,
            AccountAbility::UpdateProjects => true,
            AccountAbility::DeleteProjects,
            AccountAbility::ManageApiKeys,
            AccountAbility::ManageMembers,
            AccountAbility::UpdateAccount => $this !== self::Member,
            AccountAbility::TransferOwnership,
            AccountAbility::DeleteAccount => $this === self::Owner,
        };
    }

    /**
     * Rótulo traduzido (accounts.roles.*).
     */
    public function label(): string
    {
        return __('accounts.roles.'.$this->value);
    }
}
