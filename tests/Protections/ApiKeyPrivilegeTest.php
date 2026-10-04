<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Exceptions\ApiKeyPrivilegeExceededException;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Accounts\Tenancy\TenantContext;
use Twstec\Kit\Accounts\Tests\Fixtures\User;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// SEM ESCALADA DE PRIVILÉGIO PELA API v1: uma chave só cria, rotaciona ou
// edita chave que caiba nela — escopos (com curinga) e projetos. `scopes`
// omitido herda os escopos da chave autenticada (nunca `*:*` por omissão).
// Recusa: 403 com código estável no envelope e a tentativa na trilha. Pela
// sessão (o painel), vale o papel na conta — o serviço sem chave autenticada
// continua como sempre.
// =============================================================================

function privilegeToken(User $user): string
{
    $token = 'token-privilegio-'.bin2hex(random_bytes(16));

    DB::table('sensitive_action_tokens')->insert([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $token;
}

/**
 * @param  list<string>  $scopes
 * @return array<string, string>
 */
function privilegeHeaders(mixed $test, User $owner, array $scopes, array $data = []): array
{
    // keyFor() e credentials() são do TestCase (protegidos): chamados nele.
    return (function () use ($owner, $scopes, $data): array {
        ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner, ['scopes' => $scopes, ...$data]);

        return [...$this->credentials($key, $secret), 'X-Sensitive-Action-Token' => privilegeToken($owner)];
    })->call($test);
}

function privilegeKeys(): int
{
    return Accounts::asSystem('teste', fn (): int => ApiKey::query()->count());
}

it('SUBCONJUNTO: cria chave com escopos que cabem nos da chave autenticada', function (array $grants, array $asked): void {
    $owner = $this->owner();
    $headers = privilegeHeaders($this, $owner, $grants);

    $this->postJson('/api/v1/api-keys', ['name' => 'Menor', 'scopes' => $asked], $headers)
        ->assertCreated()
        ->assertJsonPath('data.scopes', $asked);
})->with([
    'exato' => [['api-keys:create', 'orders:create'], ['orders:create']],
    'curinga de ação cobre a ação' => [['api-keys:create', 'orders:*'], ['orders:create', 'orders:read']],
    'curinga de ação cobre o curinga' => [['api-keys:create', 'orders:*'], ['orders:*']],
    'curinga de recurso' => [['api-keys:create', '*:read'], ['orders:read', 'customers:read']],
    'tudo cobre tudo' => [['*:*'], ['*:*']],
]);

it('SUPERCONJUNTO: pedir escopo além dos seus é 403 api_key_scope_exceeded, sem criar, com a recusa na trilha', function (array $grants, array $asked, array $exceeding): void {
    $owner = $this->owner();
    $headers = privilegeHeaders($this, $owner, $grants);
    $before = privilegeKeys();

    $this->postJson('/api/v1/api-keys', ['name' => 'Maior', 'scopes' => $asked], $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'api_key_scope_exceeded')
        ->assertJsonPath('error.message', __('api_keys.scopes.exceeded', ['scopes' => implode(', ', $exceeding)]));

    $row = AuditEvent::query()->where('action', 'api_key.privilege_exceeded')->sole();

    expect(privilegeKeys())->toBe($before)
        ->and($row->outcome->value)->toBe('denied')
        ->and($row->context->value)->toBe('api')
        ->and($row->actor_uuid)->toBe($owner->uuid);
})->with([
    'outro recurso' => [['api-keys:create', 'orders:create'], ['customers:read'], ['customers:read']],
    'curinga pedido sem tê-lo' => [['api-keys:create', 'orders:create'], ['orders:*'], ['orders:*']],
    '*:* sem ter *:*' => [['api-keys:create', 'orders:*'], ['*:*'], ['*:*']],
    'curinga de recurso sem tê-lo' => [['api-keys:create', 'orders:read'], ['*:read'], ['*:read']],
    'um a mais no meio' => [['api-keys:create', 'orders:read'], ['orders:read', 'api-keys:create', 'api-keys:revoke'], ['api-keys:revoke']],
]);

it('OMITIDO: a chave nova herda exatamente os escopos da chave autenticada — nunca *:*', function (): void {
    $owner = $this->owner();
    $headers = privilegeHeaders($this, $owner, ['api-keys:create', 'orders:read']);

    $this->postJson('/api/v1/api-keys', ['name' => 'Herdada'], $headers)
        ->assertCreated()
        ->assertJsonPath('data.scopes', ['api-keys:create', 'orders:read']);

    $this->postJson('/api/v1/api-keys', ['name' => 'Herdada nula', 'scopes' => null], privilegeHeaders($this, $owner, ['api-keys:create', 'orders:read']))
        ->assertCreated()
        ->assertJsonPath('data.scopes', ['api-keys:create', 'orders:read']);
});

it('ROTAÇÃO: rotacionar chave mais ampla que a autenticada é 403 (a secreta nova não sai); a si mesma e a menor, pode', function (): void {
    $owner = $this->owner();
    ['api_key' => $ampla] = $this->keyFor($owner, ['scopes' => ['*:*']]);
    ['api_key' => $menor] = $this->keyFor($owner, ['scopes' => ['orders:read']]);
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner, ['scopes' => ['api-keys:rotate', 'orders:*']]);
    $headers = fn (): array => [...$this->credentials($key, $secret), 'X-Sensitive-Action-Token' => privilegeToken($owner)];

    $this->postJson("/api/v1/api-keys/{$ampla->uuid}/rotate", [], $headers())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'api_key_scope_exceeded')
        ->assertJsonMissingPath('secret_key');

    expect(Accounts::asSystem('teste', fn () => $ampla->fresh()->rotated_to_id))->toBeNull();

    $this->postJson("/api/v1/api-keys/{$menor->uuid}/rotate", [], $headers())->assertCreated();
    $this->postJson("/api/v1/api-keys/{$key->uuid}/rotate", [], $headers())->assertCreated();
});

it('EDITAR (projetos): só chave que caiba na autenticada', function (): void {
    $owner = $this->owner();
    $project = $this->inAccountOf($owner, fn () => Project::query()->create(['name' => 'Loja']));
    ['api_key' => $ampla] = $this->keyFor($owner, ['scopes' => ['*:*']]);
    ['api_key' => $menor] = $this->keyFor($owner, ['scopes' => ['orders:read']]);
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner, ['scopes' => ['api-keys:assign', 'orders:read']]);

    $this->putJson("/api/v1/api-keys/{$ampla->uuid}/projects", ['project_uuids' => []], $this->credentials($key, $secret))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'api_key_scope_exceeded');

    $this->putJson("/api/v1/api-keys/{$menor->uuid}/projects", ['project_uuids' => [$project->uuid]], $this->credentials($key, $secret))
        ->assertOk();
});

it('PROJETOS: chave restrita a projetos não cria nem edita fora deles (defesa além do account.key)', function (): void {
    $owner = $this->owner();
    $account = $this->accountOf($owner);
    [$loja, $outro] = $this->inAccountOf($owner, fn () => [Project::query()->create(['name' => 'Loja']), Project::query()->create(['name' => 'Outro'])]);
    ['api_key' => $restrita] = $this->keyFor($owner, ['scopes' => ['*:*'], 'project_uuids' => [$loja->uuid]]);
    $service = app(ApiKeyService::class);

    // Como a requisição da API deixaria o contexto (o resolve.tenant).
    app(TenantContext::class)->resolve($account, $restrita, $owner);

    try {
        $herdada = Accounts::actingAs($account, fn () => $service->create($owner, ['name' => 'Herda os projetos']), $owner);

        expect($herdada['api_key']->isRestrictedToProjects())->toBeTrue()
            ->and(Accounts::asSystem('t', fn () => $herdada['api_key']->projects()->pluck('projects.uuid')->all()))->toBe([$loja->uuid]);

        expect(fn () => Accounts::actingAs($account, fn () => $service->create($owner, ['name' => 'Fora', 'project_uuids' => [$outro->uuid]]), $owner))
            ->toThrow(ApiKeyPrivilegeExceededException::class);

        expect(fn () => Accounts::actingAs($account, fn () => $service->syncProjects($herdada['api_key'], []), $owner))
            ->toThrow(ApiKeyPrivilegeExceededException::class);
    } finally {
        app(TenantContext::class)->forget();
    }

    expect(AuditEvent::query()->where('action', 'api_key.privilege_exceeded')->count())->toBe(2);
});

it('PELA SESSÃO (sem chave autenticada): o padrão da configuração continua, e quem decide é o papel', function (): void {
    $owner = $this->owner();

    $result = $this->inAccountOf($owner, fn () => app(ApiKeyService::class)->create($owner, ['name' => 'Do painel']));

    expect($result['api_key']->scopes)->toBe(['*:*']);
});
