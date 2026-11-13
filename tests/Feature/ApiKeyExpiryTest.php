<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Twstec\Kit\Accounts\ApiKeys\Support\ApiKeyExpiry;

// =============================================================================
// VALIDADE ESCOLHIDA NA CRIAÇÃO (telas do painel): sem validade, N horas ou N
// dias — o cálculo e as regras num lugar só, com os tetos da configuração.
// =============================================================================

it('sem validade, horas ou dias, a partir de agora', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00.750', 'UTC'));

    expect(ApiKeyExpiry::resolve('none', null))->toBeNull()
        ->and(ApiKeyExpiry::resolve('hours', 6)?->toIso8601String())->toBe('2026-11-14T16:00:00+00:00')
        ->and(ApiKeyExpiry::resolve('days', '30')?->toIso8601String())->toBe('2026-12-14T10:00:00+00:00');
});

it('quantidade fora do teto da unidade, zero ou unidade desconhecida: recusa', function (string $unit, mixed $amount): void {
    config(['api_keys.expiry.max_hours' => 48, 'api_keys.expiry.max_days' => 90]);

    expect(fn () => ApiKeyExpiry::resolve($unit, $amount))->toThrow(InvalidArgumentException::class);
})->with([
    ['hours', 49],
    ['days', 91],
    ['hours', 0],
    ['days', -1],
    ['days', 'trinta'],
    ['minutes', 5],
]);

it('as regras de validação seguem o teto da unidade escolhida', function (array $input, bool $passes): void {
    config(['api_keys.expiry.max_hours' => 48, 'api_keys.expiry.max_days' => 90]);

    $validator = Validator::make($input, ApiKeyExpiry::rules($input['expiry_unit'] ?? null));

    expect($validator->passes())->toBe($passes);
})->with([
    'sem validade' => [['expiry_unit' => 'none'], true],
    '48 horas' => [['expiry_unit' => 'hours', 'expiry_amount' => 48], true],
    '49 horas' => [['expiry_unit' => 'hours', 'expiry_amount' => 49], false],
    '90 dias' => [['expiry_unit' => 'days', 'expiry_amount' => 90], true],
    '91 dias' => [['expiry_unit' => 'days', 'expiry_amount' => 91], false],
    'horas sem quantidade' => [['expiry_unit' => 'hours'], false],
    'unidade desconhecida' => [['expiry_unit' => 'weeks', 'expiry_amount' => 1], false],
    'sem unidade' => [[], false],
]);

it('a tela recebe as unidades e os tetos', function (): void {
    config(['api_keys.expiry.max_hours' => 48, 'api_keys.expiry.max_days' => 90]);

    expect(ApiKeyExpiry::options())->toBe(['units' => ['none', 'hours', 'days'], 'maxHours' => 48, 'maxDays' => 90]);
});
