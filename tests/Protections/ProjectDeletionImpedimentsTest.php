<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Accounts\Account\Support\AccountResourceGuard;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Tenancy\Contracts\ProjectDeletionCheck;
use Twstec\Kit\Accounts\Tenancy\Deletion\ProjectDeletionImpediments;
use Twstec\Kit\Accounts\Tenancy\Exceptions\ProjectDeletionRefusedException;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Accounts\Tenancy\Services\ProjectService;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// O APLICATIVO IMPEDE A EXCLUSÃO DE UM PROJETO.
//
// Regras declaradas (accounts.projects.deletion_checks ou
// ProjectDeletionImpediments::register) são perguntadas pelo
// ProjectService::delete() — o caminho da API v1 e das telas — antes de o
// projeto sair. Havendo impedimento: 409 `project_deletion_refused` com o
// motivo, nada apagado. Registro do aplicativo que aponta para o projeto e que
// nenhuma regra declarou: o banco recusa (chave estrangeira) e a resposta é o
// 409 genérico `conflict` — nunca o 500 do erro bruto.
// =============================================================================

/**
 * Regra de exemplo do "aplicativo": projeto com pedidos não sai.
 */
final class ProjetoComPedidosNaoSai implements ProjectDeletionCheck
{
    /** @var list<string> */
    public static array $comPedidos = [];

    public function impediments(Project $project): iterable
    {
        if (in_array((string) $project->uuid, self::$comPedidos, true)) {
            yield new DeletionImpediment('project_has_orders', 'O projeto tem pedidos e não pode ser excluído.');
        }
    }
}

beforeEach(function (): void {
    ProjetoComPedidosNaoSai::$comPedidos = [];
});

it('API v1: regra do aplicativo impede → 409 project_deletion_refused com o motivo, e nada sai', function (): void {
    config()->set('accounts.projects.deletion_checks', [ProjetoComPedidosNaoSai::class]);

    $dona = $this->owner();
    ['api_key' => $chave, 'secret_key' => $segredo] = $this->keyFor($dona);
    $preso = $this->inAccountOf($dona, fn () => Project::createWithPublicCodeRetry(['name' => 'Com pedidos']));
    $livre = $this->inAccountOf($dona, fn () => Project::createWithPublicCodeRetry(['name' => 'Sem pedidos']));
    ProjetoComPedidosNaoSai::$comPedidos = [(string) $preso->uuid];

    $this->deleteJson("/api/v1/projects/{$preso->uuid}", [], $this->credentials($chave, $segredo))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'project_deletion_refused')
        ->assertJsonPath('error.message', 'O projeto tem pedidos e não pode ser excluído.')
        ->assertJsonStructure(['error' => ['code', 'message', 'correlation_id']]);

    $this->deleteJson("/api/v1/projects/{$livre->uuid}", [], $this->credentials($chave, $segredo))
        ->assertOk()
        ->assertJsonPath('message', __('api_keys.projects.deleted'));

    expect($this->inAccountOf($dona, fn () => Project::query()->pluck('uuid')->map(fn ($uuid) => (string) $uuid)->all()))
        ->toBe([(string) $preso->uuid]);
});

it('sem regra declarada, a exclusão segue como sempre', function (): void {
    $dona = $this->owner();
    ['api_key' => $chave, 'secret_key' => $segredo] = $this->keyFor($dona);
    $projeto = $this->inAccountOf($dona, fn () => Project::createWithPublicCodeRetry(['name' => 'Qualquer']));

    $this->deleteJson("/api/v1/projects/{$projeto->uuid}", [], $this->credentials($chave, $segredo))->assertOk();

    expect($this->inAccountOf($dona, fn () => Project::query()->count()))->toBe(0);
});

it('regras em código (Closure e instância) somam-se às da configuração: todos os motivos e códigos', function (): void {
    config()->set('accounts.projects.deletion_checks', [ProjetoComPedidosNaoSai::class]);
    app(ProjectDeletionImpediments::class)->register(fn (Project $project): array => [
        new DeletionImpediment('legal_hold', 'O projeto está sob guarda legal.'),
    ]);
    app(ProjectDeletionImpediments::class)->register(new class implements ProjectDeletionCheck
    {
        public function impediments(Project $project): iterable
        {
            return [];
        }
    });

    $dona = $this->owner();
    $projeto = $this->inAccountOf($dona, fn () => Project::createWithPublicCodeRetry(['name' => 'Preso']));
    ProjetoComPedidosNaoSai::$comPedidos = [(string) $projeto->uuid];

    try {
        $this->inAccountOf($dona, fn () => app(ProjectService::class)->delete($projeto));
        $this->fail('A exclusão deveria ter sido recusada.');
    } catch (ProjectDeletionRefusedException $recusa) {
        expect($recusa->getStatusCode())->toBe(409)
            ->and($recusa->apiErrorCode())->toBe('project_deletion_refused')
            ->and($recusa->codes())->toBe(['project_has_orders', 'legal_hold'])
            ->and($recusa->getMessage())->toBe('O projeto tem pedidos e não pode ser excluído. O projeto está sob guarda legal.');
    }

    expect($this->inAccountOf($dona, fn () => Project::query()->count()))->toBe(1);
});

it('regra que falha não é engolida (a exclusão para) e resposta fora do contrato é erro', function (): void {
    $dona = $this->owner();
    $projeto = $this->inAccountOf($dona, fn () => Project::createWithPublicCodeRetry(['name' => 'Na dúvida, fica']));

    app(ProjectDeletionImpediments::class)->register(fn (Project $project): iterable => throw new RuntimeException('regra fora do ar'));

    expect(fn () => $this->inAccountOf($dona, fn () => app(ProjectService::class)->delete($projeto)))->toThrow(RuntimeException::class, 'regra fora do ar')
        ->and($this->inAccountOf($dona, fn () => Project::query()->count()))->toBe(1);

    $this->refreshApplication();
    config()->set('accounts.projects.deletion_checks', [stdClass::class]);
    expect(fn () => app(ProjectDeletionImpediments::class)->check(new Project))->toThrow(InvalidArgumentException::class);
});

it('registro do aplicativo que aponta para o projeto e não foi declarado: 409 conflict genérico, nunca 500, e a transação de quem chama continua utilizável', function (): void {
    // As chaves estrangeiras do SQLite em memória da suíte do pacote ficam
    // desligadas: o gatilho reproduz a recusa do banco com o mesmo erro que a
    // RESTRICT dá. A RESTRICT de verdade (SQLite e PostgreSQL) é coberta na
    // suíte do starter.
    Schema::create('app_pedidos', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('project_id');
    });
    DB::unprepared("CREATE TRIGGER app_pedidos_restrict BEFORE DELETE ON projects
        WHEN EXISTS (SELECT 1 FROM app_pedidos WHERE project_id = OLD.id)
        BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END");

    $dona = $this->owner();
    ['api_key' => $chave, 'secret_key' => $segredo] = $this->keyFor($dona);
    $projeto = $this->inAccountOf($dona, fn () => Project::createWithPublicCodeRetry(['name' => 'Referenciado']));
    DB::table('app_pedidos')->insert(['project_id' => $projeto->getKey()]);

    $this->deleteJson("/api/v1/projects/{$projeto->uuid}", [], $this->credentials($chave, $segredo))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict')
        ->assertJsonPath('error.message', __('api_keys.projects.deletion_referenced'));

    // Dentro de uma transação do chamador: a recusa não a deixa abortada.
    DB::transaction(function () use ($dona, $projeto): void {
        try {
            $this->inAccountOf($dona, fn () => app(ProjectService::class)->delete($projeto));
            $this->fail('A exclusão deveria ter sido recusada.');
        } catch (ProjectDeletionRefusedException $recusa) {
            expect($recusa->apiErrorCode())->toBe('conflict')
                ->and($recusa->codes())->toBe(['referenced_by_application']);
        }

        expect(DB::table('app_pedidos')->count())->toBe(1);
    });

    expect($this->inAccountOf($dona, fn () => Project::query()->count()))->toBe(1);
});

it('a recusa vista na tela fica na trilha (denied, com o motivo)', function (): void {
    $dona = $this->owner();
    $projeto = $this->inAccountOf($dona, fn () => Project::createWithPublicCodeRetry(['name' => 'Preso']));
    $this->actingAs($dona);

    Accounts::actingAs($this->accountOf($dona), function () use ($projeto): void {
        app(AccountResourceGuard::class)->projectDeletionRefused(
            $projeto,
            ProjectDeletionRefusedException::impeded([new DeletionImpediment('project_has_orders', 'O projeto tem pedidos.')]),
        );
    }, $dona);

    $linha = AuditEvent::query()->sole();

    expect($linha->action)->toBe('project.deleted')
        ->and($linha->outcome->value)->toBe('denied')
        ->and($linha->reason)->toBe('O projeto tem pedidos.')
        ->and((string) $linha->subject_uuid)->toBe((string) $projeto->uuid);
});
