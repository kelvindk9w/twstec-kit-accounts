<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A VALIDADE escolhida na criação da chave pelo painel: sem validade, N horas
 * ou N dias (a partir de agora). As telas dos dois starters usam as mesmas
 * regras e o mesmo cálculo daqui; a API v1 continua recebendo `expires_at`.
 *
 * Os tetos de cada unidade vêm de `api_keys.expiry` (API_KEYS_EXPIRY_MAX_HOURS,
 * API_KEYS_EXPIRY_MAX_DAYS). O sistema nunca impõe prazo: "sem validade" é
 * uma escolha, e é o padrão do formulário.
 */
final class ApiKeyExpiry
{
    public const NONE = 'none';

    public const HOURS = 'hours';

    public const DAYS = 'days';

    public const UNITS = [self::NONE, self::HOURS, self::DAYS];

    /**
     * Regras de validação dos dois campos. `$unit` é a unidade que chegou no
     * pedido: o teto da quantidade é o dela (horas ou dias).
     *
     * @return array<string, list<string>>
     */
    public static function rules(mixed $unit, string $unitField = 'expiry_unit', string $amountField = 'expiry_amount'): array
    {
        $amount = ['nullable'];

        if ($unit === self::HOURS || $unit === self::DAYS) {
            $amount = ['required', 'integer', 'min:1', 'max:'.self::maxFor($unit)];
        }

        return [
            $unitField => ['required', 'string', 'in:'.implode(',', self::UNITS)],
            $amountField => $amount,
        ];
    }

    /**
     * O teto da unidade (horas ou dias).
     */
    public static function maxFor(string $unit): int
    {
        return match ($unit) {
            self::HOURS => max(1, (int) config('api_keys.expiry.max_hours', 720)),
            self::DAYS => max(1, (int) config('api_keys.expiry.max_days', 3650)),
            default => throw new InvalidArgumentException("Unidade de validade desconhecida: {$unit}."),
        };
    }

    /**
     * O fim da validade escolhida (null = sem validade).
     *
     * @throws InvalidArgumentException Unidade desconhecida ou quantidade fora do teto.
     */
    public static function resolve(string $unit, int|string|null $amount): ?CarbonImmutable
    {
        if ($unit === self::NONE) {
            return null;
        }

        $amount = filter_var($amount, FILTER_VALIDATE_INT);

        if ($amount === false || $amount < 1 || $amount > self::maxFor($unit)) {
            throw new InvalidArgumentException('Quantidade de validade fora do permitido.');
        }

        $now = CarbonImmutable::now()->startOfSecond();

        return $unit === self::HOURS ? $now->addHours($amount) : $now->addDays($amount);
    }

    /**
     * O que a tela precisa para montar a escolha.
     *
     * @return array{units: list<string>, maxHours: int, maxDays: int}
     */
    public static function options(): array
    {
        return [
            'units' => self::UNITS,
            'maxHours' => self::maxFor(self::HOURS),
            'maxDays' => self::maxFor(self::DAYS),
        ];
    }
}
