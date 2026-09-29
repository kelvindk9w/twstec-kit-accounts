<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Motor de API Keys + Tenancy.
//
// projects: camada ORGANIZACIONAL da conta (multi-empresa/projeto).
// Por ora nasce SÓ COM NOME (dados formais — CNPJ etc. — só quando necessário).
// No MVP é metadado para separar dados e visões; a custódia segue uma por conta.
//
// Identificadores (3 camadas, anti-enumeração): `id` interno nunca exposto; `uuid`
// externo; `codigo_publico` legível PRJ-xxxxxx (UNIQUE — a unicidade é do banco).
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('codigo_publico', 32)->unique();
            // Dono do projeto (tenant). 1 login gerencia N projetos.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Só nome por ora.
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
