<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Twstec\Kit\Accounts\AccountsServiceProvider;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Accounts\ApiKeys\Support\ApiKeyHasher;
use Twstec\Kit\Accounts\Tenancy\Middleware\ResolveTenant;
use Twstec\Kit\Accounts\Tenancy\Services\ProjectService;
use Twstec\Kit\Auth\Enums\UserStatus;

// =============================================================================
// AS PROTEÇÕES DA API VÊM DO PACOTE — numa aplicação Laravel limpa (Testbench),
// sem nada do starter: nenhum middleware no bootstrap, nenhuma rota declarada
// pelo teste (as /api/v1 são as que o pacote registra), nenhum provider do
// aplicativo, nenhuma variável do .env do starter (tests/bootstrap.php). Se
// uma proteção daqui só funcionasse porque o starter lembrou de ligá-la, este
// arquivo reprovaria.
// =============================================================================

/**
 * Texto que o PACOTE traz para a chave (no idioma da suíte, en), lido do
 * arquivo — não do tradutor —, para provar que a mensagem é a do pacote.
 */
function accountsMessage(string $key, array $replace = []): string
{
    [$group, $item] = explode('.', $key, 2);
    $text = (string) Arr::get(require dirname(__DIR__, 2)."/lang/en/{$group}.php", $item);

    foreach ($replace as $name => $value) {
        $text = str_replace(':'.$name, $value, $text);
    }

    return $text;
}

/**
 * O envelope padrão de erro da API, e nada além dele.
 */
function assertApiErrorEnvelope(TestResponse $response, int $status, string $code): void
{
    $response->assertStatus($status)
        ->assertJsonStructure(['error' => ['code', 'message', 'correlation_id']])
        ->assertJsonPath('error.code', $code);

    $body = $response->json();

    expect(array_keys($body))->toBe(['error'])
        ->and(array_diff(array_keys($body['error']), ['code', 'message', 'correlation_id', 'errors']))->toBe([])
        ->and($response->headers->get('X-Correlation-Id'))->toBe($body['error']['correlation_id'])
        // Nada de detalhe interno: classe, arquivo, linha, stack trace.
        ->and($response->getContent())->not->toContain('Exception')
        ->and($response->getContent())->not->toContain('.php')
        ->and($response->getContent())->not->toContain('trace');
}

it('sem credencial: 401 no envelope padrão, com a mensagem do pacote e sem detalhe — nem com APP_DEBUG ligado', function (): void {
    config(['app.debug' => true]);

    $response = $this->getJson('/api/v1/projects');

    assertApiErrorEnvelope($response, 401, 'unauthorized');
    $response->assertJsonPath('error.message', accountsMessage('api_keys.auth.invalid'));
});

it('chave inválida repetida: o limite de falhas por credencial e IP responde 429 no envelope, e nem a secreta certa passa', function (): void {
    config(['security.rate_limit.api_auth_failures' => 3]);

    $owner = $this->owner();
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner);

    foreach (range(1, 3) as $tentativa) {
        $response = $this->getJson('/api/v1/projects', $this->credentials($key, 'sk_live_errada'.$tentativa));

        assertApiErrorEnvelope($response, 401, 'unauthorized');
    }

    $bloqueio = $this->getJson('/api/v1/projects', $this->credentials($key, $secret));

    assertApiErrorEnvelope($bloqueio, 429, 'too_many_requests');
    expect((int) $bloqueio->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('teto de falhas por IP: trocar a chave pública a cada tentativa não escapa do limite', function (): void {
    config(['security.rate_limit.api_auth_failures_per_ip' => 3]);

    foreach (range(1, 3) as $tentativa) {
        $this->getJson('/api/v1/projects', [
            'X-Api-Key' => 'pk_live_inventada'.$tentativa,
            'Authorization' => 'Bearer sk_live_inventada',
        ])->assertUnauthorized();
    }

    assertApiErrorEnvelope($this->getJson('/api/v1/projects', [
        'X-Api-Key' => 'pk_live_mais_uma',
        'Authorization' => 'Bearer sk_live_inventada',
    ]), 429, 'too_many_requests');
});

it('escopo ausente: 403 no envelope, com o escopo exigido na mensagem', function (): void {
    $owner = $this->owner();
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner, ['scopes' => ['projects:create']]);

    $response = $this->getJson('/api/v1/projects', $this->credentials($key, $secret));

    assertApiErrorEnvelope($response, 403, 'forbidden');
    $response->assertJsonPath('error.message', accountsMessage('api_keys.scopes.denied', ['scope' => 'projects:read']));
});

it('projeto fora do vínculo da chave: 404 no envelope (não revela que existe); o vinculado responde', function (): void {
    $owner = $this->owner();
    $projects = app(ProjectService::class);
    $vinculado = $this->inAccountOf($owner, fn () => $projects->create($owner, 'Vinculado'));
    $fora = $this->inAccountOf($owner, fn () => $projects->create($owner, 'Fora do vínculo'));
    $outro = $this->owner();
    $deOutro = $this->inAccountOf($outro, fn () => $projects->create($outro, 'De outra conta'));

    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner, ['project_uuids' => [$vinculado->uuid]]);
    $headers = $this->credentials($key, $secret);

    assertApiErrorEnvelope($this->getJson("/api/v1/projects/{$fora->uuid}", $headers), 404, 'not_found');
    assertApiErrorEnvelope($this->getJson("/api/v1/projects/{$deOutro->uuid}", $headers), 404, 'not_found');

    $this->getJson("/api/v1/projects/{$vinculado->uuid}", $headers)
        ->assertOk()
        ->assertJsonPath('data.uuid', $vinculado->uuid);

    expect(collect($this->getJson('/api/v1/projects', $headers)->assertOk()->json('data'))->pluck('uuid')->all())
        ->toBe([$vinculado->uuid]);
});

it('chave vinculada a projetos não faz operação de conta: 403 no envelope', function (): void {
    $owner = $this->owner();
    $projeto = $this->inAccountOf($owner, fn () => app(ProjectService::class)->create($owner, 'Único'));
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner, ['project_uuids' => [$projeto->uuid]]);

    $response = $this->postJson('/api/v1/projects', ['name' => 'Novo'], $this->credentials($key, $secret));

    assertApiErrorEnvelope($response, 403, 'forbidden');
    $response->assertJsonPath('error.message', accountsMessage('api_keys.projects.account_key_required'));
});

it('limite por chave: a chave que passa do limite recebe 429, e outra chave do mesmo IP continua', function (): void {
    config(['security.rate_limit.api' => 2]);

    $owner = $this->owner();
    ['api_key' => $primeira, 'secret_key' => $secretPrimeira] = $this->keyFor($owner);
    ['api_key' => $segunda, 'secret_key' => $secretSegunda] = $this->keyFor($owner);

    $this->getJson('/api/v1/projects', $this->credentials($primeira, $secretPrimeira))->assertOk();
    $this->getJson('/api/v1/projects', $this->credentials($primeira, $secretPrimeira))->assertOk();

    $limite = $this->getJson('/api/v1/projects', $this->credentials($primeira, $secretPrimeira));

    assertApiErrorEnvelope($limite, 429, 'too_many_requests');
    expect((int) $limite->headers->get('Retry-After'))->toBeGreaterThan(0);

    // O limite conta pela CHAVE (a autenticação roda antes dele), não pelo IP.
    $this->getJson('/api/v1/projects', $this->credentials($segunda, $secretSegunda))->assertOk();
});

it('recusa com 401 a chave revogada, expirada, rotacionada fora da graça e a inativa há mais que o limite', function (): void {
    $owner = $this->owner();
    $keys = app(ApiKeyService::class);

    ['api_key' => $revogada, 'secret_key' => $s1] = $this->keyFor($owner);
    $keys->revoke($revogada);

    ['api_key' => $expirada, 'secret_key' => $s2] = $this->keyFor($owner);
    $expirada->forceFill(['expires_at' => now()->subMinute()])->save();

    ['api_key' => $rotacionada, 'secret_key' => $s3] = $this->keyFor($owner);
    $this->inAccountOf($owner, fn () => $keys->rotate($rotacionada, null));

    ['api_key' => $inativa, 'secret_key' => $s4] = $this->keyFor($owner);
    $inativa->forceFill(['last_used_at' => now()->subMonthsNoOverflow((int) config('api_keys.inactivity.months'))->subDay()])->save();

    foreach ([[$revogada, $s1], [$expirada, $s2], [$rotacionada, $s3], [$inativa, $s4]] as [$key, $secret]) {
        assertApiErrorEnvelope($this->getJson('/api/v1/projects', $this->credentials($key->refresh(), $secret)), 401, 'unauthorized');
    }

    // A expiração por inatividade vale na AUTENTICAÇÃO — não depende do
    // agendamento do job diário, que é do aplicativo.
    expect($inativa->refresh()->status)->toBe(ApiKeyStatus::Active);
});

it('recusa com 401 a chave de dono com e-mail não confirmado ou com conta inativa', function (): void {
    $naoVerificado = $this->owner(['email_verified_at' => null]);
    ['api_key' => $k1, 'secret_key' => $s1] = $this->keyFor($naoVerificado);

    $bloqueado = $this->owner(['status' => UserStatus::Blocked->value]);
    ['api_key' => $k2, 'secret_key' => $s2] = $this->keyFor($bloqueado);

    assertApiErrorEnvelope($this->getJson('/api/v1/projects', $this->credentials($k1, $s1)), 401, 'unauthorized');
    assertApiErrorEnvelope($this->getJson('/api/v1/projects', $this->credentials($k2, $s2)), 401, 'unauthorized');

    // A mesma chave, com o dono em dia, passa.
    $naoVerificado->forceFill(['email_verified_at' => now()])->save();
    $this->getJson('/api/v1/projects', $this->credentials($k1, $s1))->assertOk();
});

it('credencial válida: 200 no envelope de sucesso, com o tenant resolvido', function (): void {
    $owner = $this->owner();
    $this->inAccountOf($owner, fn () => app(ProjectService::class)->create($owner, 'Meu projeto'));
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner);

    $this->getJson('/api/v1/projects', $this->credentials($key, $secret))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Meu projeto');

    expect($key->refresh()->last_used_at)->not->toBeNull();
});

it('pepper: o hash da secreta usa API_KEYS_HASH_PEPPER quando existe e cai na APP_KEY quando não — e trocar o pepper invalida as chaves', function (): void {
    // Sem pepper próprio (o padrão de uma aplicação nova): APP_KEY.
    expect(config('api_keys.hash_pepper'))->toBe(env('APP_KEY'))
        ->and(env('APP_KEY'))->toBeString()->not->toBe('');

    $owner = $this->owner();
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner);

    expect($key->secret_hash)->toBe(hash_hmac('sha256', $secret, (string) env('APP_KEY')));

    $hashGravado = (string) $key->secret_hash;

    putenv('API_KEYS_HASH_PEPPER=pepper-dedicado-de-teste');
    $_ENV['API_KEYS_HASH_PEPPER'] = $_SERVER['API_KEYS_HASH_PEPPER'] = 'pepper-dedicado-de-teste';

    try {
        // Um boot novo lê o .env de novo (a config é montada no boot).
        $this->refreshApplication();

        $hasher = app(ApiKeyHasher::class);

        expect(config('api_keys.hash_pepper'))->toBe('pepper-dedicado-de-teste')
            ->and($hasher->hash($secret))->toBe(hash_hmac('sha256', $secret, 'pepper-dedicado-de-teste'))
            // A chave gravada com o pepper antigo deixa de conferir.
            ->and($hasher->verify($secret, $hashGravado))->toBeFalse();
    } finally {
        putenv('API_KEYS_HASH_PEPPER');
        unset($_ENV['API_KEYS_HASH_PEPPER'], $_SERVER['API_KEYS_HASH_PEPPER']);
    }
});

it('instala no kernel: throttle:api na frente do grupo api, a autenticação antes do limite na prioridade e os aliases', function (): void {
    $kernel = app(Kernel::class);
    $api = $kernel->getMiddlewareGroups()['api'];
    $priority = $kernel->getMiddlewarePriority();
    $aliases = $kernel->getMiddlewareAliases();

    expect($api[0])->toBe(AccountsServiceProvider::API_GROUP_THROTTLE)
        ->and(array_count_values($api)[AccountsServiceProvider::API_GROUP_THROTTLE])->toBe(1)
        ->and(array_search(ResolveTenant::class, $priority, true))
        ->toBe(array_search(ThrottleRequests::class, $priority, true) - 1);

    foreach (AccountsServiceProvider::MIDDLEWARE_ALIASES as $alias => $middleware) {
        expect($aliases[$alias])->toBe($middleware);
    }
});

it('opt-out explícito das proteções: não instala nada e avisa no log a cada boot', function (): void {
    $this->bootWith(['api_keys.api.protections' => false]);

    // Uma requisição qualquer resolve o kernel HTTP (é quando o pacote
    // instalaria as proteções) e o tratador de exceções.
    $this->getJson('/api/nao-existe')->assertNotFound();

    $kernel = app(Kernel::class);

    expect($kernel->getMiddlewareGroups()['api'])->not->toContain(AccountsServiceProvider::API_GROUP_THROTTLE)
        ->and($kernel->getMiddlewarePriority())->not->toContain(ResolveTenant::class);

    foreach (array_keys(AccountsServiceProvider::MIDDLEWARE_ALIASES) as $alias) {
        expect($kernel->getMiddlewareAliases())->not->toHaveKey($alias);
    }

    Log::spy();

    (new AccountsServiceProvider($this->app))->boot();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_starts_with($message, 'API_KEYS_API_PROTECTIONS=false'));
});
