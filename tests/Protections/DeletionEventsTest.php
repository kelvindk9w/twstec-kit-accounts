<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Events\AccountDeleting;
use Twstec\Kit\Accounts\Account\Events\PersonDeleted;
use Twstec\Kit\Accounts\Account\Events\PersonDeleting;
use Twstec\Kit\Accounts\Account\Exceptions\OwnerOfSharedAccountException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;

// =============================================================================
// Os EVENTOS de exclusão que o pacote dá a quem guarda dado das contas fora
// dele (os uploads): PersonDeleting (só para ler — a exclusão ainda pode ser
// recusada depois), PersonDeleted (a pessoa saiu; antes de o pacote arrumar as
// contas) e AccountDeleting (dentro da transação da exclusão da conta, antes
// de qualquer linha sair). Exclusão recusada não dispara nada.
// =============================================================================

it('excluir a pessoa: PersonDeleting e depois PersonDeleted, com as contas que somem junto', function (): void {
    $ana = $this->owner();
    $bruno = $this->owner();
    $empresa = app(AccountService::class)->createAccount('Empresa', $bruno);
    app(AccountService::class)->addMember($empresa, $ana, AccountRole::Admin);
    $pessoal = $this->accountOf($ana);

    $ordem = [];

    Event::listen(PersonDeleting::class, function (PersonDeleting $evento) use (&$ordem, $ana, $pessoal): void {
        $ordem[] = 'deleting';

        // A pessoa ainda está no banco; a conta pessoal também.
        expect($evento->user->is($ana))->toBeTrue()
            ->and($evento->vanishingAccountIds)->toBe([(int) $pessoal->id])
            ->and(DB::table('users')->where('id', $ana->id)->exists())->toBeTrue();
    });

    Event::listen(PersonDeleted::class, function (PersonDeleted $evento) use (&$ordem, $ana, $pessoal): void {
        $ordem[] = 'deleted';

        expect($evento->vanishingAccountIds)->toBe([(int) $pessoal->id])
            ->and(DB::table('users')->where('id', $ana->id)->exists())->toBeFalse();
    });

    $ana->delete();

    // A empresa do Bruno (em que a Ana era admin) NÃO some.
    expect($ordem)->toBe(['deleting', 'deleted'])
        ->and(Account::query()->whereKey($empresa->id)->exists())->toBeTrue()
        ->and(Account::query()->whereKey($pessoal->id)->exists())->toBeFalse();
});

it('exclusão recusada (dona de conta com outros membros): nenhum evento', function (): void {
    $ana = $this->owner();
    $empresa = app(AccountService::class)->createAccount('Empresa', $ana);
    app(AccountService::class)->addMember($empresa, $this->owner(), AccountRole::Member);

    Event::fake([PersonDeleting::class, PersonDeleted::class, AccountDeleting::class]);

    expect(fn () => $ana->delete())->toThrow(OwnerOfSharedAccountException::class);

    Event::assertNotDispatched(PersonDeleting::class);
    Event::assertNotDispatched(PersonDeleted::class);
    Event::assertNotDispatched(AccountDeleting::class);
});

it('excluir a conta: AccountDeleting dentro da transação, com os dados ainda lá', function (): void {
    $ana = $this->owner();
    $empresa = app(AccountService::class)->createAccount('Empresa', $ana);
    $nivel = null;

    Event::listen(AccountDeleting::class, function (AccountDeleting $evento) use (&$nivel, $empresa): void {
        $nivel = DB::transactionLevel();

        expect($evento->account->is($empresa))->toBeTrue()
            ->and(Account::query()->whereKey($empresa->id)->exists())->toBeTrue();
    });

    $antes = DB::transactionLevel();

    app(AccountService::class)->deleteAccount($empresa);

    expect($nivel)->toBe($antes + 1)
        ->and(Account::query()->whereKey($empresa->id)->exists())->toBeFalse();
});
