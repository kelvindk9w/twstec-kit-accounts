<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Models\AccountMembership;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Cria uma conta de EMPRESA com a pessoa como dona.
 *
 * Qualquer pessoa logada pode, até o limite de contas de empresa de que é
 * dona (`accounts.limits.owned_accounts` — a pessoal não conta). O nome é
 * obrigatório (até 255 caracteres). Selecionar a conta nova é do front
 * (Accounts::switchTo), que sabe se quer ir para ela.
 *
 * Trilha: `account.created` (recusa pelo limite: `denied`).
 */
final class CreateAccount
{
    use GuardsAccountAction;

    public const NAME_MAX = 255;

    public function __construct(
        private readonly AccountService $accounts,
        private readonly AccountAudit $audit,
    ) {}

    public function handle(AuthUser $actor, string $name): Account
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            $this->reject(AccountAuditEvent::Created, null, $actor, 'name', __('accounts.account.name_invalid', ['max' => self::NAME_MAX]), subjectType: 'account');
        }

        $limite = (int) config('accounts.limits.owned_accounts', 10);

        $donas = AccountMembership::query()
            ->where('user_id', $actor->getKey())
            ->where('role', AccountRole::Owner->value)
            ->whereHas('account', fn ($query) => $query->whereNull('personal_user_id'))
            ->count();

        if ($donas >= $limite) {
            $this->reject(AccountAuditEvent::Created, null, $actor, 'name', __('accounts.account.limit_reached', ['max' => $limite]), subjectType: 'account');
        }

        return DB::transaction(function () use ($actor, $name): Account {
            $account = $this->accounts->createAccount($name, $actor);

            $this->audit->record(AccountAuditEvent::Created, $account, $actor, $account, [
                'name' => ['before' => null, 'after' => $name],
            ]);

            return $account;
        });
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
