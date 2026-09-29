<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Exceptions\MissingAccountContextException;
use Twstec\Kit\Accounts\Account\Queue\AccountJobContext;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Accounts\Tests\Fixtures\RecordsAccountContextJob;

// =============================================================================
// JOBS ENFILEIRADOS carregam a conta de quem enfileirou e a RESTAURAM no
// worker — numa aplicação limpa, com a fila `database` de verdade (o worker
// roda num contexto sem conta nenhuma, como um processo separado).
// =============================================================================

beforeEach(function (): void {
    config(['queue.default' => 'database']);
    RecordsAccountContextJob::$runs = [];
});

function rodarFila(): void
{
    Artisan::call('queue:work', ['--once' => true, '--queue' => 'default', '--stop-when-empty' => true]);
}

it('o job restaura a conta de quem enfileirou e só enxerga os dados dela', function (): void {
    $ana = $this->owner();
    $bruno = $this->owner();
    $this->inAccountOf($ana, fn () => Project::createWithPublicCodeRetry(['name' => 'Da Ana']));
    $this->inAccountOf($bruno, fn () => Project::createWithPublicCodeRetry(['name' => 'Do Bruno']));

    $this->inAccountOf($ana, fn () => dispatch(new RecordsAccountContextJob));

    // O payload leva a conta.
    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
    expect($payload[AccountJobContext::PAYLOAD_KEY]['account'])->toBe($this->accountOf($ana)->getKey());

    // O worker começa SEM conta (outro processo, ninguém logado).
    expect(Accounts::current())->toBeNull();

    rodarFila();

    expect(RecordsAccountContextJob::$runs)->toBe([[
        'account' => $this->accountOf($ana)->getKey(),
        'system' => null,
        'projects' => ['Da Ana'],
    ]]);

    // Depois do job, o contexto do worker volta a ser nenhum.
    expect(Accounts::current())->toBeNull();
});

it('job enfileirado SEM conta roda sem conta: tocar em dado de conta é exceção, não vazamento', function (): void {
    $ana = $this->owner();
    $this->inAccountOf($ana, fn () => Project::createWithPublicCodeRetry(['name' => 'Da Ana']));

    dispatch(new RecordsAccountContextJob);

    rodarFila();

    expect(RecordsAccountContextJob::$runs)->toBe([[
        'account' => null,
        'system' => null,
        'projects' => MissingAccountContextException::class,
    ]]);
});

it('job enfileirado em modo sistema roda em modo sistema declarado (com o motivo de origem)', function (): void {
    $ana = $this->owner();
    $bruno = $this->owner();
    $this->inAccountOf($ana, fn () => Project::createWithPublicCodeRetry(['name' => 'A']));
    $this->inAccountOf($bruno, fn () => Project::createWithPublicCodeRetry(['name' => 'B']));

    Accounts::asSystem('relatório noturno', fn () => dispatch(new RecordsAccountContextJob));

    rodarFila();

    expect(RecordsAccountContextJob::$runs)->toBe([[
        'account' => null,
        'system' => 'queue: relatório noturno',
        'projects' => ['A', 'B'],
    ]]);
});

it('na fila sync o contexto de fora é preservado depois do job', function (): void {
    config(['queue.default' => 'sync']);
    $ana = $this->owner();
    $bruno = $this->owner();
    $this->inAccountOf($bruno, fn () => Project::createWithPublicCodeRetry(['name' => 'Do Bruno']));

    $depois = $this->inAccountOf($bruno, function () use ($ana) {
        // Um job enfileirado de dentro de outra conta leva ESTA conta (Bruno).
        dispatch(new RecordsAccountContextJob);

        // E um enfileirado dentro de actingAs(Ana) leva a Ana.
        $this->inAccountOf($ana, fn () => dispatch(new RecordsAccountContextJob));

        return Accounts::current()?->getKey();
    });

    expect($depois)->toBe($this->accountOf($bruno)->getKey())
        ->and(array_column(RecordsAccountContextJob::$runs, 'account'))
        ->toBe([$this->accountOf($bruno)->getKey(), $this->accountOf($ana)->getKey()]);
});
