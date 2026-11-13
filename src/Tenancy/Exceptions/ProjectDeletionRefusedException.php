<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Deletion\DeletionImpediments;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionImpededException;
use Twstec\Kit\Foundation\Http\Exceptions\Contracts\ProvidesApiErrorCode;

/**
 * Exclusão de projeto RECUSADA — nada foi apagado. É um 409 (conflito com o
 * estado do recurso), e a mensagem é a que o usuário lê.
 *
 * | code                       | quando                                                                 |
 * |----------------------------|------------------------------------------------------------------------|
 * | `project_deletion_refused` | uma regra do aplicativo impede (Contracts\ProjectDeletionCheck)         |
 * | `conflict`                 | o banco recusou: um registro ainda aponta para o projeto (chave estrangeira) e nenhuma regra o declarou |
 *
 * Na API v1, sai no envelope de erro com o código e a mensagem (os motivos,
 * já traduzidos). Nas telas, quem chama mostra a mensagem no lugar do aviso
 * de sucesso. Sem tratamento, continua um 409 — nunca um 500.
 */
final class ProjectDeletionRefusedException extends HttpException implements ProvidesApiErrorCode
{
    public const REFUSED = 'project_deletion_refused';

    public const CONFLICT = 'conflict';

    /**
     * @param  non-empty-list<DeletionImpediment>  $impediments
     */
    private function __construct(public readonly array $impediments, private readonly string $errorCode)
    {
        parent::__construct(409, DeletionImpededException::messageFor($impediments));
    }

    /**
     * @param  non-empty-list<DeletionImpediment>  $impediments
     */
    public static function impeded(array $impediments): self
    {
        return new self($impediments, self::REFUSED);
    }

    /**
     * O banco recusou por chave estrangeira (registro que ninguém declarou
     * como impedimento).
     */
    public static function referenced(): self
    {
        return new self([new DeletionImpediment(DeletionImpediments::REFERENCED, (string) __('api_keys.projects.deletion_referenced'))], self::CONFLICT);
    }

    public function apiErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Os códigos dos impedimentos (`project_has_orders`,
     * `referenced_by_application`…), para teste, log e decisão de tela.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(static fn (DeletionImpediment $impediment): string => $impediment->code, $this->impediments);
    }
}
