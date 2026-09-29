<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * O que toda Action de conta faz antes de mudar qualquer coisa: achar a
 * conta ATUAL, conferir o papel de quem age e, na recusa, GRAVAR a
 * tentativa na trilha (`denied`) antes de a exceção sair — não existe
 * recusar sem registrar.
 *
 * - Recusa de PAPEL ou de regra de quem mexe em quem → AuthorizationException
 *   (403 na web e na API).
 * - Recusa de DADO (e-mail inválido, já é membro, limite, intervalo) →
 *   ValidationException (erro no campo).
 */
trait GuardsAccountAction
{
    abstract protected function audit(): AccountAudit;

    protected function currentAccount(): Account
    {
        return Accounts::currentOrFail();
    }

    /**
     * O papel de quem age na conta atual, se ele permite a ação — senão
     * registra a recusa e lança 403.
     *
     * @throws AuthorizationException
     */
    protected function requireAbility(AccountAuditEvent $event, Account $account, AuthUser $actor, AccountAbility $ability, ?Model $subject = null): AccountRole
    {
        $role = $account->roleOf($actor);

        if ($role === null) {
            $this->deny($event, $account, $actor, __('accounts.authorization.not_member'), $subject);
        }

        if (! $role->allows($ability)) {
            $this->deny($event, $account, $actor, __('accounts.authorization.denied'), $subject);
        }

        return $role;
    }

    /**
     * Registra a recusa e lança 403.
     *
     * @throws AuthorizationException
     */
    protected function deny(AccountAuditEvent $event, Account|string|null $account, ?AuthUser $actor, string $reason, ?Model $subject = null, ?string $subjectType = null, ?string $subjectUuid = null): never
    {
        $this->audit()->denied($event, $account, $actor, $reason, $subject, $subjectType, $subjectUuid);

        throw new AuthorizationException($reason);
    }

    /**
     * Registra a recusa e lança erro de validação no campo.
     *
     * @throws ValidationException
     */
    protected function reject(AccountAuditEvent $event, Account|string|null $account, ?AuthUser $actor, string $field, string $reason, ?Model $subject = null, ?string $subjectType = null, ?string $subjectUuid = null): never
    {
        $this->audit()->denied($event, $account, $actor, $reason, $subject, $subjectType, $subjectUuid);

        throw ValidationException::withMessages([$field => $reason]);
    }
}
