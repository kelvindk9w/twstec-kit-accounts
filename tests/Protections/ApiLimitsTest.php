<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Twstec\Kit\Accounts\Account\Actions\ChangeApiLimits;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Limits\ApiLimitCounter;
use Twstec\Kit\Accounts\Tenancy\Limits\ApiLimits;

// =============================================================================
// LIMITES DA API POR CONTA (api_keys.limits): cota por janela, rajada por
// minuto, concorrência, limite próprio de rota — contados pela CONTA (todas
// as chaves somam), com os cabeçalhos RateLimit-* e os códigos próprios no
// envelope de erro. Valores por conta: plataforma (/admin) e redução do
// dono, que nunca passa da plataforma.
// =============================================================================

/**
 * Liga os limites com os valores do teste.
 *
 * @param  array<string, int>  $values
 */
function enableApiLimits(array $values = []): void
{
    config()->set('api_keys.limits', [
        ...config('api_keys.limits'),
        'enabled' => true,
        ...$values,
    ]);
}

/**
 * Cabeçalhos do par de credenciais devolvido por keyFor().
 *
 * @param  array{api_key: ApiKey, secret_key: string}  $pair
 * @return array<string, string>
 */
function apiLimitsHeaders(array $pair): array
{
    return [
        'X-Api-Key' => (string) $pair['api_key']->public_key,
        'Authorization' => 'Bearer '.$pair['secret_key'],
        'Accept' => 'application/json',
    ];
}

beforeEach(function (): void {
    Cache::store()->flush();
});

it('desligado por padrão: nenhum cabeçalho RateLimit-* e nenhuma recusa da conta', function (): void {
    config()->set('api_keys.limits.quota', 1);
    $headers = apiLimitsHeaders($this->keyFor($this->owner()));

    foreach (range(1, 3) as $_) {
        $this->getJson('/api/v1/projects', $headers)
            ->assertOk()
            ->assertHeaderMissing('RateLimit-Limit');
    }
});

it('ligado: toda resposta autenticada leva a cota (limite, quanto resta, segundos até recomeçar)', function (): void {
    enableApiLimits(['quota' => 10, 'window_minutes' => 720]);
    $headers = apiLimitsHeaders($this->keyFor($this->owner()));

    $first = $this->getJson('/api/v1/projects', $headers)->assertOk();
    $second = $this->getJson('/api/v1/projects', $headers)->assertOk();

    expect($first->headers->get('RateLimit-Limit'))->toBe('10')
        ->and($first->headers->get('RateLimit-Remaining'))->toBe('9')
        ->and($second->headers->get('RateLimit-Remaining'))->toBe('8')
        ->and((int) $second->headers->get('RateLimit-Reset'))->toBeGreaterThan(0)->toBeLessThanOrEqual(720 * 60);

    // Erro da própria rota também leva a cota (e conta nela).
    $this->getJson('/api/v1/projects/'.fake()->uuid(), $headers)
        ->assertNotFound()
        ->assertHeader('RateLimit-Remaining', '7');
});

it('a cota é da CONTA: as chaves dela somam, outra conta não é afetada; passou → 429 api_quota_exceeded com Retry-After', function (): void {
    enableApiLimits(['quota' => 2]);
    $ana = $this->owner();
    $one = apiLimitsHeaders($this->keyFor($ana));
    $two = apiLimitsHeaders($this->keyFor($ana));
    $bia = apiLimitsHeaders($this->keyFor($this->owner()));

    $this->getJson('/api/v1/projects', $one)->assertOk();
    $this->getJson('/api/v1/projects', $two)->assertOk();

    $refused = $this->getJson('/api/v1/projects', $one)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'api_quota_exceeded')
        ->assertJsonPath('error.message', __('api.errors.api_quota_exceeded'))
        ->assertHeader('RateLimit-Limit', '2')
        ->assertHeader('RateLimit-Remaining', '0');

    expect((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(60)
        ->and($refused->headers->get('Retry-After'))->toBe($refused->headers->get('RateLimit-Reset'));

    $this->getJson('/api/v1/projects', $bia)->assertOk()->assertHeader('RateLimit-Remaining', '1');
});

it('a janela começa na PRIMEIRA requisição e não se renova a cada uma; ao fim dela a cota recomeça', function (): void {
    enableApiLimits(['quota' => 2, 'window_minutes' => 60]);
    $headers = apiLimitsHeaders($this->keyFor($this->owner()));

    $this->getJson('/api/v1/projects', $headers)->assertOk();
    $this->travel(50)->minutes();
    $this->getJson('/api/v1/projects', $headers)->assertOk()->assertHeader('RateLimit-Reset', (string) (10 * 60));
    $this->getJson('/api/v1/projects', $headers)->assertStatus(429);

    $this->travel(11)->minutes();

    $this->getJson('/api/v1/projects', $headers)
        ->assertOk()
        ->assertHeader('RateLimit-Remaining', '1')
        ->assertHeader('RateLimit-Reset', (string) (60 * 60));
});

it('o replay de uma Idempotency-Key conta na cota como qualquer requisição', function (): void {
    enableApiLimits(['quota' => 2]);
    $headers = [...apiLimitsHeaders($this->keyFor($this->owner())), 'Idempotency-Key' => '6a0b8f0e-3c1d-4b8e-9f2a-7d6c5b4a3f21'];

    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $headers)->assertCreated();
    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $headers)
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertHeader('RateLimit-Remaining', '0');

    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $headers)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'api_quota_exceeded');
});

it('conta DEPOIS da autenticação qualquer que seja a ordem declarada na rota (lista de prioridade)', function (): void {
    enableApiLimits(['quota' => 5]);
    // Pilha montada à mão, com o limite declarado ANTES da autenticação.
    Route::middleware(['api.limits', 'resolve.tenant'])->get('api/v1/limits-order', static fn () => response()->json(['data' => 'ok']));
    $headers = apiLimitsHeaders($this->keyFor($this->owner()));

    $this->getJson('/api/v1/limits-order', $headers)
        ->assertOk()
        ->assertHeader('RateLimit-Limit', '5')
        ->assertHeader('RateLimit-Remaining', '4');
});

it('rajada: passou do teto por minuto → 429 api_burst_exceeded; no minuto seguinte volta', function (): void {
    enableApiLimits(['quota' => 100, 'burst_per_minute' => 2]);
    $headers = apiLimitsHeaders($this->keyFor($this->owner()));

    $this->getJson('/api/v1/projects', $headers)->assertOk();
    $this->getJson('/api/v1/projects', $headers)->assertOk();

    $refused = $this->getJson('/api/v1/projects', $headers)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'api_burst_exceeded')
        ->assertHeader('RateLimit-Limit', '100');

    expect((int) $refused->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);

    $this->travel(61)->seconds();
    $this->getJson('/api/v1/projects', $headers)->assertOk();
});

it('concorrência: sem vaga livre → 429 api_concurrency_exceeded; vaga devolvida → passa', function (): void {
    enableApiLimits(['concurrency' => 2, 'concurrency_retry_after_seconds' => 3]);
    $owner = $this->owner();
    $headers = apiLimitsHeaders($this->keyFor($owner));
    $uuid = (string) $this->accountOf($owner)->uuid;

    // Duas requisições da conta "em andamento" (vagas ocupadas por outro worker).
    $busy = [
        Cache::lock(ApiLimitCounter::slotKey($uuid, 0), 300),
        Cache::lock(ApiLimitCounter::slotKey($uuid, 1), 300),
    ];
    expect($busy[0]->get())->toBeTrue()->and($busy[1]->get())->toBeTrue();

    $this->getJson('/api/v1/projects', $headers)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'api_concurrency_exceeded')
        ->assertHeader('Retry-After', '3')
        ->assertHeader('RateLimit-Limit', (string) config('api_keys.limits.quota'));

    $busy[1]->release();

    $this->getJson('/api/v1/projects', $headers)->assertOk();
    $this->getJson('/api/v1/projects', $headers)->assertOk();
});

it('a vaga de concorrência volta também quando a requisição FALHA', function (): void {
    enableApiLimits(['concurrency' => 1]);
    Route::middleware(['api', 'resolve.tenant'])->get('api/v1/limits-boom', static fn () => throw new RuntimeException('falha no meio'));
    $headers = apiLimitsHeaders($this->keyFor($this->owner()));

    $this->getJson('/api/v1/limits-boom', $headers)->assertStatus(500)->assertJsonPath('error.code', 'server_error');
    $this->getJson('/api/v1/limits-boom', $headers)->assertStatus(500)->assertJsonPath('error.code', 'server_error');
    $this->getJson('/api/v1/projects', $headers)->assertOk();
});

it('limite próprio de rota (api.route-limit), por conta: passou → 429 api_route_limit_exceeded; outra conta e outra rota seguem', function (): void {
    Route::middleware(['api', 'resolve.tenant', 'api.route-limit:2,60'])->get('api/v1/limits-route', static fn () => response()->json(['data' => 'ok']));
    $ana = apiLimitsHeaders($this->keyFor($this->owner()));
    $bia = apiLimitsHeaders($this->keyFor($this->owner()));

    $this->getJson('/api/v1/limits-route', $ana)->assertOk();
    $this->getJson('/api/v1/limits-route', $ana)->assertOk();

    $refused = $this->getJson('/api/v1/limits-route', $ana)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'api_route_limit_exceeded')
        ->assertJsonPath('error.message', __('api.errors.api_route_limit_exceeded'));
    expect((int) $refused->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);

    $this->getJson('/api/v1/limits-route', $bia)->assertOk();
    $this->getJson('/api/v1/projects', $ana)->assertOk();
});

it('store dos contadores fora do ar: a requisição NÃO roda — 503 api_limits_unavailable, sem detalhe do erro', function (): void {
    enableApiLimits();
    $headers = apiLimitsHeaders($this->keyFor($this->owner()));

    $this->app->instance(ApiLimitCounter::class, new ApiLimitCounter(new class(Cache::store()) extends RateLimiter
    {
        public function hit($key, $decaySeconds = 60)
        {
            throw new RuntimeException('redis: connection refused 10.0.0.9');
        }
    }));

    $response = $this->postJson('/api/v1/projects', ['name' => 'Não criado'], [...$headers, 'Idempotency-Key' => 'b5c1e2f3-0a4d-4e8b-9c7f-1a2b3c4d5e6f'])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'api_limits_unavailable')
        ->assertJsonPath('error.message', __('api.errors.api_limits_unavailable'));

    expect($response->getContent())->not->toContain('10.0.0.9')
        ->and(Accounts::asSystem('teste', fn (): int => DB::table('projects')->count()))->toBe(0);
});

it('vale o MENOR entre a plataforma (padrão ou /admin) e a redução do dono', function (): void {
    enableApiLimits(['quota' => 1000, 'burst_per_minute' => 100, 'concurrency' => 10]);
    $account = $this->accountOf($this->owner());
    $limits = app(ApiLimits::class);

    expect($limits->effective($account)->toArray())->toBe(['quota' => 1000, 'burst_per_minute' => 100, 'concurrency' => 10]);

    $limits->setPlatform($account, ['quota' => 5000, 'concurrency' => 4]);
    $limits->setOwn($account->refresh(), ['quota' => 300, 'burst_per_minute' => 50]);

    expect($limits->effective($account->refresh())->toArray())->toBe(['quota' => 300, 'burst_per_minute' => 50, 'concurrency' => 4]);

    // A plataforma baixou abaixo da redução do dono: vale a plataforma.
    $limits->setPlatform($account, ['quota' => 200]);
    expect($limits->effective($account->refresh())->quota)->toBe(200);

    // Nulo volta ao padrão.
    $limits->setPlatform($account, ['quota' => null, 'concurrency' => null]);
    expect($limits->platform($account->refresh())->toArray())->toBe(['quota' => 1000, 'burst_per_minute' => 100, 'concurrency' => 10]);

    expect(fn () => $limits->setPlatform($account, ['quota' => 0]))->toThrow(InvalidArgumentException::class);
});

it('o valor por conta vale na requisição (cota do /admin para esta conta)', function (): void {
    enableApiLimits(['quota' => 1000]);
    $owner = $this->owner();
    $headers = apiLimitsHeaders($this->keyFor($owner));
    app(ApiLimits::class)->setPlatform($this->accountOf($owner), ['quota' => 1]);

    $this->getJson('/api/v1/projects', $headers)->assertOk()->assertHeader('RateLimit-Limit', '1');
    $this->getJson('/api/v1/projects', $headers)->assertStatus(429)->assertJsonPath('error.code', 'api_quota_exceeded');
});

it('o dono BAIXA os limites (com trilha); acima da plataforma é recusado (com trilha), e nulo tira a redução', function (): void {
    enableApiLimits(['quota' => 1000, 'burst_per_minute' => 100, 'concurrency' => 10]);
    $owner = $this->owner();
    $account = $this->accountOf($owner);
    $change = app(ChangeApiLimits::class);

    Accounts::actingAs($account, fn () => $change->handle($owner, ['quota' => '400', 'concurrency' => 3]), $owner);

    expect(app(ApiLimits::class)->effective($account->refresh())->toArray())->toBe(['quota' => 400, 'burst_per_minute' => 100, 'concurrency' => 3]);

    $line = DB::table('audit_events')->where('action', 'account.api_limits_changed')->where('outcome', 'success')->sole();
    expect($line->tenant_uuid)->toBe((string) $account->uuid)
        ->and(json_decode((string) $line->changes, true))->toMatchArray([
            'quota' => ['before' => null, 'after' => 400],
            'concurrency' => ['before' => null, 'after' => 3],
        ]);

    expect(fn () => Accounts::actingAs($account, fn () => $change->handle($owner, ['burst_per_minute' => 101]), $owner))
        ->toThrow(ValidationException::class, __('accounts.api_limits.above_platform', ['max' => 100]));
    expect(DB::table('audit_events')->where('action', 'account.api_limits_changed')->where('outcome', 'denied')->count())->toBe(1)
        ->and(app(ApiLimits::class)->ownReductions($account->refresh())['burst_per_minute'])->toBeNull();

    expect(fn () => Accounts::actingAs($account, fn () => $change->handle($owner, ['quota' => 0]), $owner))
        ->toThrow(ValidationException::class);

    Accounts::actingAs($account, fn () => $change->handle($owner, ['quota' => null]), $owner);
    expect(app(ApiLimits::class)->effective($account->refresh())->quota)->toBe(1000);
});

it('só o DONO muda os limites: admin e membro da conta são recusados, com a tentativa na trilha', function (): void {
    enableApiLimits();
    $owner = $this->owner();
    $account = app(AccountService::class)->createAccount('Empresa', $owner);
    $admin = $this->owner();
    $member = $this->owner();
    app(AccountService::class)->addMember($account, $admin, AccountRole::Admin);
    app(AccountService::class)->addMember($account, $member, AccountRole::Member);

    foreach ([$admin, $member] as $person) {
        expect(fn () => Accounts::actingAs($account, fn () => app(ChangeApiLimits::class)->handle($person, ['quota' => 10]), $person))
            ->toThrow(AuthorizationException::class);
    }

    expect(DB::table('audit_events')->where('action', 'account.api_limits_changed')->where('outcome', 'denied')->count())->toBe(2)
        ->and(app(ApiLimits::class)->ownReductions($account->refresh())['quota'])->toBeNull();
});
