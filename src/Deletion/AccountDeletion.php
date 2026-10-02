<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Deletion;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Exceptions\OwnerOfSharedAccountException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionImpededException;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionOutsideServiceException;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use WeakMap;

/**
 * O CAMINHO ÚNICO DE EXCLUSÃO de pessoa e de conta — o que as telas usam (a
 * página da conta nos starters, o /admin, a execução da aprovação em dois
 * passos) e o que o código do aplicativo (job, comando, serviço) deve usar.
 *
 *     app(AccountDeletion::class)->deleteUser($pessoa);
 *     app(AccountDeletion::class)->deleteAccount($conta, $quemPediu);
 *
 * Em cada exclusão:
 * 1. pergunta aos verificadores de impedimento (Deletion\DeletionImpediments)
 *    ANTES de qualquer linha sair — para a pessoa, também a regra do dono de
 *    conta com outros membros;
 * 2. roda a exclusão num savepoint com a REDE DE SEGURANÇA: um registro do
 *    aplicativo que ainda aponta para o que sairia (chave estrangeira
 *    RESTRICT que ninguém declarou) vira a mesma recusa limpa
 *    (DeletionImpededException, código `referenced_by_application`), com a
 *    exclusão desfeita — nunca o erro bruto do banco;
 * 3. grava a RECUSA na trilha de auditoria (`user.deleted` ou
 *    `account.deleted`, `denied`, com o motivo), de forma que ela fique no
 *    banco MESMO quando quem chamou desfaz a transação em volta; e a
 *    exclusão da conta grava também o sucesso (`account.deleted`), na mesma
 *    transação da exclusão.
 *
 * Recusada, a exclusão LANÇA (nada foi apagado e a recusa já está na trilha:
 * quem chama só mostra a mensagem, sem gravar de novo):
 * - Exceptions\DeletionImpededException — impedimento declarado ou registro
 *   do aplicativo que aponta para o que sairia;
 * - Account\Exceptions\OwnerOfSharedAccountException — a pessoa é dona de
 *   conta com outros membros (a propriedade é transferida antes).
 *
 * FALHA FECHADA FORA DESTE CAMINHO:
 * - `$conta->delete()` direto é RECUSADO (Exceptions\DeletionOutsideServiceException,
 *   com a recusa na trilha): a conta só sai por aqui, que leva junto o que é
 *   dela e avisa quem guarda dado dela fora deste pacote (os uploads);
 * - `$pessoa->delete()` direto continua valendo (é o que factories, testes e
 *   rotinas de limpeza usam) e continua perguntando aos verificadores e à
 *   regra do dono no `deleting`, com a recusa na trilha
 *   (Account\Support\PersonLifecycle). O que só existe aqui é a REDE DE
 *   SEGURANÇA: pela chamada direta, uma chave estrangeira RESTRICT não
 *   declarada continua recusando no banco (nada sai), mas com o erro bruto e
 *   sem linha na trilha. Declare o impedimento (DeletionCheck) ou exclua por
 *   aqui.
 * - Exclusão em massa (`User::query()->delete()`, `DB::table(...)`) não passa
 *   por evento de model nenhum: não use para pessoas nem contas.
 */
final class AccountDeletion
{
    /**
     * Pessoas sendo excluídas por deleteUser() agora: a recusa que sai do
     * `deleting` delas é gravada aqui, não lá (uma linha só).
     *
     * @var WeakMap<AuthUser, true>
     */
    private WeakMap $deletingPeople;

    /**
     * Contas que podem sair agora (já perguntadas): chave da conta → quantas
     * autorizações abertas.
     *
     * @var array<array-key, int>
     */
    private array $removable = [];

    /**
     * A linha `denied` gravada para cada recusa da exclusão de pessoa que
     * saiu de deleteUser() — para a tela que avisa o operador provar que a
     * recusa já está na trilha (o /admin).
     *
     * @var WeakMap<Throwable, AuditEvent>
     */
    private WeakMap $refusals;

    public function __construct(
        private readonly AccountService $accounts,
        private readonly DeletionImpediments $impediments,
        private readonly AccountAudit $audit,
        private readonly AuditTrail $trail,
    ) {
        $this->deletingPeople = new WeakMap;
        $this->refusals = new WeakMap;
    }

    /**
     * Exclui a PESSOA (e as contas que saem junto com ela: a pessoal e as de
     * que ela é a única dona). Devolve o que o `delete()` do model devolveu
     * (`false` = um ouvinte do aplicativo cancelou sem exceção).
     *
     * @throws DeletionImpededException
     * @throws OwnerOfSharedAccountException
     */
    public function deleteUser(Model&AuthUser $person): bool
    {
        $this->deletingPeople[$person] = true;

        try {
            // A pergunta aos verificadores e a regra do dono rodam no
            // `deleting` do model (PersonLifecycle), dentro do savepoint.
            return DeletionImpediments::guardIntegrity(fn (): bool => (bool) $person->delete());
        } catch (DeletionImpededException|OwnerOfSharedAccountException $exception) {
            $this->refusals[$exception] = $this->recordPersonRefusal($person, $exception->getMessage());

            throw $exception;
        } finally {
            unset($this->deletingPeople[$person]);
        }
    }

    /**
     * Exclui a CONTA com o que é dela (projetos, chaves, vínculos, convites;
     * e o que os outros pacotes guardam dela, pelo Events\AccountDeleting).
     * `$actor` é quem pediu (vai para a trilha; nulo numa rotina do sistema).
     *
     * A conta PESSOAL não sai por aqui: ela sai junto com a pessoa.
     *
     * @throws DeletionImpededException
     */
    public function deleteAccount(Account $account, ?AuthUser $actor = null): void
    {
        $impediments = $account->isPersonal()
            ? [new DeletionImpediment('personal_account', __('accounts.deletion.personal_account'))]
            : $this->impediments->check(DeletionRequest::forAccount($account));

        if ($impediments !== []) {
            $this->refuseAccount($account, $actor, new DeletionImpededException($impediments));
        }

        try {
            DeletionImpediments::guardIntegrity(function () use ($account, $actor): void {
                $changes = [
                    'name' => ['before' => $account->name, 'after' => null],
                    'members' => ['before' => $account->memberships()->count(), 'after' => 0],
                ];

                // Com quem pediu, a linha diz quem; sem (rotina do sistema),
                // vale o escopo em volta (o console, o /admin, a requisição).
                $actor !== null
                    ? $this->audit->record(AccountAuditEvent::Deleted, $account, $actor, $account, $changes)
                    : $this->trail->record(AccountAuditEvent::Deleted->value, $account, $changes, tenantUuid: (string) $account->uuid);

                $this->allowingRemoval([$account->getKey()], fn () => $this->accounts->removeAccount($account));
            });
        } catch (DeletionImpededException $exception) {
            $this->refuseAccount($account, $actor, $exception);
        }
    }

    /**
     * Motivo traduzido pelo qual a pessoa não pode ser excluída agora (nulo
     * quando pode) — para a tela avisar antes de pedir a confirmação.
     */
    public function personDenial(AuthUser $person): ?string
    {
        return $this->accounts->deletionDenial($person);
    }

    /**
     * Motivo traduzido pelo qual a conta não pode ser excluída agora (nulo
     * quando pode).
     */
    public function accountDenial(Account $account): ?string
    {
        return $account->isPersonal()
            ? __('accounts.deletion.personal_account')
            : $this->accounts->accountDeletionDenial($account);
    }

    /**
     * A linha `denied` que deleteUser() gravou para esta recusa (nula para
     * uma exceção que não saiu de lá).
     */
    public function recordedRefusal(Throwable $refusal): ?AuditEvent
    {
        return $this->refusals[$refusal] ?? null;
    }

    /**
     * A pessoa está sendo excluída por deleteUser() agora? (Quem grava a
     * recusa, nesse caso, é deleteUser().)
     *
     * @internal
     */
    public function isDeletingPerson(AuthUser $person): bool
    {
        return isset($this->deletingPeople[$person]);
    }

    /**
     * Libera a saída destas contas (já perguntadas) enquanto `$remove` roda —
     * a exclusão da conta e a limpeza depois da exclusão da pessoa. Fora
     * disso, o `delete()` da conta é recusado (guardDirectDeletion).
     *
     * @internal
     *
     * @template T
     *
     * @param  list<array-key>  $accountKeys
     * @param  Closure(): T  $remove
     * @return T
     */
    public function allowingRemoval(array $accountKeys, Closure $remove): mixed
    {
        foreach ($accountKeys as $key) {
            $this->removable[$key] = ($this->removable[$key] ?? 0) + 1;
        }

        try {
            return $remove();
        } finally {
            foreach ($accountKeys as $key) {
                if (--$this->removable[$key] <= 0) {
                    unset($this->removable[$key]);
                }
            }
        }
    }

    /**
     * O `deleting` do model da conta: só sai a conta liberada por este
     * serviço; qualquer outro `delete()` é recusado, com a recusa na trilha.
     *
     * @throws DeletionOutsideServiceException
     */
    public function guardDirectDeletion(Account $account): void
    {
        if (isset($this->removable[$account->getKey()])) {
            return;
        }

        $exception = new DeletionOutsideServiceException;

        $this->recordAccountRefusal($account, null, $exception->getMessage());

        throw $exception;
    }

    /**
     * Grava uma recusa na trilha de modo que ela FIQUE no banco: gravada
     * agora e, a cada transação em volta que for desfeita (savepoint ou a
     * transação inteira), gravada de novo no nível de cima — a linha gravada
     * dentro dela iria embora junto. Fica sempre exatamente uma linha.
     *
     * @internal
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T O que a primeira gravação devolveu.
     */
    public static function recordDurably(Closure $write): mixed
    {
        $record = static function () use ($write, &$record): mixed {
            $written = $write();

            if (DB::transactionLevel() > 0) {
                DB::afterRollBack($record);
            }

            return $written;
        };

        return $record();
    }

    /**
     * Grava a recusa da exclusão da pessoa (`user.deleted`, `denied`).
     *
     * @internal
     */
    public function recordPersonRefusal(AuthUser $person, string $reason): AuditEvent
    {
        return self::recordDurably(fn () => $this->trail->denied(
            AuditTrail::subjectType(UserModel::name()).'.deleted',
            $person instanceof Model ? $person : null,
            $reason,
        ));
    }

    /**
     * @throws DeletionImpededException
     */
    private function refuseAccount(Account $account, ?AuthUser $actor, DeletionImpededException $exception): never
    {
        $this->recordAccountRefusal($account, $actor, $exception->getMessage());

        throw $exception;
    }

    /**
     * Grava a recusa da exclusão da conta (`account.deleted`, `denied`, na
     * conta). Com quem pediu, a linha diz quem; sem, vale o escopo em volta.
     */
    private function recordAccountRefusal(Account $account, ?AuthUser $actor, string $reason): void
    {
        self::recordDurably(fn () => $actor !== null
            ? $this->audit->denied(AccountAuditEvent::Deleted, $account, $actor, $reason, $account)
            : $this->trail->denied(AccountAuditEvent::Deleted->value, $account, $reason, tenantUuid: (string) $account->uuid));
    }
}
