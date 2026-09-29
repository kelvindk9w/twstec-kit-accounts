<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// A restrição a projetos passa a ser um ESTADO DA CHAVE, não uma consequência
// da lista de vínculos.
//
// Antes, "chave sem vínculo = conta toda" era deduzido da tabela pivô vazia.
// Efeito colateral: excluir o último projeto vinculado apagava (em cascata) o
// último vínculo e a chave, que era restrita, passava a enxergar a conta toda.
// Com a coluna, excluir projeto só encolhe a lista: a chave restrita a nada
// continua restrita (fail-closed). Voltar à conta toda é sempre uma ação
// explícita de quem gerencia as chaves.
//
// Backfill: toda chave que hoje tem vínculo nasce restrita; as demais seguem
// com acesso à conta toda, exatamente como se comportavam.
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->boolean('restricted_to_projects')->default(false)->after('scopes');
        });

        DB::table('api_keys')
            ->whereIn('id', DB::table('api_key_project')->select('api_key_id'))
            ->update(['restricted_to_projects' => true]);
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('restricted_to_projects');
        });
    }
};
