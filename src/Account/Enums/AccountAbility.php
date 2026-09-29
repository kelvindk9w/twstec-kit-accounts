<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Enums;

/**
 * As ações sobre uma conta que dependem do PAPEL da pessoa nela (ver
 * AccountRole::allows()). Cada uma vira também uma habilidade do Gate do
 * Laravel com o prefixo `accounts.` (ex.: `@can('accounts.api-keys.manage')`),
 * sempre avaliada na conta atual.
 *
 * A chave de API não tem papel: ela é uma credencial da CONTA e é limitada
 * pelos escopos e pelo vínculo com projetos, não por esta matriz.
 */
enum AccountAbility: string
{
    /** Ver a conta: visão geral, projetos, chaves (sem a secreta). */
    case View = 'view';

    case CreateProjects = 'projects.create';

    case UpdateProjects = 'projects.update';

    case DeleteProjects = 'projects.delete';

    /** Criar, rotacionar, revogar chaves de API e definir o vínculo com projetos. */
    case ManageApiKeys = 'api-keys.manage';

    /** Convidar, remover e mudar o papel de membros (quem mexe em quem: Support\MemberRules). */
    case ManageMembers = 'members.manage';

    /** Renomear a conta (a conta pessoal não tem nome próprio). */
    case UpdateAccount = 'account.update';

    case TransferOwnership = 'account.transfer';

    case DeleteAccount = 'account.delete';

    /**
     * Nome da habilidade no Gate do Laravel.
     */
    public function gateName(): string
    {
        return 'accounts.'.$this->value;
    }
}
