<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Actions\Concerns\FindsAccountMember;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\Account\Support\MemberRules;
use Twstec\Kit\Accounts\Account\Support\OrphanedApiKeys;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * REMOVE um membro da conta ATUAL.
 *
 * Quem remove quem (Support\MemberRules): o dono remove qualquer admin ou
 * member; o admin só remove member; ninguém remove o dono; ninguém remove a
 * si mesmo (para isso, LeaveAccount). Recusa → 403 + `denied`.
 *
 * As chaves de API que a pessoa criou CONTINUAM VALENDO (são da conta) e o
 * dono e os admins recebem o AVISO DE CHAVE ÓRFÃ por e-mail (quais chaves,
 * sem segredo) — depois de a remoção ser gravada.
 *
 * Trilha: `account.member_removed` (papel que ela tinha e quantas chaves
 * ficaram órfãs).
 */
final class RemoveMember
{
    use FindsAccountMember, GuardsAccountAction;

    public function __construct(
        private readonly AccountService $accounts,
        private readonly OrphanedApiKeys $orphans,
        private readonly AccountAudit $audit,
    ) {}

    /**
     * PRÉ-CHECAGEM do papel, para a tela conferir ANTES de abrir a
     * confirmação ou gastar o código (quem não pode nem começa). Recusa →
     * 403 com a mesma mensagem de handle() e a linha `denied` na trilha,
     * como qualquer recusa desta Action. handle() confere de novo.
     *
     * @throws AuthorizationException
     */
    public function authorize(AuthUser $actor): void
    {
        $account = $this->currentAccount();
        $this->requireAbility(AccountAuditEvent::MemberRemoved, $account, $actor, AccountAbility::ManageMembers);
    }

    public function handle(AuthUser $actor, string $memberUuid): void
    {
        $account = $this->currentAccount();
        $actorRole = $this->requireAbility(AccountAuditEvent::MemberRemoved, $account, $actor, AccountAbility::ManageMembers);

        $member = $this->memberOf($account, $memberUuid);
        $papel = $account->roleOf($member);
        $self = (string) $member->getKey() === (string) $actor->getKey();

        if ($papel === null || ! MemberRules::canRemove($actorRole, $papel, $self)) {
            $this->deny(AccountAuditEvent::MemberRemoved, $account, $actor, __('accounts.members.cannot_remove'), $member);
        }

        $chaves = $this->orphans->keysCreatedBy($member);

        DB::transaction(function () use ($account, $actor, $member, $papel, $chaves): void {
            $this->accounts->removeMember($account, $member);

            $this->audit->record(AccountAuditEvent::MemberRemoved, $account, $actor, $member, [
                'role' => ['before' => $papel->value, 'after' => null],
                'orphaned_api_keys' => ['before' => null, 'after' => count($chaves)],
            ], 'user');
        });

        $this->orphans->notify($account, $member, $chaves, removed: true);
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
