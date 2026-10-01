<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Deletion\DeletionImpediments;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionImpededException;
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
 * - IMPEDIMENTO declarado (Deletion\DeletionImpediments — um registro que a
 *   lei manda guardar, por exemplo): recusa limpa, ANTES de pedir o código e
 *   de novo antes de apagar, com a mensagem no campo `account` (erro de
 *   validação) e a linha `denied` na trilha. Um registro do aplicativo que
 *   aponta para a conta sem ter sido declarado (chave estrangeira RESTRICT)
 *   vira a mesma recusa, com a transação desfeita — nunca o erro bruto do
 *   banco.
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

        $this->ensureNoImpediment($account, $actor);
    }

    public function handle(AuthUser $actor, #[\SensitiveParameter] string $sensitiveToken): void
    {
        $account = $this->currentAccount();
        $this->requireAbility(AccountAuditEvent::Deleted, $account, $actor, AccountAbility::DeleteAccount, $account);

        if ($account->isPersonal()) {
            $this->deny(AccountAuditEvent::Deleted, $account, $actor, __('accounts.account.personal_delete'), $account);
        }

        $this->ensureNoImpediment($account, $actor);

        if (! $this->sensitive->validateToken($actor, $sensitiveToken)) {
            $this->deny(AccountAuditEvent::Deleted, $account, $actor, __('accounts.sensitive.required'), $account);
        }

        try {
            DeletionImpediments::guardIntegrity(function () use ($account, $actor): void {
                DB::transaction(function () use ($account, $actor): void {
                    $this->audit->record(AccountAuditEvent::Deleted, $account, $actor, $account, [
                        'name' => ['before' => $account->name, 'after' => null],
                        'members' => ['before' => $account->memberships()->count(), 'after' => 0],
                    ]);

                    $this->accounts->deleteAccount($account);
                });
            });
        } catch (DeletionImpededException $exception) {
            // Desfeita a transação (nada saiu), a recusa fica na trilha.
            $this->reject(AccountAuditEvent::Deleted, $account, $actor, 'account', $exception->getMessage(), $account);
        }

        Accounts::clearSelection();
    }

    /**
     * Impedimento declarado → erro no campo `account` + `denied` na trilha.
     */
    private function ensureNoImpediment(Account $account, AuthUser $actor): void
    {
        $motivo = $this->accounts->accountDeletionDenial($account);

        if ($motivo !== null) {
            $this->reject(AccountAuditEvent::Deleted, $account, $actor, 'account', $motivo, $account);
        }
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
