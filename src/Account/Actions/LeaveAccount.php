<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\Account\Support\MemberRules;
use Twstec\Kit\Accounts\Account\Support\OrphanedApiKeys;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * A pessoa SAI da conta ATUAL — qualquer admin ou member. O DONO não sai:
 * transfere a propriedade antes (403 + `denied`).
 *
 * As chaves que ela criou continuam valendo e o dono e os admins recebem o
 * aviso de chave órfã. A seleção da conta na sessão é desfeita (a pessoa
 * volta para a conta pessoal).
 *
 * Trilha: `account.member_left`.
 */
final class LeaveAccount
{
    use GuardsAccountAction;

    public function __construct(
        private readonly AccountService $accounts,
        private readonly OrphanedApiKeys $orphans,
        private readonly AccountAudit $audit,
    ) {}

    public function handle(AuthUser $actor): void
    {
        $account = $this->currentAccount();
        $papel = $account->roleOf($actor);

        if ($papel === null) {
            $this->deny(AccountAuditEvent::MemberLeft, $account, $actor, __('accounts.authorization.not_member'), $account);
        }

        if (! MemberRules::canLeave($papel)) {
            $this->deny(AccountAuditEvent::MemberLeft, $account, $actor, __('accounts.members.owner_cannot_leave'), $account);
        }

        $chaves = $this->orphans->keysCreatedBy($actor);

        DB::transaction(function () use ($account, $actor, $papel, $chaves): void {
            $this->accounts->removeMember($account, $actor);

            $this->audit->record(AccountAuditEvent::MemberLeft, $account, $actor, $actor instanceof Model ? $actor : null, [
                'role' => ['before' => $papel->value, 'after' => null],
                'orphaned_api_keys' => ['before' => null, 'after' => count($chaves)],
            ], 'user');
        });

        $this->orphans->notify($account, $actor, $chaves, removed: false);

        Accounts::clearSelection();
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
