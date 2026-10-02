<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Exceptions\OwnerOfSharedAccountException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Deletion\AccountDeletion;
use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Deletion\DeletionImpediments;
use Twstec\Kit\Accounts\Deletion\DeletionRequest;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionImpededException;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionOutsideServiceException;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Accounts\Tests\Fixtures\User;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// O CAMINHO ÚNICO DE EXCLUSÃO (Deletion\AccountDeletion), chamado POR CÓDIGO
// — o job, o comando, o serviço do aplicativo (aplicação limpa):
//
// - pergunta aos verificadores ANTES de apagar; recusa limpa, nada sai;
// - registro do aplicativo que aponta para o que sairia (chave estrangeira
//   RESTRICT) que ninguém declarou: a mesma recusa limpa, nunca o erro bruto
//   do banco;
// - a recusa fica na trilha (UMA linha), mesmo quando quem chamou desfaz a
//   transação em volta, em qualquer profundidade;
// - FALHA FECHADA: o `delete()` direto da conta é recusado, com a trilha; o
//   da pessoa continua perguntando no `deleting`.
// =============================================================================

/**
 * Registro do aplicativo que aponta para `$tabelaAlvo` sem ter sido declarado
 * como impedimento. As chaves estrangeiras do SQLite em memória da suíte
 * ficam desligadas dentro da transação do teste: o gatilho dá o MESMO erro que
 * a RESTRICT (a RESTRICT de verdade, no SQLite e no PostgreSQL, é coberta na
 * suíte dos starters).
 */
function registroRestritoApontandoPara(string $tabelaAlvo, int $id): void
{
    $coluna = $tabelaAlvo === 'users' ? 'user_id' : 'account_id';

    Schema::create("registros_restritos_{$tabelaAlvo}", fn (Blueprint $t) => $t->foreignId($coluna));
    DB::table("registros_restritos_{$tabelaAlvo}")->insert([$coluna => $id]);
    DB::unprepared("CREATE TRIGGER registros_restritos_{$tabelaAlvo}_restrict BEFORE DELETE ON {$tabelaAlvo}
        WHEN EXISTS (SELECT 1 FROM registros_restritos_{$tabelaAlvo} WHERE {$coluna} = OLD.id)
        BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END");
}

/**
 * @return list<string>
 */
function recusasDeExclusao(string $acao): array
{
    return AuditEvent::query()->where('action', $acao)->where('outcome', AuditOutcome::Denied)->orderBy('id')->pluck('reason')->all();
}

it('PESSOA por código, com impedimento declarado: recusa limpa, nada sai, UMA linha `denied` na trilha', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    $pessoal = app(AccountService::class)->personalAccountOf($ana);
    app(DeletionImpediments::class)->register(fn (DeletionRequest $pedido): array => [new DeletionImpediment('retained_records', 'Registros guardados por lei.')]);

    expect(fn () => app(AccountDeletion::class)->deleteUser($ana))
        ->toThrow(DeletionImpededException::class, 'Registros guardados por lei.');

    expect(User::query()->whereKey($ana->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey($pessoal->id)->exists())->toBeTrue()
        ->and(recusasDeExclusao('user.deleted'))->toBe(['Registros guardados por lei.']);
});

it('PESSOA por código, com RESTRICT que ninguém declarou: recusa limpa (nunca o erro do banco), nada sai, a recusa na trilha', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    $pessoal = app(AccountService::class)->personalAccountOf($ana);
    registroRestritoApontandoPara('users', (int) $ana->id);

    try {
        app(AccountDeletion::class)->deleteUser($ana);
        $this->fail('A exclusão deveria ter sido recusada.');
    } catch (DeletionImpededException $exception) {
        expect($exception->codes())->toBe([DeletionImpediments::REFERENCED])
            ->and($exception->getMessage())->toBe(__('accounts.deletion.referenced'))
            ->and($exception->getMessage())->not->toContain('FOREIGN KEY');
    }

    expect(User::query()->whereKey($ana->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey($pessoal->id)->exists())->toBeTrue()
        ->and(recusasDeExclusao('user.deleted'))->toBe([__('accounts.deletion.referenced')]);
});

it('PESSOA dona de conta com outros membros: recusada pelo serviço, com a recusa na trilha', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Empresa da Ana', $ana);
    app(AccountService::class)->addMember($empresa, User::fixture(), AccountRole::Member);

    expect(fn () => app(AccountDeletion::class)->deleteUser($ana))->toThrow(OwnerOfSharedAccountException::class);

    expect(User::query()->whereKey($ana->id)->exists())->toBeTrue()
        ->and(recusasDeExclusao('user.deleted'))->toHaveCount(1);
});

it('PESSOA sem impedimento: sai com as contas de que era a única dona — a trava da conta não atrapalha a limpeza', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    $pessoal = app(AccountService::class)->personalAccountOf($ana);
    $empresa = app(AccountService::class)->createAccount('Só da Ana', $ana);

    expect(app(AccountDeletion::class)->deleteUser($ana))->toBeTrue();

    expect(User::query()->whereKey($ana->id)->exists())->toBeFalse()
        ->and(Account::query()->whereKey([$pessoal->id, $empresa->id])->exists())->toBeFalse()
        ->and(recusasDeExclusao('user.deleted'))->toBe([])
        ->and(recusasDeExclusao('account.deleted'))->toBe([]);
});

it('CONTA por código, com impedimento declarado: recusa limpa, nada sai, `denied` na conta', function (): void {
    $dona = User::fixture(['email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Empresa', $dona);
    app(DeletionImpediments::class)->register(fn (DeletionRequest $pedido): array => $pedido->isPersonDeletion() ? [] : [new DeletionImpediment('open_contracts', 'Contratos em vigor.')]);

    expect(fn () => app(AccountDeletion::class)->deleteAccount($empresa))->toThrow(DeletionImpededException::class, 'Contratos em vigor.');

    $recusa = AuditEvent::query()->where('action', 'account.deleted')->sole();

    expect(Account::query()->whereKey($empresa->id)->exists())->toBeTrue()
        ->and($recusa->outcome)->toBe(AuditOutcome::Denied)
        ->and($recusa->tenant_uuid)->toBe((string) $empresa->uuid)
        ->and($recusa->reason)->toBe('Contratos em vigor.');
});

it('CONTA por código, com RESTRICT que ninguém declarou: recusa limpa, nada sai (nem os vínculos), UMA recusa na trilha — mesmo com quem chamou desfazendo transações em volta', function (): void {
    $dona = User::fixture(['email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Empresa referenciada', $dona);
    registroRestritoApontandoPara('accounts', (int) $empresa->id);

    try {
        // Um job que roda dentro de duas transações e desfaz as duas.
        DB::transaction(function () use ($empresa): void {
            DB::transaction(fn () => app(AccountDeletion::class)->deleteAccount($empresa));
        });
        $this->fail('A exclusão deveria ter sido recusada.');
    } catch (DeletionImpededException $exception) {
        expect($exception->codes())->toBe([DeletionImpediments::REFERENCED]);
    }

    expect(Account::query()->whereKey($empresa->id)->exists())->toBeTrue()
        ->and($empresa->memberships()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'account.deleted')->where('tenant_uuid', $empresa->uuid)->pluck('outcome')->map->value->all())
        ->toBe(['denied'])
        ->and(recusasDeExclusao('account.deleted'))->toBe([__('accounts.deletion.referenced')]);
});

it('CONTA por código, sem impedimento: sai com o que é dela, e o sucesso fica na trilha', function (): void {
    $dona = User::fixture(['email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Empresa que sai', $dona);

    app(AccountDeletion::class)->deleteAccount($empresa, $dona);

    $linha = AuditEvent::query()->where('action', 'account.deleted')->sole();

    expect(Account::query()->whereKey($empresa->id)->exists())->toBeFalse()
        ->and($empresa->memberships()->count())->toBe(0)
        ->and($linha->outcome)->toBe(AuditOutcome::Success)
        ->and($linha->tenant_uuid)->toBe((string) $empresa->uuid)
        ->and($linha->actor_uuid)->toBe((string) $dona->uuid);
});

it('a conta PESSOAL não sai pelo serviço: ela sai junto com a pessoa', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    $pessoal = app(AccountService::class)->personalAccountOf($ana);

    expect(fn () => app(AccountDeletion::class)->deleteAccount($pessoal))
        ->toThrow(DeletionImpededException::class, __('accounts.deletion.personal_account'));

    expect(Account::query()->whereKey($pessoal->id)->exists())->toBeTrue()
        ->and(app(AccountDeletion::class)->accountDenial($pessoal))->toBe(__('accounts.deletion.personal_account'))
        ->and(recusasDeExclusao('account.deleted'))->toBe([__('accounts.deletion.personal_account')]);
});

it('FALHA FECHADA: `delete()` direto na conta é recusado antes de qualquer linha sair, com a recusa na trilha', function (string $chamada): void {
    $dona = User::fixture(['email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Empresa', $dona);

    expect(fn () => match ($chamada) {
        'delete' => $empresa->delete(),
        'forceDelete' => $empresa->forceDelete(),
        'removeAccount' => DB::transaction(fn () => app(AccountService::class)->removeAccount($empresa)),
    })->toThrow(DeletionOutsideServiceException::class, __('accounts.deletion.outside_service'));

    $recusa = AuditEvent::query()->where('action', 'account.deleted')->sole();

    expect(Account::query()->whereKey($empresa->id)->exists())->toBeTrue()
        ->and($empresa->memberships()->count())->toBe(1)
        ->and($recusa->outcome)->toBe(AuditOutcome::Denied)
        ->and($recusa->tenant_uuid)->toBe((string) $empresa->uuid);
})->with(['delete', 'forceDelete', 'removeAccount']);

it('FALHA FECHADA: a remoção interna chamada fora do serviço recusa ANTES de apagar os dados da conta', function (): void {
    $dona = User::fixture(['email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Empresa com dados', $dona);
    $projeto = Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Projeto']), $dona);

    // Sem transação em volta: nada pode ter saído antes da recusa.
    expect(fn () => app(AccountService::class)->removeAccount($empresa))->toThrow(DeletionOutsideServiceException::class);

    expect(Accounts::asSystem('teste: conferir o que ficou', fn (): bool => Project::query()->whereKey($projeto->id)->exists()))->toBeTrue()
        ->and(Account::query()->whereKey($empresa->id)->exists())->toBeTrue()
        ->and($empresa->memberships()->count())->toBe(1);
});

it('`$pessoa->delete()` direto continua valendo, perguntando no `deleting` — e a recusa da regra do dono agora também vai para a trilha', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Empresa da Ana', $ana);
    app(AccountService::class)->addMember($empresa, User::fixture(), AccountRole::Member);

    expect(fn () => $ana->delete())->toThrow(OwnerOfSharedAccountException::class);

    expect(User::query()->whereKey($ana->id)->exists())->toBeTrue()
        ->and(recusasDeExclusao('user.deleted'))->toHaveCount(1);

    $bia = User::fixture();
    $bia->delete();

    expect(User::query()->whereKey($bia->id)->exists())->toBeFalse();
});

it('a gravação durável deixa exatamente UMA linha, desfeita uma, duas ou nenhuma transação em volta', function (int $niveis, bool $desfaz): void {
    $grava = fn () => AccountDeletion::recordDurably(fn () => app(AuditTrail::class)->denied('teste.durable', null, 'motivo'));

    $roda = function (int $resta) use (&$roda, $grava, $desfaz): void {
        if ($resta === 0) {
            $grava();

            if ($desfaz) {
                throw new RuntimeException('desfaz');
            }

            return;
        }

        DB::transaction(fn () => $roda($resta - 1));
    };

    try {
        $roda($niveis);
    } catch (RuntimeException) {
    }

    expect(AuditEvent::query()->where('action', 'teste.durable')->count())->toBe(1);
})->with([
    'sem transação' => [0, false],
    'uma, confirmada' => [1, false],
    'uma, desfeita' => [1, true],
    'três, desfeitas' => [3, true],
    'três, confirmadas' => [3, false],
]);
