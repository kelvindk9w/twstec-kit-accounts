<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyAttempt;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Exceptions\ApiKeyPrivilegeExceededException;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Support\ApiKeyGenerator;
use Twstec\Kit\Accounts\ApiKeys\Support\ApiKeyHasher;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Accounts\Tenancy\TenantContext;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;
use Twstec\Kit\Foundation\Identifiers\UuidColumn;

/**
 * Casos de uso do motor de API keys: criação, rotação e revogação.
 *
 * Invariantes:
 * - A secreta em claro NUNCA toca o banco: o service a retorna uma única vez
 *   junto com a chave persistida (só o hash é gravado).
 * - Scopes: padrão = tudo habilitado (config api_keys.default_scopes, ['*:*']);
 *   o usuário pode restringir por recurso:ação (menor privilégio).
 * - Rotação: nova chave herda nome, scopes e projetos da antiga; o dono escolhe
 *   a morte da antiga (imediata ou grace period em minutos).
 * - SEM ESCALADA PELA API: quando quem age é uma CHAVE de API (a requisição
 *   autenticada pelo resolve.tenant — TenantContext), a chave criada,
 *   rotacionada ou editada nunca é mais ampla que ela: os escopos têm de
 *   caber nos da chave autenticada (com curinga: `orders:*` contém
 *   `orders:create`; `*:*` só quem tem `*:*`) e, se ela for restrita a
 *   projetos, os projetos também. Na criação, `scopes` omitido HERDA os
 *   escopos da chave autenticada (nunca `*:*` por omissão). Recusa: 403
 *   (ApiKeyPrivilegeExceededException, código estável no envelope) e a
 *   tentativa na trilha (`api_key.privilege_exceeded`, contexto `api`). A
 *   regra mora AQUI, não no controller: todo caminho pela API passa por ela.
 *   Pela sessão (o painel), quem decide é o papel da pessoa na conta.
 */
final class ApiKeyService
{
    public function __construct(
        private readonly ApiKeyGenerator $generator,
        private readonly ApiKeyHasher $hasher,
    ) {}

    /**
     * Cria uma chave de API na conta atual; `$creator` é quem criou.
     *
     * @param  array{name: string, scopes?: list<string>|null, expires_at?: string|null, project_uuids?: list<string>|null}  $data
     * @return array{api_key: ApiKey, secret_key: string} A secreta em claro —
     *                                                    exibir UMA vez e descartar. Não há recuperação.
     */
    public function create(AuthUser $creator, array $data): array
    {
        $grantor = $this->grantor();

        // Pela API, o padrão é o que a chave autenticada já pode; no painel,
        // o padrão da configuração.
        $scopes = $data['scopes'] ?? ($grantor !== null ? $grantor->scopes : config('api_keys.default_scopes', ['*:*']));
        $projectIds = $this->resolveProjectIds($data['project_uuids'] ?? null);

        if ($grantor !== null) {
            $this->assertScopesWithin($grantor, $scopes);

            // Chave autenticada restrita a projetos: sem projetos no pedido, a
            // nova herda os dela; com projetos, eles têm de estar entre os dela.
            if ($grantor->isRestrictedToProjects() && ($data['project_uuids'] ?? []) === []) {
                $projectIds = $this->grantorProjectIds($grantor);
            }

            $this->assertProjectsWithin($grantor, $projectIds, $grantor->isRestrictedToProjects());
        }

        $pair = $this->generator->generatePair();

        /** @var ApiKey $apiKey */
        $apiKey = DB::transaction(function () use ($creator, $data, $pair, $scopes, $projectIds, $grantor): ApiKey {
            /** @var ApiKey $apiKey */
            $apiKey = ApiKey::createWithPublicCodeRetry([
                'created_by' => $creator->getKey(),
                'name' => $data['name'],
                'public_key' => $pair['public_key'],
                // SOMENTE o hash — nunca a sk_ em claro.
                'secret_hash' => $this->hasher->hash($pair['secret_key']),
                'scopes' => $scopes,
                'expires_at' => $data['expires_at'] ?? null,
                'status' => ApiKeyStatus::Active,
            ]);

            $this->applyProjects($apiKey, $projectIds, $grantor !== null && $grantor->isRestrictedToProjects());

            return $apiKey;
        });

        return ['api_key' => $apiKey, 'secret_key' => $pair['secret_key']];
    }

    /**
     * Rotaciona a chave: gera substituta herdando nome, scopes e projetos.
     *
     * @param  int|null  $gracePeriodMinutes  Morte da antiga: nulo/0 = imediata;
     *                                        positivo = janela de coexistência
     *                                        (escolha do usuário).
     * @return array{api_key: ApiKey, secret_key: string} Nova chave + secreta
     *                                                    em claro (exibida UMA vez).
     *
     * @throws InvalidArgumentException A chave não está ativa (não rotacionável).
     */
    public function rotate(ApiKey $current, ?int $gracePeriodMinutes): array
    {
        if ($current->status !== ApiKeyStatus::Active) {
            throw new InvalidArgumentException('Somente chaves ativas podem ser rotacionadas.');
        }

        // A rotação entrega a secreta NOVA a quem pediu: pela API, só de chave
        // que não seja mais ampla que a autenticada (a si mesma, sempre).
        $grantor = $this->grantor();

        if ($grantor !== null) {
            $this->assertScopesWithin($grantor, $current->scopes);
            $this->assertProjectsWithin($grantor, $this->keyProjectIds($current), $current->isRestrictedToProjects());
        }

        $pair = $this->generator->generatePair();

        /** @var ApiKey $newKey */
        $newKey = DB::transaction(function () use ($current, $gracePeriodMinutes, $pair): ApiKey {
            /** @var ApiKey $newKey */
            // Mesma conta da antiga (a atual — o escopo não deixa achar chave
            // de outra); quem rotacionou vira o `created_by` da nova.
            $newKey = ApiKey::createWithPublicCodeRetry([
                'account_id' => $current->account_id,
                'name' => $current->name,
                'public_key' => $pair['public_key'],
                'secret_hash' => $this->hasher->hash($pair['secret_key']),
                'scopes' => $current->scopes,
                // Herda a RESTRIÇÃO, não só a lista: a chave restrita cujos
                // projetos foram todos excluídos continua sem acesso algum.
                'restricted_to_projects' => $current->isRestrictedToProjects(),
                'expires_at' => $current->expires_at,
                'rotated_from_id' => $current->id,
                'status' => ApiKeyStatus::Active,
            ]);

            $newKey->projects()->sync($current->projects()->pluck('projects.id'));

            // Morte da antiga: imediata (sem grace) ou programada (grace_ends_at).
            $immediate = $gracePeriodMinutes === null || $gracePeriodMinutes <= 0;

            $current->forceFill([
                'rotated_to_id' => $newKey->id,
                'status' => $immediate ? ApiKeyStatus::Rotated : ApiKeyStatus::Active,
                'grace_ends_at' => $immediate ? now() : now()->addMinutes($gracePeriodMinutes),
            ])->save();

            return $newKey;
        });

        return ['api_key' => $newKey, 'secret_key' => $pair['secret_key']];
    }

    /**
     * Revoga a chave (irreversível). Idempotente: revogar de novo não falha.
     */
    public function revoke(ApiKey $apiKey): void
    {
        if ($apiKey->status === ApiKeyStatus::Revoked) {
            return;
        }

        $apiKey->forceFill(['status' => ApiKeyStatus::Revoked])->save();
    }

    /**
     * Define o vínculo chave ↔ projetos — o ÚNICO ponto que muda a restrição.
     *
     * Lista com projetos = chave restrita a eles. Lista vazia = chave de conta
     * toda: é a ação explícita de quem gerencia a conta. Os ids já
     * devem ter passado por resolveProjectIds() (projetos da conta atual).
     *
     * A restrição NÃO é recalculada quando um projeto é excluído — a cascata
     * remove o vínculo e a chave segue restrita, agora a menos projetos (ou a
     * nenhum). É isso que impede que excluir projeto amplie o acesso.
     *
     * @param  list<int>  $projectIds
     */
    public function syncProjects(ApiKey $apiKey, array $projectIds): void
    {
        // Pela API: só edita chave que não seja mais ampla que a autenticada,
        // e (se ela for restrita) só dentro dos projetos dela — lista vazia,
        // que é "conta toda", fica de fora.
        $grantor = $this->grantor();

        if ($grantor !== null) {
            $this->assertScopesWithin($grantor, $apiKey->scopes);
            $this->assertProjectsWithin($grantor, $projectIds, $projectIds !== []);
        }

        $this->applyProjects($apiKey, $projectIds, $projectIds !== []);
    }

    /**
     * @param  list<int>  $projectIds
     */
    private function applyProjects(ApiKey $apiKey, array $projectIds, bool $restricted): void
    {
        DB::transaction(function () use ($apiKey, $projectIds, $restricted): void {
            $apiKey->forceFill(['restricted_to_projects' => $restricted || $projectIds !== []])->save();
            $apiKey->projects()->sync($projectIds);
        });
    }

    /**
     * A chave de API que está agindo (a requisição autenticada por chave), ou
     * null fora da API (o painel, um comando).
     */
    private function grantor(): ?ApiKey
    {
        return app(TenantContext::class)->apiKey();
    }

    /**
     * Cada escopo pedido cabe nos da chave autenticada? `ApiKey::allows()` faz
     * o curinga do lado de quem concede: `orders:*` cobre `orders:create` e
     * `orders:*`; `*:*` só é coberto por `*:*`.
     *
     * @param  list<string>|null  $scopes
     *
     * @throws ApiKeyPrivilegeExceededException
     */
    private function assertScopesWithin(ApiKey $grantor, ?array $scopes): void
    {
        $exceeding = array_values(array_filter(
            array_map('strval', $scopes ?? ['*:*']),
            static fn (string $scope): bool => ! $grantor->allows($scope),
        ));

        if ($exceeding !== []) {
            $this->refuse(ApiKeyPrivilegeExceededException::scopes($exceeding));
        }
    }

    /**
     * Chave autenticada restrita a projetos: o alvo tem de ser restrito e
     * ficar dentro dos projetos dela.
     *
     * @param  list<int>  $projectIds
     *
     * @throws ApiKeyPrivilegeExceededException
     */
    private function assertProjectsWithin(ApiKey $grantor, array $projectIds, bool $targetRestricted): void
    {
        if (! $grantor->isRestrictedToProjects()) {
            return;
        }

        if (! $targetRestricted || array_diff($projectIds, $this->grantorProjectIds($grantor)) !== []) {
            $this->refuse(ApiKeyPrivilegeExceededException::projects());
        }
    }

    /**
     * @return list<int>
     */
    private function grantorProjectIds(ApiKey $grantor): array
    {
        return $this->keyProjectIds($grantor);
    }

    /**
     * @return list<int>
     */
    private function keyProjectIds(ApiKey $apiKey): array
    {
        return array_values(array_map('intval', $apiKey->projects()->pluck('projects.id')->all()));
    }

    /**
     * Grava a recusa na trilha (contexto `api`, a chave autenticada como alvo)
     * e lança o 403 com o código estável.
     *
     * @throws ApiKeyPrivilegeExceededException
     */
    private function refuse(ApiKeyPrivilegeExceededException $exception): never
    {
        $context = app(TenantContext::class);

        app(AccountAudit::class)->denied(
            ApiKeyAttempt::PrivilegeExceeded,
            $context->account(),
            $context->user(),
            $exception->getMessage(),
            $context->apiKey(),
            context: AuditContext::Api,
        );

        throw $exception;
    }

    /**
     * Resolve os UUIDs de projetos (N:N) garantindo que pertencem à conta
     * atual (escopo das contas) — nunca vincula projeto de outra conta.
     *
     * @param  list<string>|null  $projectUuids
     * @return list<int>
     *
     * @throws InvalidArgumentException Algum projeto não existe na conta.
     */
    public function resolveProjectIds(?array $projectUuids): array
    {
        if ($projectUuids === null || $projectUuids === []) {
            return [];
        }

        $projectUuids = array_values(array_unique($projectUuids));

        // Texto que não é uuid = projeto que não existe (os Form Requests já
        // barram, isto protege quem chama o service direto): no PostgreSQL ele
        // derrubaria a consulta em vez de simplesmente não encontrar.
        if (count(UuidColumn::onlyValid($projectUuids)) !== count($projectUuids)) {
            throw new InvalidArgumentException('projects');
        }

        /** @var list<int> $ids */
        $ids = Project::query()
            ->whereIn('uuid', $projectUuids)
            ->pluck('id')
            ->all();

        if (count($ids) !== count($projectUuids)) {
            throw new InvalidArgumentException('projects');
        }

        return $ids;
    }
}
