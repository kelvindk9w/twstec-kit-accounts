<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\Tenancy\Limits\ApiLimit;
use Twstec\Kit\Accounts\Tenancy\Limits\ApiLimits;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * O DONO da conta ATUAL baixa os limites da API dela
 * (AccountAbility::ReduceApiLimits — só o dono).
 *
 * Cada limite (ApiLimit) recebe um inteiro entre 1 e o que a PLATAFORMA dá
 * a esta conta, ou nulo/vazio para tirar a redução (vale o da plataforma).
 * Acima da plataforma é recusado no campo — subir é só pelo /admin. Chave
 * ausente não mexe naquele limite.
 *
 * Trilha: `account.api_limits_changed` com o antes/depois da redução; a
 * recusa (papel, valor) como `denied`.
 */
final class ChangeApiLimits
{
    use GuardsAccountAction;

    public function __construct(
        private readonly AccountAudit $audit,
        private readonly ApiLimits $limits,
    ) {}

    /**
     * PRÉ-CHECAGEM do papel, para a tela esconder/recusar antes do envio.
     *
     * @throws AuthorizationException
     */
    public function authorize(AuthUser $actor): void
    {
        $account = $this->currentAccount();
        $this->requireAbility(AccountAuditEvent::ApiLimitsChanged, $account, $actor, AccountAbility::ReduceApiLimits, $account);
    }

    /**
     * @param  array<string, mixed>  $values  chaves de ApiLimit (`quota`, `burst_per_minute`, `concurrency`)
     *
     * @throws AuthorizationException|ValidationException
     */
    public function handle(AuthUser $actor, array $values): Account
    {
        $account = $this->currentAccount();
        $this->requireAbility(AccountAuditEvent::ApiLimitsChanged, $account, $actor, AccountAbility::ReduceApiLimits, $account);

        $platform = $this->limits->platform($account);
        $before = $this->limits->ownReductions($account);
        $after = [];

        foreach (ApiLimit::cases() as $limit) {
            if (! array_key_exists($limit->value, $values)) {
                continue;
            }

            try {
                $value = ApiLimits::normalize($values[$limit->value], $limit);
            } catch (InvalidArgumentException) {
                $this->reject(AccountAuditEvent::ApiLimitsChanged, $account, $actor, $limit->value, __('accounts.api_limits.invalid'), $account);
            }

            if ($value !== null && $value > $platform->get($limit)) {
                $this->reject(AccountAuditEvent::ApiLimitsChanged, $account, $actor, $limit->value, __('accounts.api_limits.above_platform', ['max' => $platform->get($limit)]), $account);
            }

            $after[$limit->value] = $value;
        }

        $changes = [];

        foreach ($after as $key => $value) {
            if ($before[$key] !== $value) {
                $changes[$key] = ['before' => $before[$key], 'after' => $value];
            }
        }

        if ($changes === []) {
            return $account;
        }

        return DB::transaction(function () use ($account, $actor, $after, $changes): Account {
            $this->limits->setOwn($account, $after);

            $this->audit->record(AccountAuditEvent::ApiLimitsChanged, $account, $actor, $account, $changes);

            return $account;
        });
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
