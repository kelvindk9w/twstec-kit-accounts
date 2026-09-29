<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyAttempt;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Enums\ProjectAttempt;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Identifiers\UuidColumn;

/**
 * A guarda das TELAS de chaves de API e de projetos (os dois starters): a
 * mesma recusa de sempre, agora com a tentativa na trilha — tudo que é tentado
 * no painel fica no banco, inclusive as recusas.
 *
 * - authorize(): o papel na conta atual não permite → a linha `denied` (a
 *   ação tentada, quem, a conta e o motivo) e o MESMO 403, com a MESMA
 *   mensagem de Accounts::authorize(). Quem pode passa sem linha nenhuma.
 * - apiKey() / project(): acha o recurso NA CONTA ATUAL pelo uuid; se ele não
 *   está nela (inexistente ou de outra conta — a guarda não distingue, e não
 *   consulta outra conta para isso), grava `denied` com o uuid tentado e
 *   relança a MESMA ModelNotFoundException: a resposta continua o 404 comum,
 *   idêntico nos dois casos, e não revela que o recurso existe.
 * - foreignProjects(): o vínculo pediu projeto que não está na conta atual →
 *   `denied`; quem chama devolve o erro de validação de sempre.
 *
 * A API v1 (por chave) não passa por aqui: as recusas de escopo e de chave
 * vinculada ficam nos middlewares (EnsureApiKeyScope, EnsureAccountWideApiKey),
 * e os 401/404 dela ficam fora da trilha (ver ApiKeyAttempt).
 */
final class AccountResourceGuard
{
    public function __construct(
        private readonly AccountAudit $audit,
        private readonly CurrentAccount $context,
    ) {}

    /**
     * @param  string|null  $subjectUuid  O alvo (a chave ou o projeto), quando a tentativa tem um.
     *
     * @throws AuthorizationException 403 quando o papel não permite.
     */
    public function authorize(AccountAbility $ability, ApiKeyAttempt|ProjectAttempt $attempt, ?string $subjectUuid = null): void
    {
        if (Accounts::can($ability)) {
            return;
        }

        $reason = __('accounts.authorization.denied');

        $this->deny($attempt, $reason, $subjectUuid);

        throw new AuthorizationException($reason);
    }

    /**
     * Chave de API da conta atual pelo uuid.
     *
     * @throws ModelNotFoundException<ApiKey> Fora da conta atual (404, com a tentativa na trilha).
     */
    public function apiKey(string $uuid, ApiKeyAttempt $attempt): ApiKey
    {
        /** @var ApiKey */
        return $this->findOrDeny(ApiKey::query(), $uuid, $attempt);
    }

    /**
     * Projeto da conta atual pelo uuid.
     *
     * @throws ModelNotFoundException<Project> Fora da conta atual (404, com a tentativa na trilha).
     */
    public function project(string $uuid, ProjectAttempt $attempt): Project
    {
        /** @var Project */
        return $this->findOrDeny(Project::query(), $uuid, $attempt);
    }

    /**
     * O vínculo chave ↔ projetos pediu projeto que não está na conta atual:
     * grava a tentativa. O erro de validação é de quem chama.
     */
    public function foreignProjects(ApiKeyAttempt $attempt, ?string $apiKeyUuid = null): void
    {
        $this->deny($attempt, __('api_keys.projects.invalid'), $apiKeyUuid);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     *
     * @throws ModelNotFoundException<TModel>
     */
    private function findOrDeny(Builder $query, string $uuid, ApiKeyAttempt|ProjectAttempt $attempt): Model
    {
        try {
            return $query->byUuid($uuid)->firstOrFail();
        } catch (ModelNotFoundException $exception) {
            $this->deny($attempt, __('accounts.authorization.not_found'), $uuid);

            throw $exception;
        }
    }

    private function deny(ApiKeyAttempt|ProjectAttempt $attempt, string $reason, ?string $subjectUuid): void
    {
        $subjectType = AuditTrail::subjectType($attempt instanceof ApiKeyAttempt ? ApiKey::class : Project::class);

        $this->audit->denied(
            $attempt,
            Accounts::current(),
            $this->context->actor(),
            $reason,
            subjectType: $subjectType,
            // O uuid tentado vem de quem pediu: só vai para a coluna se for uuid.
            subjectUuid: UuidColumn::isValid($subjectUuid) ? $subjectUuid : null,
        );
    }
}
