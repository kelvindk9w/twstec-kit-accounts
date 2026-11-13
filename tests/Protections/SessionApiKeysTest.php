<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\AccountsServiceProvider;
use Twstec\Kit\Accounts\ApiKeys\Confirmation\ApiKeyCreationConfirmation;
use Twstec\Kit\Accounts\ApiKeys\Confirmation\ApiKeyCreationConfirmed;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Events\SessionApiKeysRevoked;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Accounts\ApiKeys\Sessions\SessionApiKeys;
use Twstec\Kit\Accounts\Deletion\AccountDeletion;
use Twstec\Kit\Accounts\Tests\Fixtures\User;
use Twstec\Kit\Auth\Enums\SessionEndReason;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Auth\Events\SessionsEnded;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Sessions\SessionBinding;

// =============================================================================
// CHAVE DE API DE SESSÃO: presa à sessão web de quem a criou, fora das listas
// e contagens, revogada NA HORA quando a sessão termina por qualquer caminho
// (logout, outros aparelhos, troca de senha, bloqueio) e quando ela vence por
// inatividade; apagada pela rotina agendada depois de revogada ou vencida.
// Numa aplicação limpa, sem nada do starter.
// =============================================================================

beforeEach(function (): void {
    // Várias chaves da mesma pessoa no mesmo teste: sem o intervalo de reenvio.
    config(['api_keys.environment' => 'test', 'session.lifetime' => 120, 'auth.verification.resend_cooldown_seconds' => 0]);
});

/**
 * A sessão do guard web desta execução, com a pessoa autenticada nela.
 */
function webSessionOf(User $user): Session
{
    /** @var Store $session */
    $session = app('session.store');
    $session->start();

    Auth::guard('web')->login($user);

    return $session;
}

/**
 * Uma sessão de OUTRO aparelho da mesma pessoa (outro armazenamento, com a
 * pessoa autenticada nele).
 */
function otherDeviceSessionOf(User $user): Session
{
    $session = new Store('outro-aparelho', new ArraySessionHandler(120));
    $session->start();
    $session->put(Auth::guard('web')->getName(), $user->getKey());

    return $session;
}

/**
 * A prova de confirmação, pelo caminho de verdade (código por e-mail).
 */
function confirmedCreation(User $user): ApiKeyCreationConfirmed
{
    Mail::fake();

    $confirmation = app(ApiKeyCreationConfirmation::class);
    $confirmation->sendCode($user);

    $code = null;
    Mail::assertQueued(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    return $confirmation->confirm($user, (string) $code);
}

/**
 * @param  array<string, mixed>  $data
 * @return array{api_key: ApiKey, secret_key: string}
 */
function sessionKeyFor(mixed $test, User $user, Session $session, array $data = []): array
{
    return (fn (): array => $this->inAccountOf($user, fn (): array => app(ApiKeyService::class)->createForSession(
        $user,
        $session,
        ['name' => 'Documentação', 'scopes' => ['projects:read'], ...$data],
        confirmedCreation($user),
    )))->call($test);
}

function freshKey(ApiKey $key): ?ApiKey
{
    return Accounts::asSystem('teste', fn (): ?ApiKey => ApiKey::query()->withSessionKeys()->whereKey($key->id)->first());
}

it('nasce presa à sessão, fora da lista e das contagens, e autentica como qualquer chave', function (): void {
    $owner = $this->owner();
    $session = webSessionOf($owner);

    ['api_key' => $key, 'secret_key' => $secret] = sessionKeyFor($this, $owner, $session);
    ['api_key' => $regular, 'secret_key' => $regularSecret] = $this->keyFor($owner, ['scopes' => ['api-keys:read']]);

    expect($key->isSessionBound())->toBeTrue()
        ->and($key->session_binding)->toBe(SessionBinding::current($session, $owner))
        ->and($key->scopes)->toBe(['projects:read'])
        ->and($regular->isSessionBound())->toBeFalse();

    $this->inAccountOf($owner, function () use ($key, $regular, $owner): void {
        expect(ApiKey::query()->pluck('uuid')->all())->toBe([(string) $regular->uuid])
            ->and($this->accountOf($owner)->apiKeys()->count())->toBe(1)
            ->and(ApiKey::query()->withSessionKeys()->count())->toBe(2)
            ->and(ApiKey::query()->onlySessionKeys()->pluck('uuid')->all())->toBe([(string) $key->uuid]);
    });

    // A lista da API v1 não a mostra.
    $this->getJson('/api/v1/api-keys', $this->credentials($regular, $regularSecret))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', (string) $regular->uuid)
        ->assertJsonPath('data.0.session_bound', false);

    // Mas ela autentica, com os escopos dela.
    $this->getJson('/api/v1/projects', $this->credentials($key, $secret))->assertOk();
    $this->getJson('/api/v1/api-keys', $this->credentials($key, $secret))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

it('só nasce na sessão de quem cria e com uma confirmação nova a cada chave', function (): void {
    $owner = $this->owner();
    $intruder = $this->owner();
    $session = webSessionOf($owner);
    $proof = confirmedCreation($owner);

    $create = fn (User $creator, Session $on, ApiKeyCreationConfirmed $with): array => $this->inAccountOf($creator, fn (): array => app(ApiKeyService::class)
        ->createForSession($creator, $on, ['name' => 'Documentação'], $with));

    // A sessão é de outra pessoa.
    expect(fn () => $create($intruder, $session, confirmedCreation($intruder)))
        ->toThrow(InvalidArgumentException::class);

    // A prova é de outra pessoa.
    expect(fn () => $create($owner, $session, confirmedCreation($intruder)))
        ->toThrow(LogicException::class, 'outra pessoa');

    $create($owner, $session, $proof);

    // A mesma prova não cria a segunda chave.
    expect(fn () => $create($owner, $session, $proof))->toThrow(LogicException::class, 'já foi usada');

    expect(Accounts::asSystem('teste', fn (): int => ApiKey::query()->onlySessionKeys()->count()))->toBe(1);
});

it('logout revoga NA HORA as chaves desta sessão — e só elas — e avisa o aplicativo', function (): void {
    $owner = $this->owner();
    $session = webSessionOf($owner);

    ['api_key' => $key, 'secret_key' => $secret] = sessionKeyFor($this, $owner, $session);
    ['api_key' => $otherDevice] = sessionKeyFor($this, $owner, otherDeviceSessionOf($owner));
    ['api_key' => $regular] = $this->keyFor($owner);

    Event::fake([SessionApiKeysRevoked::class]);

    Auth::guard('web')->logout();

    expect(freshKey($key)?->status)->toBe(ApiKeyStatus::Revoked)
        ->and(freshKey($otherDevice)?->status)->toBe(ApiKeyStatus::Active)
        ->and(freshKey($regular)?->status)->toBe(ApiKeyStatus::Active);

    Event::assertDispatched(SessionApiKeysRevoked::class, fn (SessionApiKeysRevoked $event): bool => $event->reason === SessionEndReason::Logout
        && $event->apiKeyUuids === [(string) $key->uuid]
        && $event->userId === $owner->getKey());

    $this->getJson('/api/v1/projects', $this->credentials($key, $secret))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthorized');
});

it('"sair dos outros aparelhos" revoga as das outras sessões e mantém a desta', function (): void {
    $owner = $this->owner();
    $session = webSessionOf($owner);

    ['api_key' => $here] = sessionKeyFor($this, $owner, $session);
    ['api_key' => $there] = sessionKeyFor($this, $owner, otherDeviceSessionOf($owner));

    Auth::guard('web')->logoutOtherDevices('senha-correta');

    expect(freshKey($here)?->status)->toBe(ApiKeyStatus::Active)
        ->and(freshKey($there)?->status)->toBe(ApiKeyStatus::Revoked);
});

it('troca de senha revoga as das outras sessões na hora; redefinição sem login revoga todas', function (): void {
    $owner = $this->owner();
    $session = webSessionOf($owner);

    ['api_key' => $here] = sessionKeyFor($this, $owner, $session);
    ['api_key' => $there] = sessionKeyFor($this, $owner, otherDeviceSessionOf($owner));

    // Na própria sessão (o perfil).
    $owner->forceFill(['password' => 'senha-nova-123'])->save();

    expect(freshKey($here)?->status)->toBe(ApiKeyStatus::Active)
        ->and(freshKey($there)?->status)->toBe(ApiKeyStatus::Revoked);

    // Sem ninguém logado (redefinição por e-mail).
    Auth::forgetGuards();
    $owner->fresh()?->forceFill(['password' => 'senha-redefinida-1'])->save();

    expect(freshKey($here)?->status)->toBe(ApiKeyStatus::Revoked);
});

it('bloqueio da conta revoga todas as chaves de sessão da pessoa', function (): void {
    $owner = $this->owner();
    $session = webSessionOf($owner);

    ['api_key' => $here] = sessionKeyFor($this, $owner, $session);
    ['api_key' => $there] = sessionKeyFor($this, $owner, otherDeviceSessionOf($owner));
    ['api_key' => $regular] = $this->keyFor($owner);

    Auth::forgetGuards();
    $owner->forceFill(['status' => UserStatus::Blocked])->save();

    expect(freshKey($here)?->status)->toBe(ApiKeyStatus::Revoked)
        ->and(freshKey($there)?->status)->toBe(ApiKeyStatus::Revoked)
        ->and(freshKey($regular)?->status)->toBe(ApiKeyStatus::Active);
});

it('encerramento por recurso próprio do aplicativo: o evento documentado revoga', function (): void {
    $owner = $this->owner();
    ['api_key' => $key] = sessionKeyFor($this, $owner, webSessionOf($owner));

    event(SessionsEnded::allExcept($owner, null, SessionEndReason::Administrative));

    expect(freshKey($key)?->status)->toBe(ApiKeyStatus::Revoked);
});

it('sessão vencida por inatividade: recusada na hora, revogada e apagada pela rotina', function (): void {
    $owner = $this->owner();
    $session = webSessionOf($owner);

    ['api_key' => $key, 'secret_key' => $secret] = sessionKeyFor($this, $owner, $session);
    ['api_key' => $revokedRegular] = $this->keyFor($owner);
    $this->inAccountOf($owner, fn () => app(ApiKeyService::class)->revoke($revokedRegular));

    $this->travel(121)->minutes();

    // Antes da rotina: a autenticação já recusa (defesa em profundidade).
    $this->getJson('/api/v1/projects', $this->credentials($key, $secret))->assertUnauthorized();

    Event::fake([SessionApiKeysRevoked::class]);

    $this->artisan('api-keys:prune-session-keys')
        ->expectsOutputToContain('Chaves de sessão revogadas: 1. Chaves de sessão apagadas: 1.')
        ->assertSuccessful();

    Event::assertDispatched(SessionApiKeysRevoked::class, fn (SessionApiKeysRevoked $event): bool => $event->reason === SessionEndReason::Expired
        && $event->apiKeyUuids === [(string) $key->uuid]);

    // A de sessão saiu do banco; a chave comum revogada continua (histórico).
    expect(freshKey($key))->toBeNull()
        ->and(freshKey($revokedRegular)?->status)->toBe(ApiKeyStatus::Revoked);
});

it('a sessão vista no grupo web mantém a chave viva além do prazo original', function (): void {
    Route::middleware('web')->get('/painel-de-teste', fn (): string => 'painel');

    $owner = $this->owner();
    $session = webSessionOf($owner);
    ['api_key' => $key, 'secret_key' => $secret] = sessionKeyFor($this, $owner, $session);

    $this->travel(100)->minutes();
    $this->actingAs($owner)->get('/painel-de-teste')->assertOk()->assertSee('painel');

    $seen = freshKey($key)?->session_seen_at;
    expect($seen?->greaterThan(now()->subMinute()))->toBeTrue();

    // Outra requisição logo em seguida não escreve de novo (intervalo mínimo).
    $writes = 0;
    DB::listen(function ($query) use (&$writes): void {
        if (str_contains($query->sql, 'session_seen_at') && str_starts_with(strtolower($query->sql), 'update')) {
            $writes++;
        }
    });
    $this->get('/painel-de-teste')->assertOk();
    expect($writes)->toBe(0);

    // 100 + 100 minutos desde a criação, mas só 100 desde a última vez vista.
    $this->travel(100)->minutes();
    $this->getJson('/api/v1/projects', $this->credentials($key, $secret))->assertOk();

    $this->artisan('api-keys:prune-session-keys')->assertSuccessful();
    expect(freshKey($key)?->status)->toBe(ApiKeyStatus::Active);
});

it('a sessão que já venceu não ressuscita a chave', function (): void {
    $owner = $this->owner();
    $session = webSessionOf($owner);
    ['api_key' => $key] = sessionKeyFor($this, $owner, $session);

    $this->travel(121)->minutes();
    $session->forget(SessionApiKeys::SESSION_TOUCHED_AT);

    app(SessionApiKeys::class)->touch($session, $owner);

    expect(freshKey($key)?->isUsable())->toBeFalse();
});

it('pela API, a chave de sessão só cria chave presa à mesma sessão e não rotaciona chave comum', function (): void {
    $owner = $this->owner();
    $session = webSessionOf($owner);

    ['api_key' => $key, 'secret_key' => $secret] = sessionKeyFor($this, $owner, $session, ['scopes' => ['*:*']]);
    ['api_key' => $regular] = $this->keyFor($owner);

    $token = 'token-sessao-'.bin2hex(random_bytes(16));
    DB::table('sensitive_action_tokens')->insert([
        'user_id' => $owner->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $created = $this->postJson('/api/v1/api-keys', ['name' => 'Derivada'], [...$this->credentials($key, $secret), 'X-Sensitive-Action-Token' => $token])
        ->assertCreated()
        ->assertJsonPath('data.session_bound', true);

    $derived = Accounts::asSystem('teste', fn (): ?ApiKey => ApiKey::query()->withSessionKeys()->where('uuid', $created->json('data.uuid'))->first());

    expect($derived?->session_binding)->toBe($key->session_binding);

    // Rotacionar chave comum com a de sessão: a secreta nova duraria mais.
    $token2 = 'token-sessao-'.bin2hex(random_bytes(16));
    DB::table('sensitive_action_tokens')->insert([
        'user_id' => $owner->id,
        'token_hash' => hash('sha256', $token2),
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson("/api/v1/api-keys/{$regular->uuid}/rotate", [], [...$this->credentials($key, $secret), 'X-Sensitive-Action-Token' => $token2])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'api_key_expiry_exceeded');

    // O logout leva as duas.
    Auth::guard('web')->logout();

    expect(freshKey($key)?->status)->toBe(ApiKeyStatus::Revoked)
        ->and($derived !== null ? freshKey($derived)?->status : null)->toBe(ApiKeyStatus::Revoked)
        ->and(freshKey($regular)?->status)->toBe(ApiKeyStatus::Active);
});

it('pessoa excluída: as chaves de sessão dela morrem e a rotina as apaga', function (): void {
    $owner = $this->owner();
    $member = $this->owner();
    $account = $this->accountOf($owner);
    app(AccountService::class)->addMember($account, $member, AccountRole::Admin);

    $session = webSessionOf($member);
    ['api_key' => $key] = Accounts::actingAs($account, fn (): array => app(ApiKeyService::class)->createForSession(
        $member,
        $session,
        ['name' => 'Documentação'],
        confirmedCreation($member),
    ), $member);

    Auth::forgetGuards();
    app(AccountDeletion::class)->deleteUser($member);

    expect(freshKey($key)?->status)->toBe(ApiKeyStatus::Revoked);

    $this->artisan('api-keys:prune-session-keys')->assertSuccessful();

    expect(freshKey($key))->toBeNull();
});

it('a rotina é agendada pelo pacote; cron vazio desliga com aviso', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'api-keys:prune-session-keys'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/10 * * * *');

    $this->bootWith(['api_keys.session_keys.prune_schedule' => '']);

    expect(collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'api-keys:prune-session-keys')))->toHaveCount(0);

    Log::spy();
    (new AccountsServiceProvider($this->app))->boot();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'api-keys:prune-session-keys'));
});
