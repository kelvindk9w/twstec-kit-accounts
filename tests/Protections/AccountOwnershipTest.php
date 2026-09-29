<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Exceptions\AccountOwnershipException;
use Twstec\Kit\Accounts\Account\Exceptions\OwnerOfSharedAccountException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Models\AccountMembership;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Auth\Enums\UserStatus;

// =============================================================================
// A REGRA DO DONO e a EXCLUSÃO DE PESSOA, numa aplicação limpa.
//
// - Exatamente um dono por conta: segundo dono recusado (código E banco),
//   dono não é rebaixado nem sai, vínculo não muda de conta.
// - Excluir a pessoa: recusado se ela é dona de conta com outros membros
//   (nada muda); senão a conta pessoal sai com os dados (como na 1.x), e nas
//   contas em que era membro ela só deixa de ser membro.
// - A chave de API é da conta: continua valendo quando quem a criou sai da
//   conta — ou é excluído.
// =============================================================================

function servicoDeContas(): AccountService
{
    return app(AccountService::class);
}

it('exatamente um dono: o segundo é recusado pelo código e pelo índice do banco', function (): void {
    $dona = $this->owner();
    $outra = $this->owner();
    $conta = servicoDeContas()->createAccount('Empresa', $dona);

    expect(fn () => servicoDeContas()->addMember($conta, $outra, AccountRole::Owner))->toThrow(AccountOwnershipException::class)
        ->and(fn () => AccountMembership::query()->create(['account_id' => $conta->getKey(), 'user_id' => $outra->getKey(), 'role' => AccountRole::Owner]))
        ->toThrow(AccountOwnershipException::class);

    // Por fora do Eloquent: o índice único parcial recusa.
    expect(fn () => DB::table('account_memberships')->insert([
        'account_id' => $conta->getKey(),
        'user_id' => $outra->getKey(),
        'role' => 'owner',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect($conta->memberships()->where('role', 'owner')->count())->toBe(1)
        ->and($conta->owner?->is($dona))->toBeTrue();
});

it('o dono não é rebaixado, não sai e ninguém vira dono por mudança de papel', function (): void {
    $dona = $this->owner();
    $membro = $this->owner();
    $conta = servicoDeContas()->createAccount('Empresa', $dona);
    servicoDeContas()->addMember($conta, $membro, AccountRole::Admin);

    expect(fn () => servicoDeContas()->changeRole($conta, $dona, AccountRole::Admin))->toThrow(AccountOwnershipException::class)
        ->and(fn () => servicoDeContas()->changeRole($conta, $membro, AccountRole::Owner))->toThrow(AccountOwnershipException::class)
        ->and(fn () => servicoDeContas()->removeMember($conta, $dona))->toThrow(AccountOwnershipException::class);

    $vinculo = $conta->memberships()->where('user_id', $membro->getKey())->sole();
    $vinculo->account_id = servicoDeContas()->personalAccountOf($membro)?->getKey();

    expect(fn () => $vinculo->save())->toThrow(AccountOwnershipException::class);

    // Papel entre admin e member muda normalmente.
    servicoDeContas()->changeRole($conta, $membro, AccountRole::Member);

    expect($conta->roleOf($membro))->toBe(AccountRole::Member)
        ->and($conta->roleOf($dona))->toBe(AccountRole::Owner);
});

it('excluir pessoa DONA de conta com outros membros é recusado — nada muda', function (): void {
    $dona = $this->owner();
    $membro = $this->owner();
    $conta = servicoDeContas()->createAccount('Empresa', $dona);
    servicoDeContas()->addMember($conta, $membro, AccountRole::Member);
    Accounts::actingAs($conta, fn () => Project::createWithPublicCodeRetry(['name' => 'Da empresa']), $dona);
    $this->inAccountOf($dona, fn () => Project::createWithPublicCodeRetry(['name' => 'Pessoal']));

    expect(servicoDeContas()->deletionDenial($dona))->toContain($conta->codigo_publico)
        ->and(servicoDeContas()->deletionDenial($membro))->toBeNull();

    try {
        $dona->delete();
        $this->fail('A exclusão deveria ter sido recusada.');
    } catch (OwnerOfSharedAccountException $e) {
        expect($e->accounts)->toBe([$conta->codigo_publico])
            ->and($e->getMessage())->toContain($conta->codigo_publico);
    }

    expect($dona->fresh())->not->toBeNull()
        ->and(Account::query()->whereKey($conta->getKey())->exists())->toBeTrue()
        ->and(Account::query()->where('personal_user_id', $dona->getKey())->exists())->toBeTrue()
        ->and(Accounts::asSystem('teste', fn () => Project::query()->count()))->toBe(2);
});

it('excluir pessoa sem conta compartilhada: a conta pessoal sai com projetos e chaves, como na 1.x', function (): void {
    $pessoa = $this->owner();
    $vizinha = $this->owner();
    $contaPessoal = $this->accountOf($pessoa);

    $this->inAccountOf($pessoa, fn () => Project::createWithPublicCodeRetry(['name' => 'Meu']));
    $this->keyFor($pessoa);
    $this->inAccountOf($vizinha, fn () => Project::createWithPublicCodeRetry(['name' => 'Da vizinha']));
    $this->keyFor($vizinha);

    $pessoa->delete();

    expect(Account::query()->whereKey($contaPessoal->getKey())->exists())->toBeFalse()
        ->and(AccountMembership::query()->where('user_id', $pessoa->getKey())->exists())->toBeFalse()
        ->and(Accounts::asSystem('teste', fn () => Project::query()->pluck('name')->all()))->toBe(['Da vizinha'])
        ->and(Accounts::asSystem('teste', fn () => ApiKey::query()->count()))->toBe(1)
        ->and(DB::table('api_keys')->where('account_id', $contaPessoal->getKey())->count())->toBe(0);
});

it('excluir pessoa que é só MEMBRO de outra conta: ela sai da conta, a conta e os dados ficam', function (): void {
    $dona = $this->owner();
    $membro = $this->owner();
    $conta = servicoDeContas()->createAccount('Empresa', $dona);
    servicoDeContas()->addMember($conta, $membro, AccountRole::Admin);
    $projeto = Accounts::actingAs($conta, fn () => Project::createWithPublicCodeRetry(['name' => 'Feito pelo membro']), $membro);

    $membro->delete();

    expect($conta->fresh())->not->toBeNull()
        ->and($conta->hasMember($membro))->toBeFalse()
        ->and($conta->memberships()->count())->toBe(1)
        ->and(Accounts::asSystem('teste', fn () => $projeto->fresh()))->not->toBeNull()
        ->and(Accounts::asSystem('teste', fn () => $projeto->fresh()->created_by))->toBeNull();
});

it('a chave é da conta: continua valendo depois que quem a criou SAI da conta — e depois que é excluído', function (): void {
    $dona = $this->owner();
    $admin = $this->owner();
    $conta = servicoDeContas()->createAccount('Empresa', $dona);
    servicoDeContas()->addMember($conta, $admin, AccountRole::Admin);
    Accounts::actingAs($conta, fn () => Project::createWithPublicCodeRetry(['name' => 'Da empresa']), $dona);

    ['api_key' => $chave, 'secret_key' => $segredo] = $this->keyFor($admin, [], $conta);

    expect($chave->created_by)->toBe($admin->getKey())
        ->and($chave->account_id)->toBe($conta->getKey());

    $lista = fn () => collect($this->getJson('/api/v1/projects', $this->credentials($chave, $segredo))->assertOk()->json('data'))->pluck('name')->all();

    expect($lista())->toBe(['Da empresa']);

    // Sai da conta: a chave segue valendo, na mesma conta.
    servicoDeContas()->removeMember($conta, $admin);

    expect($lista())->toBe(['Da empresa'])
        ->and(Accounts::asSystem('teste', fn () => $chave->fresh()->created_by))->toBe($admin->getKey());

    // Excluído: a chave segue valendo; `created_by` fica vazio.
    $admin->delete();

    expect($lista())->toBe(['Da empresa'])
        ->and(Accounts::asSystem('teste', fn () => $chave->fresh()->created_by))->toBeNull();
});

it('a pessoa por trás da chave é quem criou enquanto for membro; depois, o dono da conta', function (): void {
    Route::get('/api/v1/_quem', fn () => response()->json([
        'pessoa' => request()->user()?->uuid,
        'tenant' => tenant()?->uuid,
    ]))->middleware('resolve.tenant');

    $dona = $this->owner();
    $admin = $this->owner();
    $conta = servicoDeContas()->createAccount('Empresa', $dona);
    servicoDeContas()->addMember($conta, $admin, AccountRole::Admin);
    ['api_key' => $chave, 'secret_key' => $segredo] = $this->keyFor($admin, [], $conta);

    $this->getJson('/api/v1/_quem', $this->credentials($chave, $segredo))
        ->assertOk()
        ->assertExactJson(['pessoa' => $admin->uuid, 'tenant' => $conta->uuid]);

    servicoDeContas()->removeMember($conta, $admin);

    $this->getJson('/api/v1/_quem', $this->credentials($chave, $segredo))
        ->assertOk()
        ->assertExactJson(['pessoa' => $dona->uuid, 'tenant' => $conta->uuid]);
});

it('dono da conta bloqueado ou sem e-mail confirmado derruba as chaves da conta (401), como na 1.x', function (): void {
    $dona = $this->owner();
    $admin = $this->owner();
    $conta = servicoDeContas()->createAccount('Empresa', $dona);
    servicoDeContas()->addMember($conta, $admin, AccountRole::Admin);
    ['api_key' => $chave, 'secret_key' => $segredo] = $this->keyFor($admin, [], $conta);

    $dona->forceFill(['status' => UserStatus::Blocked->value])->save();

    $this->getJson('/api/v1/projects', $this->credentials($chave, $segredo))->assertUnauthorized();

    $dona->forceFill(['status' => UserStatus::Active->value, 'email_verified_at' => null])->save();

    $this->getJson('/api/v1/projects', $this->credentials($chave, $segredo))->assertUnauthorized();

    $dona->forceFill(['email_verified_at' => now()])->save();

    $this->getJson('/api/v1/projects', $this->credentials($chave, $segredo))->assertOk();
});
