<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Exceptions\CrossAccountWriteException;
use Twstec\Kit\Accounts\Account\Exceptions\MissingAccountContextException;
use Twstec\Kit\Accounts\Account\Http\Middleware\ResolveCurrentAccount;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\AccountsServiceProvider;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Accounts\Tests\Fixtures\DeclaresSystemModeForRequest;
use Twstec\Kit\Accounts\Tests\Fixtures\User;

// =============================================================================
// ISOLAMENTO AUTOMÁTICO numa aplicação Laravel LIMPA (nada do starter): o
// escopo da conta atual vem do pacote e vale sozinho.
//
// - Sem conta atual e fora do modo sistema: EXCEÇÃO (nunca devolve tudo).
// - Modo sistema: explícito, com motivo, vê todas as contas, e é desfeito
//   mesmo quando o callback lança.
// - Uma pessoa em DUAS contas não enxerga dados de uma na outra — pela web
//   (conta selecionada na sessão, padrão a pessoal) e pela API (a conta da
//   chave).
// - Gravação: só na conta atual; a conta de um registro não muda.
// =============================================================================

/**
 * Conta de empresa de $dono, com $membro dentro (papel dado).
 */
function contaDeEmpresa(User $dono, ?User $membro = null, AccountRole $papel = AccountRole::Member): Account
{
    $conta = app(AccountService::class)->createAccount('Empresa '.bin2hex(random_bytes(2)), $dono);

    if ($membro !== null) {
        app(AccountService::class)->addMember($conta, $membro, $papel);
    }

    return $conta;
}

beforeEach(function (): void {
    // Rota WEB de teste: lista os projetos da conta atual, como uma tela
    // qualquer do aplicativo faria (sem filtro nenhum no código da rota).
    Route::middleware('web')->get('/_contas/projetos', fn () => response()->json(
        Project::query()->orderBy('name')->pluck('name')->all(),
    ));
});

it('toda pessoa criada ganha a conta pessoal, com o MESMO uuid, como dona', function (): void {
    $pessoa = $this->owner();
    $conta = $this->accountOf($pessoa);

    expect($conta->uuid)->toBe($pessoa->uuid)
        ->and($conta->isPersonal())->toBeTrue()
        ->and($conta->codigo_publico)->toStartWith('ACC-')
        ->and($conta->roleOf($pessoa))->toBe(AccountRole::Owner)
        ->and($conta->owner?->is($pessoa))->toBeTrue()
        ->and($conta->memberships()->count())->toBe(1);
});

it('consulta de dado de conta SEM conta atual lança exceção — nunca devolve tudo', function (): void {
    $ana = $this->owner();
    $this->inAccountOf($ana, fn () => Project::createWithPublicCodeRetry(['name' => 'Da Ana']));

    expect(fn () => Project::query()->get())->toThrow(MissingAccountContextException::class)
        ->and(fn () => Project::query()->count())->toThrow(MissingAccountContextException::class)
        ->and(fn () => ApiKey::query()->exists())->toThrow(MissingAccountContextException::class)
        ->and(fn () => Project::query()->update(['name' => 'x']))->toThrow(MissingAccountContextException::class)
        ->and(fn () => Project::query()->delete())->toThrow(MissingAccountContextException::class)
        ->and(fn () => Accounts::currentOrFail())->toThrow(MissingAccountContextException::class);

    // Nada foi alterado pelas tentativas.
    expect(Accounts::asSystem('teste', fn () => Project::query()->pluck('name')->all()))->toBe(['Da Ana']);
});

it('o modo sistema é explícito (exige motivo), vê todas as contas e é desfeito mesmo com exceção', function (): void {
    $ana = $this->owner();
    $bruno = $this->owner();
    $this->inAccountOf($ana, fn () => Project::createWithPublicCodeRetry(['name' => 'A']));
    $this->inAccountOf($bruno, fn () => Project::createWithPublicCodeRetry(['name' => 'B']));

    expect(fn () => Accounts::asSystem('', fn () => 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Accounts::asSystem('   ', fn () => 1))->toThrow(InvalidArgumentException::class);

    $visto = Accounts::asSystem('teste: relatório', fn () => [
        Accounts::inSystemMode(),
        Accounts::systemReason(),
        Project::query()->orderBy('name')->pluck('name')->all(),
    ]);

    expect($visto)->toBe([true, 'teste: relatório', ['A', 'B']])
        ->and(Accounts::inSystemMode())->toBeFalse();

    expect(fn () => Accounts::asSystem('teste', function (): void {
        throw new RuntimeException('falhou no meio');
    }))->toThrow(RuntimeException::class);

    // O modo sistema não sobra depois da exceção.
    expect(Accounts::inSystemMode())->toBeFalse()
        ->and(fn () => Project::query()->count())->toThrow(MissingAccountContextException::class);

    // Dentro do modo sistema, uma conta explícita volta a filtrar.
    expect(Accounts::asSystem('teste', fn () => Accounts::actingAs($this->accountOf($ana), fn () => Project::query()->pluck('name')->all())))
        ->toBe(['A']);
});

it('pessoa em DUAS contas: pela web vê só a conta selecionada (padrão a pessoal) e nunca a outra', function (): void {
    $dona = $this->owner();
    $pessoa = $this->owner();
    $empresa = contaDeEmpresa($dona, $pessoa);

    $this->inAccountOf($pessoa, fn () => Project::createWithPublicCodeRetry(['name' => 'Pessoal da pessoa']));
    Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Da empresa']), $dona);
    $this->inAccountOf($dona, fn () => Project::createWithPublicCodeRetry(['name' => 'Pessoal da dona']));

    // Sem seleção: a conta pessoal.
    $this->actingAs($pessoa)->getJson('/_contas/projetos')->assertOk()->assertExactJson(['Pessoal da pessoa']);

    // Selecionada a empresa (de que é membro): só a empresa.
    $this->actingAs($pessoa)
        ->withSession([CurrentAccount::sessionKey() => $empresa->uuid])
        ->getJson('/_contas/projetos')
        ->assertOk()
        ->assertExactJson(['Da empresa']);

    // Selecionar conta de que NÃO é membro não abre nada: volta à pessoal e a
    // seleção inválida sai da sessão.
    $this->actingAs($pessoa)
        ->withSession([CurrentAccount::sessionKey() => $this->accountOf($dona)->uuid])
        ->getJson('/_contas/projetos')
        ->assertOk()
        ->assertExactJson(['Pessoal da pessoa'])
        ->assertSessionMissing(CurrentAccount::sessionKey());

    // Sem ninguém logado: a consulta não devolve dado de ninguém (a exceção
    // do escopo vira erro, nunca uma lista).
    $this->app['auth']->forgetGuards();

    $this->getJson('/_contas/projetos')->assertServerError();
});

it('saiu da empresa: a seleção guardada deixa de valer na requisição seguinte', function (): void {
    $dona = $this->owner();
    $pessoa = $this->owner();
    $empresa = contaDeEmpresa($dona, $pessoa);
    Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Da empresa']), $dona);

    app(AccountService::class)->removeMember($empresa, $pessoa);

    $this->actingAs($pessoa)
        ->withSession([CurrentAccount::sessionKey() => $empresa->uuid])
        ->getJson('/_contas/projetos')
        ->assertOk()
        ->assertExactJson([]);
});

it('pessoa em DUAS contas: pela API cada chave vê só a conta dela', function (): void {
    $dona = $this->owner();
    $pessoa = $this->owner();
    $empresa = contaDeEmpresa($dona, $pessoa, AccountRole::Admin);

    $this->inAccountOf($pessoa, fn () => Project::createWithPublicCodeRetry(['name' => 'Pessoal']));
    $daEmpresa = Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Da empresa']), $dona);

    ['api_key' => $chavePessoal, 'secret_key' => $s1] = $this->keyFor($pessoa);
    ['api_key' => $chaveEmpresa, 'secret_key' => $s2] = $this->keyFor($pessoa, [], $empresa);

    expect(collect($this->getJson('/api/v1/projects', $this->credentials($chavePessoal, $s1))->assertOk()->json('data'))->pluck('name')->all())
        ->toBe(['Pessoal']);
    expect(collect($this->getJson('/api/v1/projects', $this->credentials($chaveEmpresa, $s2))->assertOk()->json('data'))->pluck('name')->all())
        ->toBe(['Da empresa']);

    // A chave pessoal não alcança o projeto da empresa (404 uniforme) nem as
    // chaves da empresa.
    $this->getJson("/api/v1/projects/{$daEmpresa->uuid}", $this->credentials($chavePessoal, $s1))->assertNotFound();
    $this->deleteJson("/api/v1/api-keys/{$chaveEmpresa->uuid}", [], $this->credentials($chavePessoal, $s1))->assertNotFound();

    expect(collect($this->getJson('/api/v1/api-keys', $this->credentials($chavePessoal, $s1))->assertOk()->json('data'))->pluck('uuid')->all())
        ->toBe([$chavePessoal->uuid]);

    // O tenant da chave da empresa é a EMPRESA (e o request log leva o uuid dela).
    expect(Accounts::asSystem('teste', fn () => $chaveEmpresa->fresh()->account_id))->toBe($empresa->getKey());
});

it('o contexto da API não sobra depois da requisição', function (): void {
    $ana = $this->owner();
    ['api_key' => $chave, 'secret_key' => $segredo] = $this->keyFor($ana);

    $this->getJson('/api/v1/projects', $this->credentials($chave, $segredo))->assertOk();

    expect(tenant())->toBeNull()
        ->and(tenantKey())->toBeNull()
        ->and(Accounts::current())->toBeNull();
});

it('grava só na conta atual: outra conta é recusada, e a conta de um registro não muda', function (): void {
    $ana = $this->owner();
    $bruno = $this->owner();

    expect(fn () => $this->inAccountOf($ana, fn () => Project::createWithPublicCodeRetry([
        'account_id' => $this->accountOf($bruno)->getKey(),
        'name' => 'Intruso',
    ])))->toThrow(CrossAccountWriteException::class);

    // Sem conta: nem grava.
    expect(fn () => Project::createWithPublicCodeRetry(['name' => 'Sem conta']))->toThrow(MissingAccountContextException::class);

    // Em modo sistema, só com a conta informada.
    expect(fn () => Accounts::asSystem('teste', fn () => Project::createWithPublicCodeRetry(['name' => 'Sem conta'])))
        ->toThrow(MissingAccountContextException::class);

    $projeto = $this->inAccountOf($ana, fn () => Project::createWithPublicCodeRetry(['name' => 'Da Ana']));

    expect($projeto->account_id)->toBe($this->accountOf($ana)->getKey())
        ->and($projeto->created_by)->toBe($ana->getKey());

    $projeto->account_id = $this->accountOf($bruno)->getKey();

    expect(fn () => $projeto->save())->toThrow(CrossAccountWriteException::class)
        ->and(Accounts::asSystem('teste', fn () => Project::query()->where('account_id', $this->accountOf($bruno)->getKey())->count()))->toBe(0);

    expect(Accounts::asSystem('teste', fn () => Project::query()->pluck('name')->all()))->toBe(['Da Ana']);
});

it('o pacote instala sozinho o middleware de conta atual no grupo web, depois da sessão', function (): void {
    $grupo = app(Kernel::class)->getMiddlewareGroups()['web'];

    expect(array_count_values($grupo)[ResolveCurrentAccount::class] ?? 0)->toBe(1)
        ->and(array_search(ResolveCurrentAccount::class, $grupo, true))
        ->toBeGreaterThan(array_search(StartSession::class, $grupo, true));
});

it('opt-out explícito do middleware web: não é instalado e o boot avisa no log; o isolamento continua', function (): void {
    $this->bootWith(['accounts.web.middleware' => false]);

    $this->getJson('/nao-existe')->assertNotFound();

    expect(app(Kernel::class)->getMiddlewareGroups()['web'])->not->toContain(ResolveCurrentAccount::class)
        ->and(fn () => Project::query()->count())->toThrow(MissingAccountContextException::class);

    Log::spy();

    (new AccountsServiceProvider($this->app))->boot();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_starts_with($message, 'ACCOUNTS_WEB_MIDDLEWARE=false'));
});

it('papéis fixos: a matriz do dono, do admin e do membro, também no Gate', function (): void {
    $dona = $this->owner();
    $admin = $this->owner();
    $membro = $this->owner();
    $empresa = contaDeEmpresa($dona);
    app(AccountService::class)->addMember($empresa, $admin, AccountRole::Admin);
    app(AccountService::class)->addMember($empresa, $membro, AccountRole::Member);

    $pode = fn (User $quem, $acao): bool => Accounts::actingAs($empresa, fn (): bool => Accounts::can($acao, $quem), $quem);
    $A = AccountAbility::class;

    foreach ($A::cases() as $acao) {
        expect($pode($dona, $acao))->toBeTrue("dono: {$acao->value}");
    }

    expect($pode($admin, $A::ManageApiKeys))->toBeTrue()
        ->and($pode($admin, $A::DeleteProjects))->toBeTrue()
        ->and($pode($admin, $A::ManageMembers))->toBeTrue()
        ->and($pode($admin, $A::TransferOwnership))->toBeFalse()
        ->and($pode($admin, $A::DeleteAccount))->toBeFalse()
        ->and($pode($membro, $A::View))->toBeTrue()
        ->and($pode($membro, $A::CreateProjects))->toBeTrue()
        ->and($pode($membro, $A::UpdateProjects))->toBeTrue()
        ->and($pode($membro, $A::DeleteProjects))->toBeFalse()
        ->and($pode($membro, $A::ManageApiKeys))->toBeFalse()
        ->and($pode($membro, $A::ManageMembers))->toBeFalse();

    // Quem não é membro não pode nada.
    expect($pode($this->owner(), $A::View))->toBeFalse();

    // No Gate, sempre na conta atual.
    expect(Accounts::actingAs($empresa, fn () => Gate::forUser($membro)->allows('accounts.api-keys.manage')))->toBeFalse()
        ->and(Accounts::actingAs($empresa, fn () => Gate::forUser($admin)->allows('accounts.api-keys.manage')))->toBeTrue();

    expect(fn () => Accounts::actingAs($empresa, fn () => Accounts::authorize($A::ManageApiKeys, $membro)))
        ->toThrow(AuthorizationException::class);
});

it('trocar de conta pela sessão só vale para conta de que a pessoa é membro', function (): void {
    $dona = $this->owner();
    $pessoa = $this->owner();
    $empresa = contaDeEmpresa($dona, $pessoa);

    $this->actingAs($pessoa);

    Accounts::switchTo($empresa);
    expect(session(CurrentAccount::sessionKey()))->toBe($empresa->uuid)
        ->and(Accounts::current()?->is($empresa))->toBeTrue();

    expect(fn () => Accounts::switchTo($this->accountOf($dona)))->toThrow(AuthorizationException::class)
        ->and(Accounts::current()?->is($empresa))->toBeTrue();
});

it('modo sistema da REQUISIÇÃO (middleware de área, como o /admin): exige motivo, vale até o fim da requisição e é desfeito sozinho', function (): void {
    $ana = $this->owner();
    $bruno = $this->owner();
    $this->inAccountOf($ana, fn () => Project::createWithPublicCodeRetry(['name' => 'A']));
    $this->inAccountOf($bruno, fn () => Project::createWithPublicCodeRetry(['name' => 'B']));

    expect(fn () => Accounts::systemModeForRequest(''))->toThrow(InvalidArgumentException::class);

    // Um middleware que declara o modo sistema e segue — sem envolver o $next.
    Route::middleware(['web', DeclaresSystemModeForRequest::class])->get('/_operacao/projetos', fn () => response()->json([
        'motivo' => Accounts::systemReason(),
        'projetos' => Project::query()->orderBy('name')->pluck('name')->all(),
        // Uma conta explícita por cima continua filtrando.
        'da_ana' => Accounts::actingAs($this->accountOf($ana), fn () => Project::query()->pluck('name')->all()),
    ]));

    $this->actingAs($ana)->getJson('/_operacao/projetos')
        ->assertOk()
        ->assertExactJson(['motivo' => 'área de operação', 'projetos' => ['A', 'B'], 'da_ana' => ['A']]);

    // Acabou a requisição: nada sobra — a mesma pessoa volta a ver só a conta dela.
    expect(Accounts::inSystemMode())->toBeFalse();

    $this->actingAs($ana)->getJson('/_contas/projetos')->assertOk()->assertExactJson(['A']);
});
