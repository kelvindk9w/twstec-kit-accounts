<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Actions\Concerns\FindsAccountMember;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\AccountMembership;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\Account\Support\MemberRules;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * MUDA O PAPEL de um membro da conta ATUAL entre member e admin.
 *
 * Quem mexe em quem (Support\MemberRules): o dono muda qualquer admin ou
 * member; o admin só promove member a admin; ninguém mexe no dono, ninguém
 * vira dono por aqui e ninguém muda o próprio papel. Recusa → 403 + `denied`.
 *
 * Trilha: `account.member_role_changed` com o papel antes/depois.
 */
final class ChangeMemberRole
{
    use FindsAccountMember, GuardsAccountAction;

    public function __construct(
        private readonly AccountService $accounts,
        private readonly AccountAudit $audit,
    ) {}

    public function handle(AuthUser $actor, string $memberUuid, AccountRole $role): AccountMembership
    {
        $account = $this->currentAccount();
        $actorRole = $this->requireAbility(AccountAuditEvent::MemberRoleChanged, $account, $actor, AccountAbility::ManageMembers);

        $member = $this->memberOf($account, $memberUuid);
        $antes = $account->roleOf($member);
        $self = (string) $member->getKey() === (string) $actor->getKey();

        if ($antes === null || ! MemberRules::canChangeRole($actorRole, $antes, $role, $self)) {
            $this->deny(AccountAuditEvent::MemberRoleChanged, $account, $actor, __('accounts.members.cannot_change_role'), $member);
        }

        return DB::transaction(function () use ($account, $actor, $member, $antes, $role): AccountMembership {
            $membership = $this->accounts->changeRole($account, $member, $role);

            $this->audit->record(AccountAuditEvent::MemberRoleChanged, $account, $actor, $member, [
                'role' => ['before' => $antes->value, 'after' => $role->value],
            ], 'user');

            return $membership;
        });
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
