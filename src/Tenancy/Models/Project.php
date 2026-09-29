<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Twstec\Kit\Accounts\Account\Concerns\BelongsToAccount;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Enums\ProjectStatus;
use Twstec\Kit\Foundation\Identifiers\HasPublicCode;
use Twstec\Kit\Foundation\Identifiers\RoutesByUuid;

/**
 * Projeto — camada ORGANIZACIONAL da conta.
 *
 * Pertence à CONTA (BelongsToAccount: toda consulta sai filtrada pela conta
 * atual; `created_by` guarda quem criou). Uma conta tem N projetos. Por ora
 * o projeto nasce SÓ COM NOME
 * (pode existir sem empresa — ex.: pessoa antes de abrir CNPJ). Hoje é
 * metadado para separar dados e visões; a custódia segue uma por conta.
 *
 * Identificadores (3 camadas — anti-enumeração): `id` nunca exposto; `uuid` externo;
 * `codigo_publico` legível PRJ-xxxxxx.
 */
#[Fillable(['account_id', 'created_by', 'name', 'status'])]
class Project extends Model
{
    use BelongsToAccount, HasPublicCode, HasUuids, RoutesByUuid;

    /**
     * Prefixo do código público legível: PRJ-xxxxxx.
     */
    protected const PUBLIC_CODE_PREFIX = 'PRJ';

    /**
     * Default da instância nova (espelha o default da migration) — sem isso,
     * o status ficaria null até o primeiro refresh após o INSERT.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Coluna preenchida automaticamente com UUID v7 na criação (HasUuids).
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
        ];
    }

    /**
     * Projetos que a CHAVE DE API enxerga: os da conta (o escopo da conta
     * atual — na API, a conta da chave); se a chave é restrita, só os
     * vinculados a ela — inclusive nenhum, quando todos os vinculados foram
     * excluídos (fail-closed).
     *
     * Toda consulta de projeto feita em nome de uma chave passa por aqui.
     *
     * @param  Builder<Project>  $query
     */
    public function scopeVisibleToApiKey(Builder $query, ApiKey $apiKey): void
    {
        if ($apiKey->isRestrictedToProjects()) {
            $query->whereHas('apiKeys', fn (Builder $keys) => $keys->whereKey($apiKey->getKey()));
        }
    }

    /**
     * Chaves de API vinculadas ao projeto (N:N).
     *
     * @return BelongsToMany<ApiKey, $this>
     */
    public function apiKeys(): BelongsToMany
    {
        return $this->belongsToMany(ApiKey::class, 'api_key_project')->withTimestamps();
    }
}
