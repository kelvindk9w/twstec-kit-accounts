<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Accounts\Tenancy\Support\TenantIdempotencyScope;
use Twstec\Kit\Accounts\Tests\Fixtures\User;
use Twstec\Kit\Foundation\Idempotency\Contracts\IdempotencyScopeResolver;
use Twstec\Kit\Foundation\Idempotency\IdempotencyStore;

// =============================================================================
// IDEMPOTENCY-KEY NA API v1 DO PACOTE, numa aplicação LIMPA.
//
// O escopo da chave é conta + chave de API (TenantIdempotencyScope). A criação
// de projeto repete a resposta original; a criação de chave de API — que
// exibe a secreta uma única vez — NÃO guarda a resposta: a repetição diz "já
// processada" sem a secreta, e a secreta nunca vai para a tabela.
// =============================================================================

const ACCOUNTS_IDEM_KEY = '0d9c8b7a-6f5e-4d3c-9b2a-1f0e9d8c7b6a';

/**
 * Token de ação sensível válido para a pessoa (só o hash no banco), como a
 * confirmação sensível emite.
 */
function accountsSensitiveToken(User $user): string
{
    $token = 'token-idempotencia-'.bin2hex(random_bytes(16));

    DB::table('sensitive_action_tokens')->insert([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $token;
}

function accountsProjectCount(): int
{
    return Accounts::asSystem('teste', fn (): int => Project::query()->count());
}

it('dois POST /projects com a mesma chave: um projeto só e a mesma resposta, marcada como replay', function (): void {
    $owner = $this->owner();
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner);
    $headers = [...$this->credentials($key, $secret), 'Idempotency-Key' => ACCOUNTS_IDEM_KEY];

    $first = $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $headers);
    $second = $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $headers);

    $first->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->getContent())->toBe($first->getContent())
        ->and(accountsProjectCount())->toBe(1);
});

it('mesma chave com outro corpo: recusa no envelope da API, sem criar', function (): void {
    $owner = $this->owner();
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner);
    $headers = [...$this->credentials($key, $secret), 'Idempotency-Key' => ACCOUNTS_IDEM_KEY];

    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $headers)->assertCreated();

    $this->postJson('/api/v1/projects', ['name' => 'Faturas'], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_reused')
        ->assertJsonPath('error.message', __('api.errors.idempotency_key_reused'))
        ->assertJsonStructure(['error' => ['code', 'message', 'correlation_id']]);

    expect(accountsProjectCount())->toBe(1);
});

it('a mesma chave em duas CONTAS cria dois projetos independentes', function (): void {
    $ana = $this->owner();
    $bia = $this->owner();
    ['api_key' => $keyA, 'secret_key' => $secretA] = $this->keyFor($ana);
    ['api_key' => $keyB, 'secret_key' => $secretB] = $this->keyFor($bia);

    $a = $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], [...$this->credentials($keyA, $secretA), 'Idempotency-Key' => ACCOUNTS_IDEM_KEY]);
    $b = $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], [...$this->credentials($keyB, $secretB), 'Idempotency-Key' => ACCOUNTS_IDEM_KEY]);

    $a->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    $b->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect($a->json('data.uuid'))->not->toBe($b->json('data.uuid'))
        ->and(accountsProjectCount())->toBe(2)
        ->and(Accounts::actingAs($this->accountOf($bia), fn (): array => Project::query()->pluck('uuid')->all(), $bia))->toBe([$b->json('data.uuid')]);
});

it('duas CHAVES DE API da mesma conta com a mesma Idempotency-Key não colidem', function (): void {
    $owner = $this->owner();
    ['api_key' => $one, 'secret_key' => $secretOne] = $this->keyFor($owner, ['name' => 'Integração 1']);
    ['api_key' => $two, 'secret_key' => $secretTwo] = $this->keyFor($owner, ['name' => 'Integração 2']);

    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], [...$this->credentials($one, $secretOne), 'Idempotency-Key' => ACCOUNTS_IDEM_KEY])->assertCreated();
    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], [...$this->credentials($two, $secretTwo), 'Idempotency-Key' => ACCOUNTS_IDEM_KEY])
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(accountsProjectCount())->toBe(2);
});

it('criar chave de API (secreta exibida uma vez): a repetição não cria outra, não reexibe a secreta e a secreta nunca vai à tabela', function (): void {
    $owner = $this->owner();
    ['api_key' => $boot, 'secret_key' => $bootSecret] = $this->keyFor($owner);
    $headers = [...$this->credentials($boot, $bootSecret), 'Idempotency-Key' => ACCOUNTS_IDEM_KEY];

    $first = $this->postJson('/api/v1/api-keys', ['name' => 'Faturamento'], [...$headers, 'X-Sensitive-Action-Token' => accountsSensitiveToken($owner)]);
    $first->assertCreated();
    $secret = (string) $first->json('secret_key');
    expect($secret)->not->toBe('');

    // Repetição com um token novo, e sem token nenhum: ambas são replay — o
    // `idempotent` vem antes do `sensitive.token` e nada executa de novo.
    $withToken = $this->postJson('/api/v1/api-keys', ['name' => 'Faturamento'], [...$headers, 'X-Sensitive-Action-Token' => accountsSensitiveToken($owner)]);
    $withoutToken = $this->postJson('/api/v1/api-keys', ['name' => 'Faturamento'], $headers);

    foreach ([$withToken, $withoutToken] as $replay) {
        $replay->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.uuid', $first->json('data.uuid'))
            ->assertJsonPath('data.public_key', $first->json('data.public_key'))
            ->assertJsonPath('idempotency.body_withheld', true)
            ->assertJsonMissingPath('secret_key')
            ->assertJsonMissingPath('data.scopes');

        expect($replay->getContent())->not->toContain($secret);
    }

    $row = DB::table(IdempotencyStore::TABLE)->sole();

    expect(Accounts::asSystem('teste', fn (): int => ApiKey::query()->count()))->toBe(2)
        ->and((bool) $row->response_withheld)->toBeTrue()
        ->and(json_encode($row))->not->toContain($secret)
        ->and(Crypt::decryptString((string) $row->response))->not->toContain($secret)
        // O token de ação sensível da repetição não foi consumido.
        ->and(DB::table('sensitive_action_tokens')->whereNull('consumed_at')->count())->toBe(1);
});

it('rotacionar chave: a repetição não rotaciona de novo e não reexibe a secreta nova', function (): void {
    $owner = $this->owner();
    ['api_key' => $boot, 'secret_key' => $bootSecret] = $this->keyFor($owner);
    ['api_key' => $target] = $this->keyFor($owner, ['name' => 'Alvo']);
    $headers = [...$this->credentials($boot, $bootSecret), 'Idempotency-Key' => ACCOUNTS_IDEM_KEY];

    $first = $this->postJson("/api/v1/api-keys/{$target->uuid}/rotate", ['grace_period_minutes' => 60], [...$headers, 'X-Sensitive-Action-Token' => accountsSensitiveToken($owner)]);
    $first->assertCreated();
    $secret = (string) $first->json('secret_key');

    $replay = $this->postJson("/api/v1/api-keys/{$target->uuid}/rotate", ['grace_period_minutes' => 60], [...$headers, 'X-Sensitive-Action-Token' => accountsSensitiveToken($owner)]);

    $replay->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.uuid', $first->json('data.uuid'))
        ->assertJsonMissingPath('secret_key');

    expect($replay->getContent())->not->toContain($secret)
        ->and(Crypt::decryptString((string) DB::table(IdempotencyStore::TABLE)->value('response')))->not->toContain($secret)
        ->and(Accounts::asSystem('teste', fn (): int => ApiKey::query()->count()))->toBe(3);
});

it('sem a Idempotency-Key, a API funciona como sempre (a chave é opcional nas rotas do pacote)', function (): void {
    $owner = $this->owner();
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner);

    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $this->credentials($key, $secret))->assertCreated();
    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $this->credentials($key, $secret))->assertCreated();

    expect(accountsProjectCount())->toBe(2)
        ->and(DB::table(IdempotencyStore::TABLE)->count())->toBe(0);
});

it('credencial inválida para antes da idempotência: 401 e nenhuma chave guardada', function (): void {
    $owner = $this->owner();
    ['api_key' => $key] = $this->keyFor($owner);

    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], [...$this->credentials($key, 'sk_invalida'), 'Idempotency-Key' => ACCOUNTS_IDEM_KEY])
        ->assertUnauthorized();

    expect(DB::table(IdempotencyStore::TABLE)->count())->toBe(0);
});

it('o escopo é conta + chave na API e conta + pessoa na sessão; sem conta, nenhum (falha fechada)', function (): void {
    $owner = $this->owner();
    $scope = app(IdempotencyScopeResolver::class);

    expect($scope)->toBeInstanceOf(TenantIdempotencyScope::class)
        ->and($scope->resolve(Request::create('/')))->toBeNull();

    $request = Request::create('/');
    $request->setUserResolver(fn () => $owner);
    $account = $this->accountOf($owner);

    expect(Accounts::actingAs($account, fn (): ?string => $scope->resolve($request), $owner))
        ->toBe('account:'.$account->uuid.'|user:'.$owner->id)
        ->and(Accounts::asSystem('teste', fn (): ?string => $scope->resolve($request)))->toBeNull();
});
