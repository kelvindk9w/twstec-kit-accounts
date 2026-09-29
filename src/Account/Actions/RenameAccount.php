<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Renomeia a conta ATUAL — dono ou admin (AccountAbility::UpdateAccount).
 *
 * A conta PESSOAL não tem nome próprio (mostra o nome da pessoa, que é dado
 * pessoal cifrado e não é copiado para a conta): recusada.
 *
 * Trilha: `account.renamed` com o nome antes/depois.
 */
final class RenameAccount
{
    use GuardsAccountAction;

    public function __construct(private readonly AccountAudit $audit) {}

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
        $this->requireAbility(AccountAuditEvent::Renamed, $account, $actor, AccountAbility::UpdateAccount, $account);
    }

    public function handle(AuthUser $actor, string $name): Account
    {
        $account = $this->currentAccount();
        $this->requireAbility(AccountAuditEvent::Renamed, $account, $actor, AccountAbility::UpdateAccount, $account);

        if ($account->isPersonal()) {
            $this->deny(AccountAuditEvent::Renamed, $account, $actor, __('accounts.account.personal_rename'), $account);
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > CreateAccount::NAME_MAX) {
            $this->reject(AccountAuditEvent::Renamed, $account, $actor, 'name', __('accounts.account.name_invalid', ['max' => CreateAccount::NAME_MAX]), $account);
        }

        $antes = (string) $account->name;

        if ($antes === $name) {
            return $account;
        }

        return DB::transaction(function () use ($account, $actor, $antes, $name): Account {
            $account->name = $name;
            $account->save();

            $this->audit->record(AccountAuditEvent::Renamed, $account, $actor, $account, [
                'name' => ['before' => $antes, 'after' => $name],
            ]);

            return $account;
        });
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
