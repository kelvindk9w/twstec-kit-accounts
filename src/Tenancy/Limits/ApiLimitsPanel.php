<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Limits;

use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Accounts;

/**
 * O que a página da conta (painel React e Livewire) mostra dos limites da
 * API: um lugar só, para as duas telas não divergirem.
 *
 * Nulo com os limites desligados (a seção some). `canReduce` é a mesma
 * regra da Action ChangeApiLimits (só o dono), que recusa o que vier por
 * fora.
 */
final class ApiLimitsPanel
{
    /**
     * @return array{windowMinutes: int, canReduce: bool, limits: list<array{key: string, label: string, effective: int, platform: int, own: int|null}>}|null
     */
    public static function for(Account $account): ?array
    {
        if (! ApiLimits::enabled()) {
            return null;
        }

        $limits = app(ApiLimits::class);
        $effective = $limits->effective($account);
        $platform = $limits->platform($account);
        $own = $limits->ownReductions($account);

        return [
            'windowMinutes' => intdiv(ApiLimits::windowSeconds(), 60),
            'canReduce' => Accounts::can(AccountAbility::ReduceApiLimits),
            'limits' => array_map(static fn (ApiLimit $limit): array => [
                'key' => $limit->value,
                'label' => $limit->label(),
                'effective' => $effective->get($limit),
                'platform' => $platform->get($limit),
                'own' => $own[$limit->value],
            ], ApiLimit::cases()),
        ];
    }
}
