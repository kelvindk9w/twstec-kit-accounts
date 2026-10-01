<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Twstec\Kit\Accounts\Account\Actions\DeleteAccount;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Deletion\Contracts\DeletionCheck;
use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Deletion\DeletionImpediments;
use Twstec\Kit\Accounts\Deletion\DeletionRequest;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionImpededException;
use Twstec\Kit\Accounts\Tests\Fixtures\User;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Services\SensitiveActionService;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// IMPEDIMENTOS DE EXCLUSÃO — o ponto de extensão para o aplicativo dizer se e
// por que uma pessoa ou uma conta não pode ser excluída agora (aplicação
// limpa):
//
// - verificador declarado (configuração, classe registrada ou Closure) é
//   perguntado ANTES de apagar: havendo impedimento, a exclusão é recusada
//   inteira — mensagem traduzida, nada apagado pela metade, recusa na trilha
//   (mesmo quando quem chamou desfaz a transação em volta);
// - a pré-checagem das telas (deletionDenial / authorize) devolve o mesmo
//   motivo antes de pedir a confirmação;
// - registro do aplicativo com chave estrangeira RESTRICT que ninguém
//   declarou: a exclusão da conta vira a mesma recusa limpa, nunca o erro
//   bruto do banco.
// =============================================================================

/**
 * Um registro "do aplicativo" que a lei manda guardar e que aponta para a
 * conta — o verificador do exemplo da documentação.
 */
final class LancamentosGuardadosImpedemExclusao implements DeletionCheck
{
    public function impediments(DeletionRequest $request): iterable
    {
        $total = DB::table('lancamentos_de_teste')->whereIn('account_id', $request->accountIds())->count();

        if ($total > 0) {
            yield new DeletionImpediment('ledger_entries', "Há {$total} lançamento(s) que a lei manda guardar.");
        }
    }
}

function criaTabelaDeLancamentos(bool $restrita = false): void
{
    Schema::create('lancamentos_de_teste', function (Blueprint $tabela) use ($restrita): void {
        $tabela->id();
        $chave = $tabela->foreignId('account_id');

        if ($restrita) {
            $chave->constrained('accounts')->restrictOnDelete();
        }
    });
}

function tokenDeExclusao(User $pessoa): string
{
    test()->travel(2)->minutes();
    Mail::fake();
    $pessoa->forceFill(['transaction_password' => 'Trans4cao!Segura'])->save();

    app(SensitiveActionService::class)->sendCode($pessoa, 'Trans4cao!Segura');

    $codigo = null;
    Mail::assertQueued(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$codigo): bool {
        $codigo = $mail->code;

        return true;
    });

    return app(SensitiveActionService::class)->confirmCode($pessoa, (string) $codigo)['token'];
}

it('verificador DECLARADO NA CONFIGURAÇÃO recusa a exclusão da PESSOA inteira: nada sai, mensagem traduzida, recusa na trilha', function (): void {
    criaTabelaDeLancamentos();
    config(['accounts.deletion.checks' => [LancamentosGuardadosImpedemExclusao::class]]);

    $ana = User::fixture(['email_verified_at' => now()]);
    $pessoal = app(AccountService::class)->personalAccountOf($ana);
    DB::table('lancamentos_de_teste')->insert(['account_id' => $pessoal->id]);

    try {
        $ana->delete();
        $this->fail('A exclusão deveria ter sido recusada.');
    } catch (DeletionImpededException $exception) {
        expect($exception->codes())->toBe(['ledger_entries'])
            ->and($exception->getMessage())->toBe('Há 1 lançamento(s) que a lei manda guardar.');
    }

    expect(User::query()->whereKey($ana->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey($pessoal->id)->exists())->toBeTrue()
        ->and(app(AccountService::class)->deletionDenial($ana))->toBe('Há 1 lançamento(s) que a lei manda guardar.');

    $recusa = AuditEvent::query()->where('action', 'user.deleted')->sole();

    expect($recusa->outcome)->toBe(AuditOutcome::Denied)
        ->and($recusa->subject_uuid)->toBe((string) $ana->uuid)
        ->and($recusa->reason)->toBe('Há 1 lançamento(s) que a lei manda guardar.');
});

it('a recusa fica na trilha MESMO quando quem chamou desfaz a transação em volta', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    app(DeletionImpediments::class)->register(fn (DeletionRequest $pedido): array => [new DeletionImpediment('contract_in_force', 'Contrato vigente.')]);

    try {
        DB::transaction(fn () => $ana->delete());
    } catch (DeletionImpededException) {
    }

    expect(User::query()->whereKey($ana->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'user.deleted')->where('outcome', AuditOutcome::Denied)->count())->toBe(1);
});

it('sem impedimento, a exclusão segue como sempre (o ponto de extensão não muda nada sozinho)', function (): void {
    criaTabelaDeLancamentos();
    config(['accounts.deletion.checks' => [LancamentosGuardadosImpedemExclusao::class]]);

    $ana = User::fixture(['email_verified_at' => now()]);
    $ana->delete();

    expect(User::query()->whereKey($ana->id)->exists())->toBeFalse()
        ->and(app(DeletionImpediments::class)->check(DeletionRequest::forAccount(Account::query()->firstOrNew())))->toBe([]);
});

it('o verificador recebe a PESSOA e as CONTAS que sairiam junto; exceção dele para a exclusão (falha fechada)', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Só da Ana', $ana);
    $visto = null;

    app(DeletionImpediments::class)->register(function (DeletionRequest $pedido) use (&$visto): array {
        $visto = $pedido;

        throw new RuntimeException('verificador fora do ar');
    });

    expect(fn () => $ana->delete())->toThrow(RuntimeException::class, 'verificador fora do ar');

    expect(User::query()->whereKey($ana->id)->exists())->toBeTrue()
        ->and($visto->isPersonDeletion())->toBeTrue()
        ->and($visto->person->getKey())->toBe($ana->getKey())
        ->and($visto->accountIds())->toEqualCanonicalizing([(int) app(AccountService::class)->personalAccountOf($ana)->id, (int) $empresa->id]);
});

it('EXCLUIR A CONTA (Action do painel): a pré-checagem recusa ANTES de pedir o código, com erro no campo e `denied` na trilha; nada sai', function (): void {
    criaTabelaDeLancamentos();
    config(['accounts.deletion.checks' => [LancamentosGuardadosImpedemExclusao::class]]);

    $dona = User::fixture(['email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Empresa com lançamentos', $dona);
    DB::table('lancamentos_de_teste')->insert(['account_id' => $empresa->id]);
    $this->actingAs($dona);

    try {
        Accounts::actingAs($empresa, fn () => app(DeleteAccount::class)->authorize($dona), $dona);
        $this->fail('A pré-checagem deveria ter recusado.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['account' => ['Há 1 lançamento(s) que a lei manda guardar.']]);
    }

    $token = tokenDeExclusao($dona);

    expect(fn () => Accounts::actingAs($empresa, fn () => app(DeleteAccount::class)->handle($dona, $token), $dona))
        ->toThrow(ValidationException::class);

    expect(Account::query()->whereKey($empresa->id)->exists())->toBeTrue()
        ->and(app(AccountService::class)->accountDeletionDenial($empresa))->toBe('Há 1 lançamento(s) que a lei manda guardar.')
        ->and(AuditEvent::query()->where('action', 'account.deleted')->where('tenant_uuid', $empresa->uuid)->pluck('outcome')->map->value->all())
        ->toBe(['denied', 'denied'])
        // O token não foi gasto: a recusa veio antes.
        ->and(app(SensitiveActionService::class)->validateToken($dona, $token))->toBeTrue();
});

it('AccountService::deleteAccount também pergunta antes (quem chama por código não passa por cima)', function (): void {
    $dona = User::fixture(['email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Empresa', $dona);
    app(DeletionImpediments::class)->register(fn (DeletionRequest $pedido): array => $pedido->isPersonDeletion() ? [] : [new DeletionImpediment('open_invoices', 'Faturas em aberto.')]);

    expect(fn () => app(AccountService::class)->deleteAccount($empresa))->toThrow(DeletionImpededException::class, 'Faturas em aberto.');

    expect(Account::query()->whereKey($empresa->id)->exists())->toBeTrue();
});

it('registro do aplicativo com chave estrangeira RESTRICT que NINGUÉM declarou: recusa limpa, transação desfeita, nunca o erro bruto do banco', function (): void {
    // As chaves estrangeiras do SQLite em memória da suíte ficam desligadas
    // (e não ligam dentro da transação do teste): o gatilho reproduz a recusa
    // do banco com o mesmo erro que a RESTRICT dá. A RESTRICT de verdade, no
    // SQLite e no PostgreSQL, é coberta na suíte do starter.
    criaTabelaDeLancamentos();
    DB::unprepared("CREATE TRIGGER lancamentos_restrict BEFORE DELETE ON accounts
        WHEN EXISTS (SELECT 1 FROM lancamentos_de_teste WHERE account_id = OLD.id)
        BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END");

    $dona = User::fixture(['email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Empresa referenciada', $dona);
    DB::table('lancamentos_de_teste')->insert(['account_id' => $empresa->id]);
    $this->actingAs($dona);

    $token = tokenDeExclusao($dona);

    try {
        Accounts::actingAs($empresa, fn () => app(DeleteAccount::class)->handle($dona, $token), $dona);
        $this->fail('A exclusão deveria ter sido recusada.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['account' => [__('accounts.deletion.referenced')]]);
    }

    // Nada saiu (nem os vínculos, nem a trilha de sucesso), e a recusa ficou.
    expect(Account::query()->whereKey($empresa->id)->exists())->toBeTrue()
        ->and($empresa->memberships()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'account.deleted')->where('tenant_uuid', $empresa->uuid)->pluck('outcome')->map->value->all())
        ->toBe(['denied']);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'o teste liga as chaves estrangeiras do SQLite');

it('código e mensagem do impedimento são validados', function (): void {
    expect(fn () => new DeletionImpediment('Código Inválido', 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DeletionImpediment('valido', '  '))->toThrow(InvalidArgumentException::class)
        ->and((new DeletionImpediment('legal_hold', 'Guardado.'))->code)->toBe('legal_hold');

    app(DeletionImpediments::class)->register(fn (): array => ['texto solto']);

    expect(fn () => app(DeletionImpediments::class)->check(DeletionRequest::forAccount(new Account)))->toThrow(InvalidArgumentException::class);
});

it('reconhece a recusa por chave estrangeira de cada banco — e só ela', function (string $sqlState, string $mensagem, bool $esperado): void {
    $pdo = new PDOException($mensagem);
    $pdo->errorInfo = [$sqlState, 0, $mensagem];

    expect(DeletionImpediments::isForeignKeyViolation(new QueryException('testing', 'delete from accounts', [], $pdo)))->toBe($esperado);
})->with([
    'PostgreSQL RESTRICT' => ['23001', 'violates RESTRICT setting of foreign key constraint', true],
    'PostgreSQL NO ACTION' => ['23503', 'violates foreign key constraint', true],
    'SQLite' => ['23000', 'FOREIGN KEY constraint failed', true],
    'MySQL' => ['23000', 'Cannot delete or update a parent row: a foreign key constraint fails', true],
    'chave única não é' => ['23505', 'duplicate key value violates unique constraint', false],
    'outro 23000 não é' => ['23000', 'NOT NULL constraint failed', false],
]);
