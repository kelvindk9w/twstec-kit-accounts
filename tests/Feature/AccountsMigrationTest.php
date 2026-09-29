<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Tests\Fixtures\User;

// =============================================================================
// MIGRAÇÃO 1.x → CONTAS, numa aplicação limpa, com dados sintéticos: várias
// pessoas, projetos, chaves, vínculos chave ↔ projeto e a marca de restrição.
//
// Prova: cada pessoa vira dona de uma conta pessoal com o MESMO id e o MESMO
// uuid; projetos e chaves passam para ela com `created_by`; os vínculos e a
// restrição ficam como estavam; rodar de novo não muda nada (idempotente); e
// a ida e volta (up → down → up) devolve exatamente os dados da 1.x.
// =============================================================================

function migracaoDeContas(string $arquivo): Migration
{
    return require dirname(__DIR__, 2).'/database/migrations/'.$arquivo;
}

function criarContas(): Migration
{
    return migracaoDeContas('2026_09_26_000001_create_accounts_tables.php');
}

function moverParaContas(): Migration
{
    return migracaoDeContas('2026_09_26_000002_move_projects_and_api_keys_to_accounts.php');
}

/**
 * Volta o banco ao esquema da 1.x (sem contas) e grava dados sintéticos como
 * a 1.x gravava: projetos e chaves com `user_id`.
 *
 * @return array{users: list<User>, projects: list<array<string, mixed>>, keys: list<array<string, mixed>>, links: list<array<string, mixed>>}
 */
function bancoDaVersaoUm(int $pessoas = 5): array
{
    $users = [];

    for ($i = 0; $i < $pessoas; $i++) {
        $users[] = User::fixture(['email_verified_at' => now()]);
    }

    moverParaContas()->down();
    criarContas()->down();

    expect(Schema::hasTable('accounts'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'user_id'))->toBeTrue();

    $agora = now();

    foreach ($users as $indice => $user) {
        // Pessoas com 0, 1, 2… projetos e chaves (a primeira não tem nada).
        for ($p = 0; $p < $indice; $p++) {
            DB::table('projects')->insert([
                'uuid' => (string) Str::uuid7(),
                'codigo_publico' => 'PRJ-'.strtoupper(Str::random(6)),
                'user_id' => $user->getKey(),
                'name' => "Projeto {$indice}.{$p}",
                'status' => $p % 2 === 0 ? 'active' : 'archived',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }

        for ($k = 0; $k < $indice; $k++) {
            DB::table('api_keys')->insert([
                'uuid' => (string) Str::uuid7(),
                'codigo_publico' => 'KEY-'.strtoupper(Str::random(6)),
                'user_id' => $user->getKey(),
                'name' => "Chave {$indice}.{$k}",
                'public_key' => 'pk_test_'.Str::random(32),
                'secret_hash' => hash('sha256', Str::random()),
                'scopes' => json_encode(['*:*']),
                'restricted_to_projects' => $k === 0,
                'status' => 'active',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }
    }

    // Vínculo: a primeira chave de cada pessoa (restrita) ao primeiro projeto dela.
    foreach ($users as $user) {
        $chave = DB::table('api_keys')->where('user_id', $user->getKey())->where('restricted_to_projects', true)->value('id');
        $projeto = DB::table('projects')->where('user_id', $user->getKey())->orderBy('id')->value('id');

        if ($chave !== null && $projeto !== null) {
            DB::table('api_key_project')->insert(['api_key_id' => $chave, 'project_id' => $projeto, 'created_at' => $agora, 'updated_at' => $agora]);
        }
    }

    return [
        'users' => $users,
        'projects' => DB::table('projects')->orderBy('id')->get(['id', 'uuid', 'user_id', 'name', 'status'])->map(fn ($r) => (array) $r)->all(),
        'keys' => DB::table('api_keys')->orderBy('id')->get(['id', 'uuid', 'user_id', 'name', 'public_key', 'secret_hash', 'restricted_to_projects', 'status'])->map(fn ($r) => (array) $r)->all(),
        'links' => DB::table('api_key_project')->orderBy('id')->get(['api_key_id', 'project_id'])->map(fn ($r) => (array) $r)->all(),
    ];
}

/**
 * @param  array{users: list<User>, projects: list<array<string, mixed>>, keys: list<array<string, mixed>>, links: list<array<string, mixed>>}  $antes
 */
function conferirMigrado(array $antes): void
{
    expect(Schema::hasColumn('projects', 'user_id'))->toBeFalse()
        ->and(Schema::hasColumn('api_keys', 'user_id'))->toBeFalse()
        ->and(DB::table('accounts')->count())->toBe(count($antes['users']))
        ->and(DB::table('account_memberships')->count())->toBe(count($antes['users']));

    foreach ($antes['users'] as $user) {
        $conta = DB::table('accounts')->where('personal_user_id', $user->getKey())->sole();

        // MESMO identificador: id e uuid da pessoa.
        expect((int) $conta->id)->toBe((int) $user->getKey())
            ->and($conta->uuid)->toBe($user->uuid)
            ->and($conta->codigo_publico)->toStartWith('ACC-')
            ->and(DB::table('account_memberships')->where('account_id', $conta->id)->where('user_id', $user->getKey())->value('role'))->toBe('owner');
    }

    foreach ($antes['projects'] as $projeto) {
        $agora = DB::table('projects')->where('id', $projeto['id'])->sole();

        expect((int) $agora->account_id)->toBe((int) $projeto['user_id'])
            ->and((int) $agora->created_by)->toBe((int) $projeto['user_id'])
            ->and([$agora->uuid, $agora->name, $agora->status])->toBe([$projeto['uuid'], $projeto['name'], $projeto['status']]);
    }

    foreach ($antes['keys'] as $chave) {
        $agora = DB::table('api_keys')->where('id', $chave['id'])->sole();

        expect((int) $agora->account_id)->toBe((int) $chave['user_id'])
            ->and((int) $agora->created_by)->toBe((int) $chave['user_id'])
            ->and([$agora->uuid, $agora->public_key, $agora->secret_hash, (bool) $agora->restricted_to_projects, $agora->status])
            ->toBe([$chave['uuid'], $chave['public_key'], $chave['secret_hash'], (bool) $chave['restricted_to_projects'], $chave['status']]);
    }

    expect(DB::table('api_key_project')->orderBy('id')->get(['api_key_id', 'project_id'])->map(fn ($r) => (array) $r)->all())
        ->toBe($antes['links']);
}

it('cada pessoa vira dona de uma conta pessoal com o mesmo id e uuid; projetos, chaves e vínculos passam para ela', function (): void {
    $antes = bancoDaVersaoUm();

    expect(count($antes['projects']))->toBe(10)
        ->and(count($antes['keys']))->toBe(10)
        ->and(count($antes['links']))->toBe(4);

    criarContas()->up();
    moverParaContas()->up();

    conferirMigrado($antes);
});

it('é idempotente: rodar de novo não cria nem muda nada', function (): void {
    $antes = bancoDaVersaoUm();

    criarContas()->up();
    moverParaContas()->up();
    criarContas()->up();
    moverParaContas()->up();

    conferirMigrado($antes);
});

it('ida e volta: up → down devolve os dados da 1.x exatamente; up de novo migra igual', function (): void {
    $antes = bancoDaVersaoUm();

    criarContas()->up();
    moverParaContas()->up();

    moverParaContas()->down();

    expect(Schema::hasColumn('projects', 'account_id'))->toBeFalse()
        ->and(Schema::hasColumn('api_keys', 'created_by'))->toBeFalse()
        ->and(DB::table('projects')->orderBy('id')->get(['id', 'uuid', 'user_id', 'name', 'status'])->map(fn ($r) => (array) $r)->all())->toEqual($antes['projects'])
        ->and(DB::table('api_keys')->orderBy('id')->get(['id', 'uuid', 'user_id', 'name', 'public_key', 'secret_hash', 'restricted_to_projects', 'status'])->map(fn ($r) => (array) $r)->all())->toEqual($antes['keys'])
        ->and(DB::table('api_key_project')->orderBy('id')->get(['api_key_id', 'project_id'])->map(fn ($r) => (array) $r)->all())->toBe($antes['links']);

    criarContas()->down();
    criarContas()->up();
    moverParaContas()->up();

    conferirMigrado($antes);
});

it('id já ocupado por outra conta: a conta pessoal ganha outro id, mas mantém o uuid da pessoa', function (): void {
    $antes = bancoDaVersaoUm(3);
    [$primeira, $segunda] = $antes['users'];

    criarContas()->up();

    // Uma conta que já usa o id da segunda pessoa (base que já tinha contas).
    DB::table('accounts')->insert([
        'id' => $segunda->getKey(),
        'uuid' => (string) Str::uuid7(),
        'codigo_publico' => 'ACC-OCUPAD',
        'name' => 'Já existia',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('account_memberships')->insert([
        'account_id' => $segunda->getKey(), 'user_id' => $primeira->getKey(), 'role' => 'owner',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    moverParaContas()->up();

    $contaDaSegunda = DB::table('accounts')->where('personal_user_id', $segunda->getKey())->sole();

    expect((int) $contaDaSegunda->id)->not->toBe((int) $segunda->getKey())
        ->and($contaDaSegunda->uuid)->toBe($segunda->uuid)
        ->and(DB::table('projects')->where('account_id', $contaDaSegunda->id)->count())->toBe(1)
        ->and(DB::table('projects')->where('account_id', $segunda->getKey())->count())->toBe(0);
});

it('pessoa criada depois da migração ganha a conta pessoal pelo código, com o mesmo uuid', function (): void {
    bancoDaVersaoUm(2);

    criarContas()->up();
    moverParaContas()->up();

    $nova = User::fixture();

    $conta = DB::table('accounts')->where('personal_user_id', $nova->getKey())->sole();

    expect($conta->uuid)->toBe($nova->uuid)
        ->and(DB::table('account_memberships')->where('account_id', $conta->id)->value('role'))->toBe('owner');
});
