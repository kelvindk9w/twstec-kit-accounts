<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Deletion;

use InvalidArgumentException;

/**
 * UM motivo pelo qual a exclusão não pode acontecer agora.
 *
 * - `code`: identificador estável, em snake_case (`retained_records`,
 *   `legal_hold`, `owner_of_shared_account`) — para teste, log e decisão de
 *   tela; nunca muda com o idioma.
 * - `message`: o texto que o usuário lê e que vai para a trilha de auditoria
 *   (`reason`, redigido). Já traduzido.
 */
final class DeletionImpediment
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1) {
            throw new InvalidArgumentException('O código do impedimento de exclusão deve ser snake_case (até 64 caracteres).');
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('O impedimento de exclusão precisa de uma mensagem para o usuário.');
        }
    }
}
