<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Twstec\Kit\Accounts\Account\Concerns\BelongsToAccount;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Foundation\Identifiers\HasPublicCode;
use Twstec\Kit\Foundation\Identifiers\RoutesByUuid;

/**
 * Chave de API — motor de acesso programático.
 *
 * Pertence à CONTA (BelongsToAccount: toda consulta sai filtrada pela conta
 * atual) e é ela que a chave autentica. `created_by` guarda quem criou; a
 * chave continua valendo quando essa pessoa sai da conta.
 *
 * Par pública/secreta:
 * - `public_key` (pk_live_/pk_test_...): identificador público, indexado.
 * - Da SECRETA (sk_...) existe SOMENTE o hash (`secret_hash`, HMAC-SHA256 com
 *   pepper). A sk_ em claro é exibida UMA única vez (criação/rotação) e nunca
 *   toca o banco; a verificação é timing-safe.
 *
 * Scopes (jsonb): permissões granulares "recurso:acao" (ex.: customers:read,
 * pix:create, withdrawals:*). Padrão na criação: ['*:*'] (tudo habilitado).
 * Ver allows().
 *
 * Ciclo de vida: validade opcional (expires_at — vazio = sem validade),
 * rotação com morte imediata ou programada (grace_ends_at) e expiração por
 * inatividade (job diário — ver config/api_keys.php).
 */
#[Fillable([
    'account_id', 'created_by', 'name', 'public_key', 'secret_hash', 'scopes', 'restricted_to_projects',
    'expires_at', 'last_used_at', 'inactivity_warning_sent_at',
    'rotated_from_id', 'rotated_to_id', 'grace_ends_at', 'status',
])]
#[Hidden(['secret_hash'])]
class ApiKey extends Model
{
    use BelongsToAccount, HasPublicCode, HasUuids, RoutesByUuid;

    /**
     * Prefixo do código público legível: KEY-xxxxxx.
     */
    protected const PUBLIC_CODE_PREFIX = 'KEY';

    /**
     * Default da instância nova (espelha o default da migration).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'restricted_to_projects' => false,
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
            'scopes' => 'array',
            'restricted_to_projects' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'inactivity_warning_sent_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'status' => ApiKeyStatus::class,
        ];
    }

    /**
     * Projetos vinculados (N:N). Quem decide se a chave é
     * restrita é `restricted_to_projects`, não esta lista — ver
     * isRestrictedToProjects().
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'api_key_project')->withTimestamps();
    }

    /**
     * A chave está RESTRITA aos projetos vinculados?
     *
     * Restrita: só enxerga os projetos da lista (que pode ficar vazia quando
     * os projetos são excluídos — aí não enxerga nenhum, fail-closed) e não
     * faz operação de conta (criar projeto, gerenciar chaves). Não restrita:
     * conta toda. É um estado da chave, gravado quando o vínculo é definido
     * (ApiKeyService), para que excluir projeto nunca amplie o acesso.
     */
    public function isRestrictedToProjects(): bool
    {
        return (bool) $this->restricted_to_projects;
    }

    /**
     * Chave da qual esta foi rotacionada (encadeamento da rotação).
     *
     * @return BelongsTo<ApiKey, $this>
     */
    public function rotatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rotated_from_id');
    }

    /**
     * Chave que substituiu esta na rotação.
     *
     * @return BelongsTo<ApiKey, $this>
     */
    public function rotatedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rotated_to_id');
    }

    /**
     * A chave tem permissão para o scope informado ("recurso:acao")?
     *
     * Casamento exato ou wildcard em qualquer lado: `*:*` (tudo),
     * `customers:*` (todas as ações do recurso).
     *
     * @param  string  $scope  Ex.: "customers:read", "pix:create".
     */
    public function allows(string $scope): bool
    {
        /** @var list<string> $grants */
        $grants = $this->scopes ?? [];

        [$resource, $action] = array_pad(explode(':', $scope, 2), 2, '*');

        foreach ($grants as $grant) {
            [$grantResource, $grantAction] = array_pad(explode(':', (string) $grant, 2), 2, '*');

            if (($grantResource === '*' || $grantResource === $resource)
                && ($grantAction === '*' || $grantAction === $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A chave está utilizável NESTE momento? Checagem completa do middleware
     * de tenancy: status, validade, grace period da rotação e inatividade.
     */
    public function isUsable(): bool
    {
        if ($this->status !== ApiKeyStatus::Active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        // Chave antiga em rotação: morre quando o grace period termina.
        if ($this->grace_ends_at !== null && $this->grace_ends_at->isPast()) {
            return false;
        }

        return ! $this->isInactive();
    }

    /**
     * Sem uso há mais tempo que o limite de inatividade configurado?
     * A última atividade é o last_used_at ou, na ausência, a criação.
     */
    public function isInactive(): bool
    {
        /** @var array{enabled: bool, months: int} $config */
        $config = config('api_keys.inactivity');

        if (! $config['enabled']) {
            return false;
        }

        return $this->lastActivityAt()->lt(now()->subMonthsNoOverflow($config['months']));
    }

    /**
     * Momento da última atividade da chave (uso autenticado ou criação).
     */
    public function lastActivityAt(): Carbon
    {
        /** @var Carbon */
        return $this->last_used_at ?? $this->created_at;
    }

    /**
     * Atualiza o last_used_at de forma THROTTLED (no máximo 1 escrita a cada
     * N segundos — config api_keys.last_used_throttle_seconds): a request não
     * paga um UPDATE a cada chamada. O uso também REARMA o aviso de
     * inatividade (limpa a flag) para um futuro ciclo de inatividade.
     */
    public function touchLastUsedThrottled(): void
    {
        $throttleSeconds = (int) config('api_keys.last_used_throttle_seconds', 60);
        $cutoff = now()->subSeconds($throttleSeconds);

        static::query()
            ->where('id', $this->id)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', $cutoff);
            })
            ->update([
                'last_used_at' => now(),
                'inactivity_warning_sent_at' => null,
            ]);
    }
}
