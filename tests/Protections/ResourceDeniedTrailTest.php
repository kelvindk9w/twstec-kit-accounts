<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\AccountResourceGuard;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyAttempt;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Enums\ProjectAttempt;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Accounts\Tests\Fixtures\User;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// As RECUSAS das telas de chaves de API e de projetos ficam na trilha.
//
// Requisito do dono: tudo que é tentado no painel fica no banco, inclusive as
// recusas. As telas dos dois starters passam pelo AccountResourceGuard:
//
// - papel que não permite → o MESMO 403, com a MESMA mensagem de
//   Accounts::authorize(), e a linha `denied` com a ação tentada;
// - chave ou projeto fora da conta atual → a MESMA ModelNotFoundException
//   (o 404 comum, idêntico para "de outra conta" e "não existe" — não revela
//   existência) e a linha `denied` com o uuid tentado;
// - projeto de fora da conta no vínculo → `denied` (o erro de validação é da
//   tela).
//
// Na API v1 (por chave), o 403 de escopo e o de chave vinculada em operação de
// conta gravam `denied` no contexto `api`; os 401 e 404 ficam FORA da trilha
// (vão para o request_logs como sinal de ataque).
// =============================================================================

/**
 * @return array{conta: Account, dono: User, admin: User, membro: User}
 */
function equipeDosRecursos(): array
{
    $dono = User::fixture(['email_verified_at' => now()]);
    $admin = User::fixture(['email_verified_at' => now()]);
    $membro = User::fixture(['email_verified_at' => now()]);
    $conta = app(AccountService::class)->createAccount('Empresa Recursos', $dono);
    app(AccountService::class)->addMember($conta, $admin, AccountRole::Admin);
    app(AccountService::class)->addMember($conta, $membro, AccountRole::Member);

    return ['conta' => $conta, 'dono' => $dono, 'admin' => $admin, 'membro' => $membro];
}

/**
 * Roda a guarda como a tela roda: a pessoa logada, na conta atual.
 *
 * @template T
 *
 * @param  Closure(AccountResourceGuard): T  $callback
 * @return T
 */
function naTela(Account $conta, User $quem, Closure $callback): mixed
{
    test()->actingAs($quem);

    return Accounts::actingAs($conta, fn () => $callback(app(AccountResourceGuard::class)), $quem);
}

/**
 * @return list<array<string, mixed>>
 */
function linhasDaTrilha(): array
{
    return AuditEvent::query()->orderBy('id')->get()
        ->map(fn (AuditEvent $e): array => [
            'action' => $e->action,
            'outcome' => $e->outcome->value,
            'context' => $e->context->value,
            'actor' => $e->actor_uuid,
            'tenant' => $e->tenant_uuid,
            'subject_type' => $e->subject_type,
            'subject_uuid' => $e->subject_uuid,
            'reason' => $e->reason,
        ])
        ->all();
}

dataset('recusas de papel do member', [
    'excluir projeto' => [AccountAbility::DeleteProjects, ProjectAttempt::Deleted, 'project'],
    'criar chave' => [AccountAbility::ManageApiKeys, ApiKeyAttempt::Created, 'api_key'],
    'rotacionar chave' => [AccountAbility::ManageApiKeys, ApiKeyAttempt::Rotated, 'api_key'],
    'revogar chave' => [AccountAbility::ManageApiKeys, ApiKeyAttempt::Revoked, 'api_key'],
    'vincular projetos à chave' => [AccountAbility::ManageApiKeys, ApiKeyAttempt::ProjectsSynced, 'api_key'],
]);

it('papel que não permite: o mesmo 403 com a mesma mensagem, e a tentativa grava denied', function (AccountAbility $habilidade, ProjectAttempt|ApiKeyAttempt $tentativa, string $tipo): void {
    $e = equipeDosRecursos();
    $alvo = (string) Str::uuid();

    // A mensagem de sempre (a mesma de Accounts::authorize()).
    $mensagemDeSempre = null;
    try {
        naTela($e['conta'], $e['membro'], fn () => Accounts::authorize($habilidade));
    } catch (AuthorizationException $excecao) {
        $mensagemDeSempre = $excecao->getMessage();
    }
    expect($mensagemDeSempre)->toBe(__('accounts.authorization.denied'))
        ->and(AuditEvent::query()->count())->toBe(0);

    expect(fn () => naTela($e['conta'], $e['membro'], fn (AccountResourceGuard $g) => $g->authorize($habilidade, $tentativa, $alvo)))
        ->toThrow(AuthorizationException::class, $mensagemDeSempre);

    expect(linhasDaTrilha())->toBe([[
        'action' => $tentativa->value,
        'outcome' => 'denied',
        'context' => 'panel',
        'actor' => (string) $e['membro']->uuid,
        'tenant' => (string) $e['conta']->uuid,
        'subject_type' => $tipo,
        'subject_uuid' => $alvo,
        'reason' => __('accounts.authorization.denied'),
    ]]);
})->with('recusas de papel do member');

it('quem pode passa sem linha nenhuma (dono e admin, em todas as habilidades das telas)', function (): void {
    $e = equipeDosRecursos();

    foreach (['dono', 'admin'] as $papel) {
        naTela($e['conta'], $e[$papel], function (AccountResourceGuard $g): void {
            $g->authorize(AccountAbility::CreateProjects, ProjectAttempt::Created);
            $g->authorize(AccountAbility::UpdateProjects, ProjectAttempt::Updated);
            $g->authorize(AccountAbility::DeleteProjects, ProjectAttempt::Deleted);
            $g->authorize(AccountAbility::ManageApiKeys, ApiKeyAttempt::Created);
        });
    }

    // O member cria e edita projetos (o papel permite): também sem linha.
    naTela($e['conta'], $e['membro'], function (AccountResourceGuard $g): void {
        $g->authorize(AccountAbility::CreateProjects, ProjectAttempt::Created);
        $g->authorize(AccountAbility::UpdateProjects, ProjectAttempt::Updated);
    });

    expect(AuditEvent::query()->count())->toBe(0);
});

it('o alvo tentado que não é uuid não vai para a coluna (a linha fica, sem o alvo)', function (): void {
    $e = equipeDosRecursos();

    expect(fn () => naTela($e['conta'], $e['membro'], fn (AccountResourceGuard $g) => $g->authorize(AccountAbility::ManageApiKeys, ApiKeyAttempt::Revoked, "x' or 1=1 --")))
        ->toThrow(AuthorizationException::class);

    expect(linhasDaTrilha())->toHaveCount(1)
        ->and(linhasDaTrilha()[0]['action'])->toBe('api_key.revoked')
        ->and(linhasDaTrilha()[0]['subject_uuid'])->toBeNull();
});

it('chave e projeto de OUTRA conta: a mesma exceção de "não existe" (404 igual), e a tentativa grava denied', function (): void {
    $e = equipeDosRecursos();
    $outra = User::fixture(['email_verified_at' => now()]);
    $contaDaOutra = app(AccountService::class)->personalAccountOf($outra);
    ['api_key' => $chaveAlheia] = $this->keyFor($outra);
    $projetoAlheio = Accounts::actingAs($contaDaOutra, fn () => Project::createWithPublicCodeRetry(['name' => 'Alheio']), $outra);
    $inexistente = (string) Str::uuid();

    $mensagem = function (Closure $tentativa) use ($e): string {
        try {
            naTela($e['conta'], $e['dono'], $tentativa);
        } catch (ModelNotFoundException $excecao) {
            return $excecao::class.'|'.$excecao->getMessage();
        }

        throw new LogicException('a guarda achou o que não é da conta');
    };

    // De outra conta e inexistente: exatamente a mesma exceção e mensagem.
    $chaveDeOutra = $mensagem(fn (AccountResourceGuard $g) => $g->apiKey((string) $chaveAlheia->uuid, ApiKeyAttempt::Revoked));
    $chaveQueNaoExiste = $mensagem(fn (AccountResourceGuard $g) => $g->apiKey($inexistente, ApiKeyAttempt::Revoked));
    $projetoDeOutra = $mensagem(fn (AccountResourceGuard $g) => $g->project((string) $projetoAlheio->uuid, ProjectAttempt::Deleted));
    $projetoQueNaoExiste = $mensagem(fn (AccountResourceGuard $g) => $g->project($inexistente, ProjectAttempt::Deleted));
    $naoEUuid = $mensagem(fn (AccountResourceGuard $g) => $g->project('nao-e-uuid', ProjectAttempt::Updated));

    expect($chaveDeOutra)->toBe($chaveQueNaoExiste)
        ->and($projetoDeOutra)->toBe($projetoQueNaoExiste)
        ->and($naoEUuid)->toBe($projetoQueNaoExiste);

    $linha = fn (string $action, string $tipo, ?string $uuid): array => [
        'action' => $action,
        'outcome' => 'denied',
        'context' => 'panel',
        'actor' => (string) $e['dono']->uuid,
        'tenant' => (string) $e['conta']->uuid,
        'subject_type' => $tipo,
        'subject_uuid' => $uuid,
        'reason' => __('accounts.authorization.not_found'),
    ];

    expect(linhasDaTrilha())->toBe([
        $linha('api_key.revoked', 'api_key', (string) $chaveAlheia->uuid),
        $linha('api_key.revoked', 'api_key', $inexistente),
        $linha('project.deleted', 'project', (string) $projetoAlheio->uuid),
        $linha('project.deleted', 'project', $inexistente),
        $linha('project.updated', 'project', null),
    ]);

    // Nada mudou do lado da outra conta.
    expect(Accounts::asSystem('teste', fn () => ApiKey::query()->whereKey($chaveAlheia->getKey())->firstOrFail()->status))->toBe($chaveAlheia->status)
        ->and(Accounts::asSystem('teste', fn () => Project::query()->whereKey($projetoAlheio->getKey())->exists()))->toBeTrue();
});

it('chave e projeto da conta atual: acha, sem linha nenhuma', function (): void {
    $e = equipeDosRecursos();
    ['api_key' => $chave] = $this->keyFor($e['dono'], [], $e['conta']);
    $projeto = Accounts::actingAs($e['conta'], fn () => Project::createWithPublicCodeRetry(['name' => 'Da empresa']), $e['dono']);

    naTela($e['conta'], $e['membro'], function (AccountResourceGuard $g) use ($chave, $projeto): void {
        expect($g->apiKey((string) $chave->uuid, ApiKeyAttempt::Rotated)->is($chave))->toBeTrue()
            ->and($g->project((string) $projeto->uuid, ProjectAttempt::Updated)->is($projeto))->toBeTrue();
    });

    expect(AuditEvent::query()->count())->toBe(0);
});

it('projeto de fora da conta no vínculo da chave grava denied com o motivo do erro de validação', function (): void {
    $e = equipeDosRecursos();
    $chave = (string) Str::uuid();

    naTela($e['conta'], $e['admin'], fn (AccountResourceGuard $g) => $g->foreignProjects(ApiKeyAttempt::ProjectsSynced, $chave));

    expect(linhasDaTrilha())->toBe([[
        'action' => 'api_key.projects_synced',
        'outcome' => 'denied',
        'context' => 'panel',
        'actor' => (string) $e['admin']->uuid,
        'tenant' => (string) $e['conta']->uuid,
        'subject_type' => 'api_key',
        'subject_uuid' => $chave,
        'reason' => __('api_keys.projects.invalid'),
    ]]);
});

it('API v1: a chave autenticada sem o escopo recebe o mesmo 403 e a tentativa grava denied no contexto api', function (): void {
    $dona = $this->owner();
    ['api_key' => $chave, 'secret_key' => $segredo] = $this->keyFor($dona, ['scopes' => ['projects:read']]);

    $this->postJson('/api/v1/projects', ['name' => 'Novo'], $this->credentials($chave, $segredo))
        ->assertForbidden()
        ->assertJsonPath('error.message', __('api_keys.scopes.denied', ['scope' => 'projects:create']));

    expect(linhasDaTrilha())->toBe([[
        'action' => 'api_key.scope_denied',
        'outcome' => 'denied',
        'context' => 'api',
        'actor' => (string) $dona->uuid,
        'tenant' => (string) $this->accountOf($dona)->uuid,
        'subject_type' => 'api_key',
        'subject_uuid' => (string) $chave->uuid,
        'reason' => __('api_keys.scopes.denied', ['scope' => 'projects:create']),
    ]]);

    // Com o escopo, passa sem linha de recusa.
    $this->getJson('/api/v1/projects', $this->credentials($chave, $segredo))->assertOk();
    expect(AuditEvent::query()->count())->toBe(1);
});

it('API v1: a chave vinculada a projetos em operação de conta recebe o mesmo 403 e a tentativa grava denied', function (): void {
    $dona = $this->owner();
    $projeto = $this->inAccountOf($dona, fn () => Project::createWithPublicCodeRetry(['name' => 'Só este']));
    ['api_key' => $chave, 'secret_key' => $segredo] = $this->keyFor($dona, ['project_uuids' => [(string) $projeto->uuid]]);

    $this->postJson('/api/v1/projects', ['name' => 'Fora do alcance'], $this->credentials($chave, $segredo))
        ->assertForbidden()
        ->assertJsonPath('error.message', __('api_keys.projects.account_key_required'));

    expect(linhasDaTrilha())->toBe([[
        'action' => 'api_key.account_key_required',
        'outcome' => 'denied',
        'context' => 'api',
        'actor' => (string) $dona->uuid,
        'tenant' => (string) $this->accountOf($dona)->uuid,
        'subject_type' => 'api_key',
        'subject_uuid' => (string) $chave->uuid,
        'reason' => __('api_keys.projects.account_key_required'),
    ]]);

    // A própria chave agindo sobre si mesma (account.key:self) não é recusa.
    $this->deleteJson("/api/v1/api-keys/{$chave->uuid}", [], $this->credentials($chave, $segredo))->assertOk();
    expect(AuditEvent::query()->where('outcome', 'denied')->count())->toBe(1);
});

it('API v1: 401 (credencial inválida) e 404 (recurso de outra conta) ficam FORA da trilha', function (): void {
    $dona = $this->owner();
    $outra = $this->owner();
    ['api_key' => $chave, 'secret_key' => $segredo] = $this->keyFor($dona);
    $alheio = $this->inAccountOf($outra, fn () => Project::createWithPublicCodeRetry(['name' => 'Alheio']));
    ['api_key' => $chaveAlheia] = $this->keyFor($outra);

    $this->getJson('/api/v1/projects', $this->credentials($chave, 'sk_live_errada'))->assertUnauthorized();
    $this->getJson('/api/v1/projects')->assertUnauthorized();
    $this->getJson("/api/v1/projects/{$alheio->uuid}", $this->credentials($chave, $segredo))->assertNotFound();
    $this->deleteJson("/api/v1/projects/{$alheio->uuid}", [], $this->credentials($chave, $segredo))->assertNotFound();
    $this->deleteJson("/api/v1/api-keys/{$chaveAlheia->uuid}", [], $this->credentials($chave, $segredo))->assertNotFound();

    expect(AuditEvent::query()->count())->toBe(0);
});
