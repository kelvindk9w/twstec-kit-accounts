<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Http\Resources;

use Illuminate\Http\Request;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Foundation\Http\Resources\BaseResource;

/**
 * Serialização da chave de API (nunca expor campos internos).
 *
 * NUNCA inclui secret_hash nem qualquer forma da secreta. A sk_ em claro só
 * sai na resposta de criação/rotação, fora deste Resource (campo avulso
 * `secret_key` no envelope — exibição única).
 *
 * @mixin ApiKey
 */
final class ApiKeyResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->publicIdentifiers($this->resource),
            'name' => $this->name,
            'public_key' => $this->public_key,
            'scopes' => $this->scopes,
            'status' => $this->status->value,
            'expires_at' => $this->isoTimestamp($this->expires_at),
            'last_used_at' => $this->isoTimestamp($this->last_used_at),
            'grace_ends_at' => $this->isoTimestamp($this->grace_ends_at),
            // Alcance da chave: `account` (conta toda) ou `projects` (só os
            // listados em `projects` — que pode estar vazio: sem acesso).
            'project_access' => $this->isRestrictedToProjects() ? 'projects' : 'account',
            'projects' => $this->whenLoaded('projects', fn (): array => $this->projects
                ->map(fn ($project): array => [
                    'uuid' => $project->uuid,
                    'codigo_publico' => $project->codigo_publico,
                    'name' => $project->name,
                ])
                ->all()),
            'created_at' => $this->isoTimestamp($this->created_at),
        ];
    }
}
