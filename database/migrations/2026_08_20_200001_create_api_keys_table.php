<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Motor de API Keys + vínculo N:N com projetos.
//
// api_keys:
// - Par pública/secreta: `public_key` (pk_live_/pk_test_...) é o identificador
//   indexado; da SECRETA (sk_...) fica SOMENTE o hash (HMAC-SHA256 com pepper
//   — ver config/api_keys.php). NUNCA plaintext.
// - `scopes`: jsonb com permissões granulares recurso:ação (padrão: ['*:*']).
// - `expires_at` nullable: vazio = sem validade (validade 100% do usuário).
// - `last_used_at`: atualizado de forma throttled pelo ResolveTenant.
// - Rotação: `rotated_from_id`/`rotated_to_id` encadeiam antiga ↔ nova;
//   `grace_ends_at` é a morte programada da antiga (escolha do usuário).
// - Expiração por inatividade: `inactivity_warning_sent_at` registra o aviso
//   prévio por e-mail (não repetir); o job diário marca expired_inactivity.
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('codigo_publico', 32)->unique(); // KEY-xxxxxx
            // Dono da chave = tenant que ela resolve.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Chave pública: identificação/lookup. Indexada e única.
            $table->string('public_key', 64)->unique();
            // SOMENTE o hash da secreta — nunca a sk_ em claro.
            $table->string('secret_hash', 64);
            // Permissões granulares recurso:acao. Padrão: ['*:*'].
            $table->jsonb('scopes');
            // Validade opcional definida pelo usuário (vazio = sem validade).
            $table->timestampTz('expires_at')->nullable();
            // Último uso autenticado (throttled — no máximo 1x/minuto).
            $table->timestampTz('last_used_at')->nullable();
            // Flag do aviso prévio de expiração por inatividade (e-mail).
            $table->timestampTz('inactivity_warning_sent_at')->nullable();
            // Rotação: encadeamento antiga ↔ nova.
            $table->foreignId('rotated_from_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->foreignId('rotated_to_id')->nullable()->constrained('api_keys')->nullOnDelete();
            // Morte programada da chave antiga após a rotação (grace period).
            $table->timestampTz('grace_ends_at')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
        });

        // N:N chaves ↔ projetos: liberdade total de organização.
        // Chave SEM vínculo enxerga a conta toda; vinculada restringe.
        Schema::create('api_key_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['api_key_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_project');
        Schema::dropIfExists('api_keys');
    }
};
