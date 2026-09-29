<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Queue;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Auth\Support\UserModel;

/**
 * Jobs enfileirados CARREGAM a conta e a RESTAURAM.
 *
 * - Ao enfileirar: o payload ganha `twsAccountContext` com o contexto de quem
 *   enfileirou — a conta atual, ou o modo sistema e o motivo dele, e quem
 *   estava agindo.
 * - Ao processar (worker, ou a fila `sync`): antes do job, o mesmo contexto é
 *   empilhado; depois (sucesso, exceção ou falha), desempilhado. Um job que
 *   foi enfileirado SEM conta roda sem conta — e falha visível se tocar em
 *   dado de conta, em vez de cair na sessão de alguém.
 *
 * Um job que precisa varrer contas declara o modo sistema no próprio handle
 * (Accounts::asSystem) ou é enfileirado de dentro dele.
 */
final class AccountJobContext
{
    public const PAYLOAD_KEY = 'twsAccountContext';

    /**
     * Profundidade da pilha antes de cada job em processamento.
     *
     * @var array<int, int>
     */
    private array $depths = [];

    public function __construct(private readonly CurrentAccount $context) {}

    public static function register(Dispatcher $events): void
    {
        // O gancho do payload é estático no Queue do Laravel (os testes do
        // framework o limpam entre um teste e outro): registrado a cada boot,
        // lendo o contexto do container da vez. Registrar de novo não duplica
        // nada no payload — a chave é a mesma.
        Queue::createPayloadUsing(static fn (): array => [
            self::PAYLOAD_KEY => app(CurrentAccount::class)->snapshot(),
        ]);

        $events->listen(JobProcessing::class, static fn (JobProcessing $event) => app(self::class)->restore($event->job));

        foreach ([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class, JobReleasedAfterException::class] as $fim) {
            $events->listen($fim, static fn (object $event) => app(self::class)->release($event->job));
        }
    }

    public function restore(Job $job): void
    {
        $snapshot = $job->payload()[self::PAYLOAD_KEY] ?? null;

        // Cada job começa sem papel guardado (o worker é um processo longo).
        $this->context->forgetRoles();

        $this->depths[spl_object_id($job)] = $this->context->push($this->frameFor(is_array($snapshot) ? $snapshot : []));
    }

    public function release(Job $job): void
    {
        $id = spl_object_id($job);

        if (! array_key_exists($id, $this->depths)) {
            return;
        }

        $this->context->popTo($this->depths[$id]);
        $this->context->forgetRoles();

        unset($this->depths[$id]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{type: string, account?: Account, actor?: mixed, reason?: string}
     */
    private function frameFor(array $snapshot): array
    {
        $actor = isset($snapshot['actor']) ? UserModel::query()->find($snapshot['actor']) : null;

        if (is_string($snapshot['system'] ?? null) && $snapshot['system'] !== '') {
            return ['type' => CurrentAccount::FRAME_SYSTEM, 'reason' => 'queue: '.$snapshot['system'], 'actor' => $actor];
        }

        $account = isset($snapshot['account']) ? Account::query()->find($snapshot['account']) : null;

        if ($account instanceof Account) {
            return ['type' => CurrentAccount::FRAME_ACCOUNT, 'account' => $account, 'actor' => $actor];
        }

        return ['type' => CurrentAccount::FRAME_NONE];
    }
}
