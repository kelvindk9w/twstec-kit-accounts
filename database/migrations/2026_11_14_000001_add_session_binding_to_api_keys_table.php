<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// CHAVE DE API DE SESSÃO: a chave criada presa a uma sessão web
// (ApiKeyService::createForSession) morre quando a sessão termina.
//
// - `session_binding`: a REFERÊNCIA do vínculo da sessão (SHA-256 de um valor
//   aleatório guardado dentro da sessão — Twstec\Kit\Auth\Sessions\
//   SessionBinding). Nunca o id da sessão. Nula = chave comum (todas as que
//   já existem continuam assim).
// - `session_seen_at`: a última vez que a sessão foi vista (renovada pelo
//   grupo `web`, com intervalo mínimo). Sessão sem requisição há mais que o
//   SESSION_LIFETIME venceu, e a chave dela também.
//
// Nada muda para as chaves existentes: as duas colunas nascem nulas.
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('session_binding', 64)->nullable()->after('restricted_to_projects');
            $table->timestampTz('session_seen_at')->nullable()->after('session_binding');

            $table->index('session_binding');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropIndex(['session_binding']);
            $table->dropColumn(['session_binding', 'session_seen_at']);
        });
    }
};
