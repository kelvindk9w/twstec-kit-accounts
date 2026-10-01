<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Deletion\Exceptions;

use RuntimeException;
use Twstec\Kit\Accounts\Deletion\DeletionImpediment;

/**
 * Exclusão RECUSADA por impedimento declarado (Contracts\DeletionCheck) ou
 * por um registro do aplicativo que ainda aponta para o que sairia (chave
 * estrangeira RESTRICT — ver Deletion\DeletionImpediments::guardIntegrity).
 *
 * Lançada ANTES de qualquer linha sair (ou com a transação já desfeita): nada
 * foi apagado. A mensagem é a que o usuário lê.
 */
final class DeletionImpededException extends RuntimeException
{
    /**
     * @param  non-empty-list<DeletionImpediment>  $impediments
     */
    public function __construct(public readonly array $impediments)
    {
        parent::__construct(self::messageFor($impediments));
    }

    /**
     * @param  list<DeletionImpediment>  $impediments
     */
    public static function messageFor(array $impediments): string
    {
        return implode(' ', array_map(fn (DeletionImpediment $impediment): string => $impediment->message, $impediments));
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(fn (DeletionImpediment $impediment): string => $impediment->code, $this->impediments);
    }
}
