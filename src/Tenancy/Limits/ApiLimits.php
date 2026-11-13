<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Limits;

use InvalidArgumentException;
use Twstec\Kit\Accounts\Account\Models\Account;

/**
 * Os LIMITES DA API de uma conta: quanto ela pode, em três níveis.
 *
 *   1. PADRÃO DA PLATAFORMA — `api_keys.limits` (config/.env);
 *   2. PLATAFORMA PARA ESTA CONTA — o /admin sobe ou baixa
 *      (`accounts.api_quota`…; nulo = o padrão). Fica na trilha do /admin;
 *   3. REDUÇÃO DO DONO — o dono da conta só baixa, nunca acima do nível 2
 *      (`accounts.api_quota_own`…, pela Action ChangeApiLimits, com trilha).
 *
 * O que vale (effective) é o MENOR entre o nível 2 e o 3: se o /admin baixar
 * a plataforma abaixo da redução do dono, vale a da plataforma.
 *
 * Quem conta e recusa é o middleware `api.limits`
 * (Middleware\EnforceAccountApiLimits), com o ApiLimitCounter.
 */
final class ApiLimits
{
    /**
     * Os limites por conta estão ligados (API_LIMITS_ENABLED)?
     */
    public static function enabled(): bool
    {
        return (bool) config('api_keys.limits.enabled', false);
    }

    /**
     * Duração da janela da cota, em segundos (mínimo de 1 minuto).
     */
    public static function windowSeconds(): int
    {
        $minutes = config('api_keys.limits.window_minutes', 720);

        return max(1, is_numeric($minutes) ? (int) $minutes : 720) * 60;
    }

    /**
     * O que a PLATAFORMA dá a esta conta: o valor do /admin ou o padrão.
     */
    public function platform(Account $account): ApiLimitValues
    {
        return $this->values(static fn (ApiLimit $limit): int => self::column($account, $limit->platformColumn()) ?? $limit->default());
    }

    /**
     * O que VALE para esta conta: o menor entre a plataforma e a redução do
     * dono.
     */
    public function effective(Account $account): ApiLimitValues
    {
        $platform = $this->platform($account);

        return $this->values(static function (ApiLimit $limit) use ($account, $platform): int {
            $own = self::column($account, $limit->ownColumn());

            return $own === null ? $platform->get($limit) : min($own, $platform->get($limit));
        });
    }

    /**
     * Os valores que o /admin gravou para a conta (nulo = segue o padrão).
     *
     * @return array<string, int|null>
     */
    public function platformOverrides(Account $account): array
    {
        return $this->raw($account, static fn (ApiLimit $limit): string => $limit->platformColumn());
    }

    /**
     * A redução escolhida pelo dono (nulo = sem redução).
     *
     * @return array<string, int|null>
     */
    public function ownReductions(Account $account): array
    {
        return $this->raw($account, static fn (ApiLimit $limit): string => $limit->ownColumn());
    }

    /**
     * O /admin muda o que a plataforma dá a esta conta (sobe ou baixa).
     * Chave ausente = não mexe; nulo = volta ao padrão. Chamado DENTRO da
     * ação do /admin: a captura central da trilha registra o antes/depois.
     *
     * @param  array<string, mixed>  $values  chaves de ApiLimit
     *
     * @throws InvalidArgumentException valor fora de 1..ApiLimit::MAX
     */
    public function setPlatform(Account $account, array $values): Account
    {
        foreach (ApiLimit::cases() as $limit) {
            if (array_key_exists($limit->value, $values)) {
                $account->setAttribute($limit->platformColumn(), self::normalize($values[$limit->value], $limit));
            }
        }

        $account->save();

        return $account;
    }

    /**
     * Grava a redução do dono. A REGRA (só o dono, nunca acima da
     * plataforma, trilha) é da Action ChangeApiLimits, que chama aqui.
     *
     * @param  array<string, int|null>  $values  chaves de ApiLimit
     */
    public function setOwn(Account $account, array $values): Account
    {
        foreach (ApiLimit::cases() as $limit) {
            if (array_key_exists($limit->value, $values)) {
                $account->setAttribute($limit->ownColumn(), self::normalize($values[$limit->value], $limit));
            }
        }

        $account->save();

        return $account;
    }

    /**
     * Vazio vira nulo; número inteiro entre 1 e ApiLimit::MAX vira int.
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(mixed $value, ApiLimit $limit): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }

        if (! is_int($value) || $value < 1 || $value > ApiLimit::MAX) {
            throw new InvalidArgumentException(sprintf('Limite da API `%s` inválido: precisa ser inteiro entre 1 e %d.', $limit->value, ApiLimit::MAX));
        }

        return $value;
    }

    /**
     * @param  callable(ApiLimit): int  $value
     */
    private function values(callable $value): ApiLimitValues
    {
        return new ApiLimitValues(
            quota: $value(ApiLimit::Quota),
            burstPerMinute: $value(ApiLimit::BurstPerMinute),
            concurrency: $value(ApiLimit::Concurrency),
        );
    }

    /**
     * @param  callable(ApiLimit): string  $column
     * @return array<string, int|null>
     */
    private function raw(Account $account, callable $column): array
    {
        $raw = [];

        foreach (ApiLimit::cases() as $limit) {
            $raw[$limit->value] = self::column($account, $column($limit));
        }

        return $raw;
    }

    private static function column(Account $account, string $column): ?int
    {
        $value = $account->getAttribute($column);

        return is_numeric($value) && (int) $value >= 1 ? (int) $value : null;
    }
}
