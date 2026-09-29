<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Regra ÚNICA do CRUD de projetos — a tela do painel (Livewire) e a API v1
 * chamam este serviço; nenhuma das duas lê ou grava Project direto.
 *
 * Posse: o projeto é da CONTA, e toda consulta sai filtrada pela conta atual
 * pelo escopo das contas (no painel, a conta selecionada da pessoa; na API, a
 * conta da chave) — sem conta atual, exceção. Por cima disso, na API, o
 * recorte da chave: Project::visibleToApiKey() — se a chave é restrita
 * (`restricted_to_projects`), só os vinculados a ela, inclusive nenhum
 * (fail-closed).
 *
 * Projeto fora do recorte, de outra conta ou inexistente é a MESMA exceção
 * (ModelNotFoundException → 404 uniforme): não revela que o recurso existe.
 * Atualizar e excluir recebem o projeto já achado por um dos recortes.
 *
 * Quem pode fazer o quê (papel na conta) é decidido antes, por quem chama —
 * ver Account\Enums\AccountRole e, nas telas, Account\Support\AccountResourceGuard
 * (a recusa e o projeto fora da conta atual ficam na trilha).
 */
final class ProjectService
{
    /**
     * Atributos que a atualização aceita — o resto é ignorado (o dono e os
     * identificadores nunca mudam por aqui).
     */
    private const UPDATABLE = ['name', 'status'];

    /**
     * Projetos da conta atual, mais novos primeiro, com a contagem de chaves
     * vinculadas (a lista do painel).
     *
     * @return Collection<int, Project>
     */
    public function list(): Collection
    {
        return Project::query()
            ->withCount('apiKeys')
            ->latest()
            ->get();
    }

    /**
     * Projetos que a chave enxerga, mais novos primeiro, paginados (a lista
     * da API).
     *
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginateForApiKey(ApiKey $apiKey, int $perPage): LengthAwarePaginator
    {
        return $this->visibleTo($apiKey)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Projeto da conta atual pelo UUID.
     *
     * @throws ModelNotFoundException<Project> De outra conta ou inexistente.
     */
    public function find(string $uuid): Project
    {
        /** @var Project */
        return Project::query()
            ->byUuid($uuid)
            ->firstOrFail();
    }

    /**
     * Projeto que a chave enxerga pelo UUID.
     *
     * @throws ModelNotFoundException<Project> De outra conta, fora do vínculo da chave ou inexistente.
     */
    public function findForApiKey(ApiKey $apiKey, string $uuid): Project
    {
        /** @var Project */
        return $this->visibleTo($apiKey)
            ->byUuid($uuid)
            ->firstOrFail();
    }

    /**
     * Cria o projeto só com nome, NA CONTA ATUAL, com o código público
     * PRJ-xxxxxx gerado (com nova tentativa em caso de colisão) e quem criou.
     *
     * Quem pode criar é decidido antes: no painel, o papel da pessoa na conta;
     * na API, só chave de conta (middleware account.key).
     */
    public function create(AuthUser $creator, string $name): Project
    {
        /** @var Project */
        return Project::createWithPublicCodeRetry([
            'created_by' => $creator->getKey(),
            'name' => $name,
        ]);
    }

    /**
     * Atualiza nome e/ou status de um projeto já achado por um dos recortes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Project $project, array $attributes): Project
    {
        $project->update(Arr::only($attributes, self::UPDATABLE));

        return $project;
    }

    /**
     * Exclui um projeto já achado por um dos recortes.
     *
     * O vínculo N:N com as chaves cai junto (cascadeOnDelete na pivot). A
     * chave que só atendia este projeto CONTINUA restrita — agora a nenhum
     * projeto (fail-closed); voltar à conta toda é ação explícita de quem
     * gerencia as chaves.
     */
    public function delete(Project $project): void
    {
        $project->delete();
    }

    /**
     * @return Builder<Project>
     */
    private function visibleTo(ApiKey $apiKey): Builder
    {
        return Project::query()->visibleToApiKey($apiKey);
    }
}
