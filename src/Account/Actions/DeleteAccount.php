<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Services\SensitiveActionService;

/**
 * Exclui a conta ATUAL com tudo o que é dela (projetos, chaves, vínculos,
 * convites, membros).
 *
 * - Só o DONO (AccountAbility::DeleteAccount).
 * - Exige o TOKEN DE AÇÃO SENSÍVEL da pessoa (senha de transação + código
 *   por e-mail — o mesmo mecanismo da rotação de chave), consumido aqui: sem
 *   token válido, nada acontece (403 + `denied`).
 * - A conta PESSOAL não se exclui por aqui: ela sai junto com a pessoa.
 *
 * Trilha: `account.deleted` (na mesma transação da exclusão).
 */
final class DeleteAccount
{
    use GuardsAccountAction;

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
        $this->requireAbility(AccountAuditEvent::Deleted, $account, $actor, AccountAbility::DeleteAccount, $account);
    }

    public function handle(AuthUser $actor, #[\SensitiveParameter] string $sensitiveToken): void
    {
        $account = $this->currentAccount();
        $this->requireAbility(AccountAuditEvent::Deleted, $account, $actor, AccountAbility::DeleteAccount, $account);

        if ($account->isPersonal()) {
            $this->deny(AccountAuditEvent::Deleted, $account, $actor, __('accounts.account.personal_delete'), $account);
        }

        if (! $this->sensitive->validateToken($actor, $sensitiveToken)) {
            $this->deny(AccountAuditEvent::Deleted, $account, $actor, __('accounts.sensitive.required'), $account);
        }

        DB::transaction(function () use ($account, $actor): void {
            $this->audit->record(AccountAuditEvent::Deleted, $account, $actor, $account, [
                'name' => ['before' => $account->name, 'after' => null],
                'members' => ['before' => $account->memberships()->count(), 'after' => 0],
            ]);

            $this->accounts->deleteAccount($account);
        });

        Accounts::clearSelection();
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
