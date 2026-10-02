<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Deletion;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Twstec\Kit\Accounts\Deletion\Contracts\DeletionCheck;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionImpededException;

/**
 * IMPEDIMENTOS DE EXCLUSÃO — o ponto de extensão para o aplicativo (e os
 * outros pacotes do kit) dizer se e por que uma pessoa ou uma conta NÃO pode
 * ser excluída agora.
 *
 * Os verificadores (Contracts\DeletionCheck) vêm de dois lugares, nesta
 * ordem:
 * 1. a configuração do aplicativo: `accounts.deletion.checks` (lista de
 *    classes, resolvidas pelo container a cada consulta);
 * 2. o registro em código: `app(DeletionImpediments::class)->register(...)`,
 *    no boot de um service provider (é como um pacote, que não mexe na
 *    configuração do aplicativo, entra).
 *
 * Quem pergunta (ANTES de qualquer linha sair):
 * - a exclusão da PESSOA, no `deleting` do model de usuário
 *   (Account\Support\PersonLifecycle) e na pré-checagem das telas
 *   (AccountService::deletionDenial — o /admin usa);
 * - a exclusão da CONTA, no caminho único (Deletion\AccountDeletion) e na
 *   pré-checagem da página da conta (Account\Actions\DeleteAccount).
 *
 * Havendo impedimento: Exceptions\DeletionImpededException, com a mensagem
 * traduzida — e a recusa vai para a trilha de auditoria por quem a recebe
 * (o AccountDeletion — o caminho único que as telas e o código usam —, a
 * pré-checagem da Action, o PersonLifecycle no `delete()` direto da pessoa).
 *
 * REDE DE SEGURANÇA (aplicada pelo Deletion\AccountDeletion):
 * guardIntegrity() roda a exclusão num savepoint e, se o
 * banco recusar por chave estrangeira (um registro do aplicativo que aponta
 * para o que sairia e que ninguém declarou como impedimento), devolve a mesma
 * recusa limpa em vez do erro bruto do banco — com a transação desfeita.
 */
final class DeletionImpediments
{
    /**
     * Código do impedimento quando o banco recusou por chave estrangeira.
     */
    public const REFERENCED = 'referenced_by_application';

    /**
     * @var list<DeletionCheck|class-string<DeletionCheck>|Closure(DeletionRequest): iterable<DeletionImpediment>>
     */
    private array $registered = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Acrescenta um verificador: a instância, a classe (resolvida pelo
     * container a cada consulta) ou um Closure que recebe o pedido e devolve
     * os impedimentos.
     *
     * @param  DeletionCheck|class-string<DeletionCheck>|Closure(DeletionRequest): iterable<DeletionImpediment>  $check
     */
    public function register(DeletionCheck|string|Closure $check): void
    {
        $this->registered[] = $check;
    }

    /**
     * Todos os impedimentos do pedido (vazio = pode excluir). Exceção de um
     * verificador sobe: na dúvida, não exclui.
     *
     * @return list<DeletionImpediment>
     */
    public function check(DeletionRequest $request): array
    {
        $found = [];

        foreach ($this->checks() as $check) {
            $result = $check instanceof Closure ? $check($request) : $check->impediments($request);

            foreach ($result as $impediment) {
                if (! $impediment instanceof DeletionImpediment) {
                    throw new InvalidArgumentException('Um verificador de exclusão devolveu algo que não é DeletionImpediment.');
                }

                $found[] = $impediment;
            }
        }

        return $found;
    }

    /**
     * @throws DeletionImpededException
     */
    public function ensureNone(DeletionRequest $request): void
    {
        $found = $this->check($request);

        if ($found !== []) {
            throw new DeletionImpededException($found);
        }
    }

    /**
     * Roda a exclusão num savepoint (ou numa transação, fora de uma) e troca a
     * recusa do banco por chave estrangeira pela recusa limpa. A transação da
     * exclusão é desfeita inteira; a de quem chamou continua utilizável (no
     * PostgreSQL, sem o savepoint ela ficaria abortada).
     *
     * @template T
     *
     * @param  Closure(): T  $delete
     * @return T
     *
     * @throws DeletionImpededException
     */
    public static function guardIntegrity(Closure $delete): mixed
    {
        try {
            return DB::transaction($delete);
        } catch (QueryException $exception) {
            if (! self::isForeignKeyViolation($exception)) {
                throw $exception;
            }

            // Sem a mensagem do banco (ela traz valores de chave): só o código.
            Log::warning('accounts.deletion.undeclared_reference', ['sqlstate' => $exception->errorInfo[0] ?? null]);

            throw new DeletionImpededException([new DeletionImpediment(self::REFERENCED, __('accounts.deletion.referenced'))]);
        }
    }

    public static function isForeignKeyViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        // PostgreSQL: 23503 (foreign_key_violation, NO ACTION) e 23001
        // (restrict_violation, RESTRICT). SQLite e MySQL: 23000 genérico, com
        // o texto.
        return in_array($sqlState, ['23503', '23001'], true)
            || ($sqlState === '23000' && stripos($exception->getMessage(), 'foreign key') !== false);
    }

    /**
     * @return list<DeletionCheck|Closure(DeletionRequest): iterable<DeletionImpediment>>
     */
    private function checks(): array
    {
        $checks = [];

        foreach ([...array_values((array) config('accounts.deletion.checks', [])), ...$this->registered] as $check) {
            if (is_string($check)) {
                $check = $this->container->make($check);
            }

            if (! $check instanceof DeletionCheck && ! $check instanceof Closure) {
                throw new InvalidArgumentException('Verificador de exclusão inválido: use uma classe que implementa '.DeletionCheck::class.' ou um Closure.');
            }

            $checks[] = $check;
        }

        return $checks;
    }
}
