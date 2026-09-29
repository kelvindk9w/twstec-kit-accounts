<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Troca a conta atual da pessoa na sessão web (Accounts::switchTo) — só
 * para conta de que ela é membro.
 *
 * Conta inexistente e conta de que a pessoa não é membro recebem a MESMA
 * recusa (403): o uuid de uma conta alheia não confirma que ela existe. A
 * tentativa fica na trilha (`account.switched`, `denied`); a troca bem
 * feita não gera linha (é navegação, não mudança de dado).
 */
final class SwitchAccount
{
    use GuardsAccountAction;

    public function __construct(private readonly AccountAudit $audit) {}

    public function handle(AuthUser $actor, string $accountUuid): Account
    {
        $account = Account::query()->byUuid($accountUuid)->first();

        if ($account === null || ! $account->hasMember($actor)) {
            $this->deny(
                AccountAuditEvent::Switched,
                $account,
                $actor,
                __('accounts.authorization.not_member'),
                subjectType: 'account',
                subjectUuid: $account?->uuid,
            );
        }

        try {
            Accounts::switchTo($account);
        } catch (AuthorizationException $exception) {
            $this->deny(AccountAuditEvent::Switched, $account, $actor, $exception->getMessage(), $account);
        }

        return $account;
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
