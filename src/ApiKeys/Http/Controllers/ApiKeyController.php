<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Twstec\Kit\Accounts\ApiKeys\Http\Requests\RotateApiKeyRequest;
use Twstec\Kit\Accounts\ApiKeys\Http\Requests\StoreApiKeyRequest;
use Twstec\Kit\Accounts\ApiKeys\Http\Requests\SyncApiKeyProjectsRequest;
use Twstec\Kit\Accounts\ApiKeys\Http\Resources\ApiKeyResource;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Accounts\Tenancy\TenantContext;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * API v1 do motor de chaves. Grupo protegido por resolve.tenant
 * (+ scope por rota); criação e rotação exigem ainda o token de ação
 * sensível (senha de transação + 2FA por e-mail).
 *
 * As rotas exigem chave de CONTA (middleware account.key): chave vinculada a
 * projetos não gerencia chaves, exceto rotacionar ou revogar a si mesma
 * (account.key:self) — ver EnsureAccountWideApiKey.
 *
 * Isolamento de tenant (coberto por testes de conta A x B): TODA consulta sai
 * filtrada pela conta da chave (o escopo das contas); chave de outra conta =
 * 404 uniforme (nunca 403, para não revelar existência).
 */
final class ApiKeyController
{
    public function __construct(private readonly ApiKeyService $apiKeys) {}

    /**
     * GET /api/v1/api-keys — lista as chaves da conta (scope api-keys:read).
     */
    public function index(): AnonymousResourceCollection
    {
        $keys = ApiKey::query()
            ->with('projects')
            ->latest()
            ->paginate((int) config('api_keys.pagination.per_page', 15));

        return ApiKeyResource::collection($keys);
    }

    /**
     * POST /api/v1/api-keys — cria chave (scope api-keys:create + ação
     * sensível). A secreta em claro sai UMA única vez, no campo avulso
     * `secret_key` do envelope — no banco fica somente o hash.
     */
    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        /** @var array{name: string, scopes?: list<string>|null, expires_at?: string|null, project_uuids?: list<string>|null} $data */
        $data = $request->validated();

        try {
            $result = $this->apiKeys->create($this->tenantUser(), $data);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'project_uuids' => __('api_keys.projects.invalid'),
            ]);
        }

        return ApiKeyResource::make($result['api_key']->load('projects'))
            ->additional([
                'message' => __('api_keys.keys.created'),
                // Exibição ÚNICA — não há recuperação (perdeu = rotaciona).
                'secret_key' => $result['secret_key'],
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/v1/api-keys/{uuid} — revoga a chave (scope api-keys:revoke).
     */
    public function destroy(string $uuid): JsonResponse
    {
        $apiKey = $this->findOwned($uuid);

        $this->apiKeys->revoke($apiKey);

        return response()->json([
            'message' => __('api_keys.keys.revoked'),
            'data' => ApiKeyResource::make($apiKey->refresh()),
        ]);
    }

    /**
     * POST /api/v1/api-keys/{uuid}/rotate — rotaciona (scope api-keys:rotate
     * + ação sensível). Corpo: grace_period_minutes (nulo/0 = morte imediata
     * da antiga; positivo = janela de coexistência — escolha do usuário).
     */
    public function rotate(RotateApiKeyRequest $request, string $uuid): JsonResponse
    {
        $apiKey = $this->findOwned($uuid);

        /** @var array{grace_period_minutes?: int|null} $data */
        $data = $request->validated();

        try {
            $result = $this->apiKeys->rotate($apiKey, $data['grace_period_minutes'] ?? null);
        } catch (\InvalidArgumentException) {
            abort(422, __('api_keys.keys.not_rotatable'));
        }

        return ApiKeyResource::make($result['api_key']->load('projects'))
            ->additional([
                'message' => __('api_keys.keys.rotated'),
                // Nova secreta — exibição ÚNICA.
                'secret_key' => $result['secret_key'],
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PUT /api/v1/api-keys/{uuid}/projects — vínculo N:N chave ↔ projetos
     * (scope api-keys:assign; só por chave de conta — account.key). Lista com
     * projetos = chave restrita a eles; lista vazia = conta toda.
     */
    public function syncProjects(SyncApiKeyProjectsRequest $request, string $uuid): JsonResponse
    {
        $apiKey = $this->findOwned($uuid);

        /** @var array{project_uuids: list<string>} $data */
        $data = $request->validated();

        try {
            $projectIds = $this->apiKeys->resolveProjectIds($data['project_uuids']);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'project_uuids' => __('api_keys.projects.invalid'),
            ]);
        }

        $this->apiKeys->syncProjects($apiKey, $projectIds);

        return response()->json([
            'message' => __('api_keys.keys.projects_synced'),
            'data' => ApiKeyResource::make($apiKey->refresh()->load('projects')),
        ]);
    }

    /**
     * Localiza a chave da conta pelo UUID — 404 uniforme para chave de
     * outra conta ou inexistente (anti-IDOR/BOLA: não revela que o recurso existe).
     */
    private function findOwned(string $uuid): ApiKey
    {
        /** @var ApiKey */
        return ApiKey::query()
            ->byUuid($uuid)
            ->firstOrFail();
    }

    /**
     * A pessoa por trás da chave (o ResolveTenant garante; é o `created_by`
     * da chave criada por aqui).
     */
    private function tenantUser(): AuthUser
    {
        /** @var AuthUser */
        return app(TenantContext::class)->user() ?? throw new \LogicException('Rota sem resolve.tenant.');
    }
}
