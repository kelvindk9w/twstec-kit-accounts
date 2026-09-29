<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Actions\Concerns\FindsAccountMember;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Services\SensitiveActionService;

/**
 * TRANSFERE A PROPRIEDADE da conta ATUAL para um membro dela.
 *
 * - Só o DONO (AccountAbility::TransferOwnership).
 * - Exige o TOKEN DE AÇÃO SENSÍVEL de quem transfere — emitido só depois da
 *   SENHA DE TRANSAÇÃO e do CÓDIGO por e-mail (SensitiveActionService, o
 *   mesmo mecanismo da rotação de chave) — consumido aqui, uso único. Sem
 *   token válido: nada muda (403 + `denied`).
 * - O novo dono tem de ser MEMBRO da conta (admin ou member) e não pode ser
 *   quem transfere; outra pessoa = 404.
 * - A conta PESSOAL não se transfere (ela é da pessoa).
 * - O antigo dono vira ADMIN. A troca é uma transação só
 *   (AccountService::transferOwnership): nunca zero nem dois donos.
 *
 * Trilha: `account.ownership_transferred` (dono antes/depois).
 */
final class TransferOwnership
{
    use FindsAccountMember, GuardsAccountAction;

    public function __construct(
        private readonly AccountService $accounts,
        private readonly SensitiveActionService $sensitive,
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
        $this->requireAbility(AccountAuditEvent::OwnershipTransferred, $account, $actor, AccountAbility::TransferOwnership, $account);
    }

    public function handle(AuthUser $actor, string $newOwnerUuid, #[\SensitiveParameter] string $sensitiveToken): Account
    {
        $account = $this->currentAccount();
        $this->requireAbility(AccountAuditEvent::OwnershipTransferred, $account, $actor, AccountAbility::TransferOwnership, $account);

        if ($account->isPersonal()) {
            $this->deny(AccountAuditEvent::OwnershipTransferred, $account, $actor, __('accounts.transfer.personal'), $account);
        }

        $novo = $this->memberOf($account, $newOwnerUuid);

        if ((string) $novo->getKey() === (string) $actor->getKey()) {
            $this->deny(AccountAuditEvent::OwnershipTransferred, $account, $actor, __('accounts.transfer.self'), $account);
        }

        if (! $this->sensitive->validateToken($actor, $sensitiveToken)) {
            $this->deny(AccountAuditEvent::OwnershipTransferred, $account, $actor, __('accounts.sensitive.required'), $account);
        }

        DB::transaction(function () use ($account, $actor, $novo): void {
            $papelAntes = $account->roleOf($novo);

            $this->accounts->transferOwnership($account, $actor, $novo);

            $this->audit->record(AccountAuditEvent::OwnershipTransferred, $account, $actor, $account, [
                'owner' => ['before' => (string) $actor->getAttribute('uuid'), 'after' => (string) $novo->getAttribute('uuid')],
                'new_owner_previous_role' => ['before' => $papelAntes?->value, 'after' => 'owner'],
                'previous_owner_role' => ['before' => 'owner', 'after' => 'admin'],
            ]);
        });

        return $account->refresh();
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
