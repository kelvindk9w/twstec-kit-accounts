<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Queue\AccountJobContext;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;

// =============================================================================
// CACHE DO PAPEL POR REQUISIÇÃO: a tela pergunta o papel várias vezes (um
// botão por ação); o banco é consultado UMA vez por conta + pessoa na
// requisição. Papel velho nunca vale: o cache some quando um vínculo muda
// (na mesma requisição), no fim de cada requisição e a cada job da fila.
// =============================================================================

/**
 * Quantas consultas a `account_memberships` o callback fez.
 */
function consultasAosVinculos(Closure $callback): int
{
    $consultas = 0;

    DB::listen(function ($query) use (&$consultas): void {
        if (str_contains($query->sql, 'account_memberships')) {
            $consultas++;
        }
    });

    $callback();

    return $consultas;
}

beforeEach(function (): void {
    $this->dono = $this->owner();
    $this->pessoa = $this->owner();
    $this->empresa = app(AccountService::class)->createAccount('Empresa', $this->dono);
    app(AccountService::class)->addMember($this->empresa, $this->pessoa, AccountRole::Member);

    // A tela: pergunta o papel seis vezes, como a página da conta.
    Route::middleware('web')->get('/_papel', fn () => response()->json([
        'papel' => Accounts::roleOf()?->value,
        'pode' => array_map(fn (AccountAbility $habilidade): bool => Accounts::can($habilidade), [
            AccountAbility::View,
            AccountAbility::ManageMembers,
            AccountAbility::ManageApiKeys,
            AccountAbility::UpdateAccount,
            AccountAbility::DeleteProjects,
        ]),
    ]));
});

it('numa requisição, o papel é consultado no banco uma vez só', function (): void {
    $sessao = [CurrentAccount::sessionKey() => (string) $this->empresa->uuid];

    $consultas = consultasAosVinculos(function () use ($sessao): void {
        $this->actingAs($this->pessoa)->withSession($sessao)->getJson('/_papel')
            ->assertOk()
            ->assertJsonPath('papel', 'member')
            ->assertJsonPath('pode', [true, false, false, false, false]);
    });

    // A mesma requisição sem perguntar o papel: só a resolução da conta
    // atual (a seleção da sessão confere o vínculo).
    Route::middleware('web')->get('/_conta', fn () => response()->json(['conta' => Accounts::current()?->uuid]));

    $base = consultasAosVinculos(function () use ($sessao): void {
        $this->actingAs($this->pessoa)->withSession($sessao)->getJson('/_conta')->assertOk();
    });

    // Sem o cache seriam seis (uma por pergunta).
    $semCache = consultasAosVinculos(function (): void {
        foreach (range(1, 6) as $_) {
            $this->empresa->roleOf($this->pessoa);
        }
    });

    expect($semCache)->toBe(6)
        ->and($consultas - $base)->toBe(1);
});

it('o papel que muda na requisição vale na hora (o vínculo mudou, o cache some)', function (): void {
    Accounts::actingAs($this->empresa, function (): void {
        expect(Accounts::roleOf($this->pessoa))->toBe(AccountRole::Member)
            ->and(Accounts::can(AccountAbility::ManageMembers, $this->pessoa))->toBeFalse();

        app(AccountService::class)->changeRole($this->empresa, $this->pessoa, AccountRole::Admin);

        expect(Accounts::roleOf($this->pessoa))->toBe(AccountRole::Admin)
            ->and(Accounts::can(AccountAbility::ManageMembers, $this->pessoa))->toBeTrue();

        app(AccountService::class)->removeMember($this->empresa, $this->pessoa);

        expect(Accounts::roleOf($this->pessoa))->toBeNull()
            ->and(Accounts::can(AccountAbility::View, $this->pessoa))->toBeFalse();
    }, $this->dono);
});

it('o papel NÃO atravessa requisições: mudado por fora, a próxima requisição vê o novo', function (): void {
    $sessao = [CurrentAccount::sessionKey() => (string) $this->empresa->uuid];

    $this->actingAs($this->pessoa)->withSession($sessao)->getJson('/_papel')->assertJsonPath('papel', 'member');

    // Outro processo promove a pessoa (sem passar pelo model).
    DB::table('account_memberships')->where('account_id', $this->empresa->id)->where('user_id', $this->pessoa->id)->update(['role' => 'admin']);

    $this->actingAs($this->pessoa)->withSession($sessao)->getJson('/_papel')
        ->assertJsonPath('papel', 'admin')
        ->assertJsonPath('pode.1', true);

    // E rebaixada por fora de novo: a requisição seguinte volta a member.
    DB::table('account_memberships')->where('account_id', $this->empresa->id)->where('user_id', $this->pessoa->id)->update(['role' => 'member']);

    $this->actingAs($this->pessoa)->withSession($sessao)->getJson('/_papel')->assertJsonPath('papel', 'member');
});

it('vínculos que saem em massa (exclusão da conta) também apagam o papel guardado', function (): void {
    Accounts::actingAs($this->empresa, function (): void {
        expect(Accounts::roleOf($this->pessoa))->toBe(AccountRole::Member);
    });

    app(AccountService::class)->deleteAccount($this->empresa);

    expect((fn (): array => $this->roles)->call(app(CurrentAccount::class)))->toBe([]);
});

it('cada job da fila começa sem papel guardado (o worker é um processo longo)', function (): void {
    $contexto = app(CurrentAccount::class);

    Accounts::actingAs($this->empresa, fn () => Accounts::roleOf($this->pessoa));

    expect((fn (): array => $this->roles)->call($contexto))->not->toBe([]);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);

    app(AccountJobContext::class)->restore($job);

    expect((fn (): array => $this->roles)->call($contexto))->toBe([]);

    app(AccountJobContext::class)->release($job);
});
