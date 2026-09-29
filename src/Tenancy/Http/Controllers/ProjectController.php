<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Http\Requests\StoreProjectRequest;
use Twstec\Kit\Accounts\Tenancy\Http\Requests\UpdateProjectRequest;
use Twstec\Kit\Accounts\Tenancy\Http\Resources\ProjectResource;
use Twstec\Kit\Accounts\Tenancy\Services\ProjectService;
use Twstec\Kit\Accounts\Tenancy\TenantContext;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * CRUD de projetos da API v1 (camada organizacional).
 *
 * A regra mora no ProjectService (o mesmo que o painel usa); aqui ficam só o
 * HTTP e o envelope das respostas.
 *
 * Isolamento: TODA consulta sai filtrada pela conta da chave (escopo das
 * contas) e passa pelo recorte da chave no ProjectService
 * (Project::visibleToApiKey()) — se a chave é vinculada a projetos, só os
 * vinculados. Projeto de outra conta, ou da mesma conta fora do vínculo = 404
 * uniforme (não revela existência). Criar projeto é operação de conta: a rota
 * exige chave sem vínculo (middleware account.key).
 */
final class ProjectController
{
    public function __construct(private readonly ProjectService $projects) {}

    /**
     * GET /api/v1/projects — lista os projetos da conta (scope projects:read).
     */
    public function index(): AnonymousResourceCollection
    {
        $projects = $this->projects->paginateForApiKey(
            $this->apiKey(),
            (int) config('api_keys.pagination.per_page', 15),
        );

        return ProjectResource::collection($projects);
    }

    /**
     * POST /api/v1/projects — cria projeto só com nome (scope projects:create).
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        /** @var array{name: string} $data */
        $data = $request->validated();

        $project = $this->projects->create($this->tenantUser(), $data['name']);

        return ProjectResource::make($project)
            ->additional(['message' => __('api_keys.projects.created')])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/projects/{uuid} — detalhe (scope projects:read).
     */
    public function show(string $uuid): JsonResponse
    {
        return response()->json([
            'data' => ProjectResource::make($this->projects->findForApiKey($this->apiKey(), $uuid)),
        ]);
    }

    /**
     * PUT /api/v1/projects/{uuid} — atualiza nome/status (scope projects:update).
     */
    public function update(UpdateProjectRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->findForApiKey($this->apiKey(), $uuid);

        /** @var array{name?: string, status?: string} $data */
        $data = $request->validated();

        $this->projects->update($project, $data);

        return response()->json([
            'message' => __('api_keys.projects.updated'),
            'data' => ProjectResource::make($project->refresh()),
        ]);
    }

    /**
     * DELETE /api/v1/projects/{uuid} — remove (scope projects:delete).
     */
    public function destroy(string $uuid): JsonResponse
    {
        $this->projects->delete($this->projects->findForApiKey($this->apiKey(), $uuid));

        return response()->json(['message' => __('api_keys.projects.deleted')]);
    }

    private function apiKey(): ApiKey
    {
        return tenantKey() ?? throw new \LogicException('Rota sem resolve.tenant.');
    }

    private function tenantUser(): AuthUser
    {
        /** @var AuthUser */
        return app(TenantContext::class)->user() ?? throw new \LogicException('Rota sem resolve.tenant.');
    }
}
