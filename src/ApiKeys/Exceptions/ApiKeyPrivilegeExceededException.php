<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Twstec\Kit\Foundation\Http\Exceptions\Contracts\ProvidesApiErrorCode;

/**
 * A chave de API autenticada tentou criar, rotacionar ou editar uma chave
 * MAIS AMPLA que ela mesma (escalada de privilégio). Sai no envelope de erro
 * da API como 403, com um código estável:
 *
 * | code                        | quando                                                        |
 * |-----------------------------|---------------------------------------------------------------|
 * | `api_key_scope_exceeded`    | um escopo pedido (ou o da chave alvo) não cabe nos da chave autenticada |
 * | `api_key_projects_exceeded` | a chave autenticada é restrita a projetos e o pedido sai deles |
 */
final class ApiKeyPrivilegeExceededException extends HttpException implements ProvidesApiErrorCode
{
    public const SCOPE = 'api_key_scope_exceeded';

    public const PROJECTS = 'api_key_projects_exceeded';

    private function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct(403, $message);
    }

    /**
     * @param  list<string>  $scopes  Os escopos que não cabem.
     */
    public static function scopes(array $scopes): self
    {
        return new self(self::SCOPE, (string) __('api_keys.scopes.exceeded', ['scopes' => implode(', ', $scopes)]));
    }

    public static function projects(): self
    {
        return new self(self::PROJECTS, (string) __('api_keys.projects.exceeded'));
    }

    public function apiErrorCode(): string
    {
        return $this->errorCode;
    }
}
