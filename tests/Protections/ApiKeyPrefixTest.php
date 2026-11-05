<?php

declare(strict_types=1);

use Twstec\Kit\Accounts\ApiKeys\Support\ApiKeyGenerator;

// =============================================================================
// PREFIXO DO PRODUTO NAS CHAVES DE API (API_KEY_PREFIX → api_keys.prefix).
//
//   - vazio (padrão): chaves como sempre, `pk_live_…`/`sk_live_…`;
//   - `acme`: chaves novas `acme_pk_live_…`/`acme_sk_live_…`, que autenticam;
//   - trocar o prefixo não invalida as chaves já emitidas (a autenticação
//     procura a chave pública inteira);
//   - prefixo fora do formato (maiúscula, hífen, longo demais) impede o boot,
//     em vez de gerar chaves fora do padrão.
// =============================================================================

it('sem prefixo, as chaves continuam no formato de sempre', function (): void {
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($this->owner());

    expect((string) $key->public_key)->toMatch('/^pk_live_[A-Za-z0-9]{32}$/')
        ->and($secret)->toMatch('/^sk_live_[A-Za-z0-9]{48}$/');
});

it('com prefixo, as chaves novas nascem com ele e autenticam', function (): void {
    $this->bootWith(['api_keys.prefix' => 'acme']);

    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($this->owner());

    expect((string) $key->public_key)->toMatch('/^acme_pk_live_[A-Za-z0-9]{32}$/')
        ->and($secret)->toMatch('/^acme_sk_live_[A-Za-z0-9]{48}$/');

    $this->getJson('/api/v1/projects', $this->credentials($key, $secret))->assertOk();
});

it('o prefixo vale junto com o ambiente das chaves', function (): void {
    config(['api_keys.prefix' => 'acme', 'api_keys.environment' => 'test']);

    $pair = app(ApiKeyGenerator::class)->generatePair();

    expect($pair['public_key'])->toStartWith('acme_pk_test_')
        ->and($pair['secret_key'])->toStartWith('acme_sk_test_');
});

it('trocar o prefixo não invalida as chaves já emitidas', function (): void {
    ['api_key' => $old, 'secret_key' => $oldSecret] = $this->keyFor($this->owner());

    config(['api_keys.prefix' => 'acme']);
    ['api_key' => $new, 'secret_key' => $newSecret] = $this->keyFor($this->owner());

    expect((string) $old->public_key)->toStartWith('pk_live_')
        ->and((string) $new->public_key)->toStartWith('acme_pk_live_');

    $this->getJson('/api/v1/projects', $this->credentials($old, $oldSecret))->assertOk();
    $this->getJson('/api/v1/projects', $this->credentials($new, $newSecret))->assertOk();
});

it('o maior prefixo aceito cabe na coluna da chave pública', function (): void {
    config(['api_keys.prefix' => str_repeat('a', ApiKeyGenerator::PREFIX_MAX_LENGTH), 'api_keys.environment' => 'live']);

    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($this->owner());

    expect(strlen((string) $key->public_key))->toBeLessThanOrEqual(64);

    $this->getJson('/api/v1/projects', $this->credentials($key, $secret))->assertOk();
});

it('prefixo fora do formato impede o boot, com a mensagem do que corrigir', function (string $prefix): void {
    expect(fn () => $this->bootWith(['api_keys.prefix' => $prefix]))
        ->toThrow(InvalidArgumentException::class, 'API_KEY_PREFIX inválido');
})->with([
    'maiúscula' => ['ACME'],
    'hífen' => ['a-b'],
    'sublinhado' => ['acme_'],
    'longo demais' => [str_repeat('a', ApiKeyGenerator::PREFIX_MAX_LENGTH + 1)],
]);
