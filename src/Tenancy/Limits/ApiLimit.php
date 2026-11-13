<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Limits;

/**
 * Os limites da API que cada conta tem — um por coluna na tabela `accounts`
 * (o valor da PLATAFORMA e a redução do DONO) e uma chave em
 * `api_keys.limits` (o padrão).
 */
enum ApiLimit: string
{
    /** Requisições por janela (`api_keys.limits.window_minutes`). */
    case Quota = 'quota';

    /** Requisições por minuto (rajada). */
    case BurstPerMinute = 'burst_per_minute';

    /** Requisições em andamento ao mesmo tempo. */
    case Concurrency = 'concurrency';

    /**
     * Maior valor aceito em qualquer nível (cabe na coluna `unsigned int`).
     */
    public const MAX = 2_000_000_000;

    /**
     * Coluna do valor que a plataforma dá à conta (nula = o padrão).
     */
    public function platformColumn(): string
    {
        return 'api_'.$this->value;
    }

    /**
     * Coluna da redução escolhida pelo dono (nula = sem redução).
     */
    public function ownColumn(): string
    {
        return 'api_'.$this->value.'_own';
    }

    /**
     * O padrão da plataforma (configuração), nunca menor que 1.
     */
    public function default(): int
    {
        $fallback = match ($this) {
            self::Quota => 25000,
            self::BurstPerMinute => 1000,
            self::Concurrency => 50,
        };

        $value = config('api_keys.limits.'.$this->value, $fallback);

        return min(self::MAX, max(1, is_numeric($value) ? (int) $value : $fallback));
    }

    /**
     * Rótulo traduzido (accounts.api_limits.*).
     */
    public function label(): string
    {
        return (string) __('accounts.api_limits.'.$this->value);
    }
}
