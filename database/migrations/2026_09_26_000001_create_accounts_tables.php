<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Contas com membros.
//
// accounts: a CONTA (a empresa, ou a conta pessoal de uma pessoa). É ela a dona
// dos dados — projetos, chaves de API — e é ela que a chave de API autentica.
// Identificadores nas 3 camadas do kit: `id` interno nunca exposto; `uuid`
// externo; `codigo_publico` legível ACC-xxxxxx.
//
// - `personal_user_id`: preenchido só na CONTA PESSOAL — a que toda pessoa
//   ganha ao ser criada, com o MESMO uuid dela (a trilha de requisições, que
//   grava o uuid do tenant, continua apontando para o identificador certo).
//   Único: uma pessoa tem no máximo uma conta pessoal. Excluir a pessoa não
//   apaga a conta por esta coluna (vira nulo); quem decide o destino da conta
//   é a regra do dono (account_memberships, abaixo).
// - `name`: nulo na conta pessoal (a tela mostra o nome da pessoa, que é dado
//   pessoal cifrado na tabela `users` e não é copiado para cá).
//
// account_memberships: pessoa × conta, com PAPEL fixo — owner, admin ou
// member. Uma pessoa pode estar em várias contas; uma vez em cada.
//
// INVARIANTE: exatamente UM dono (owner) por conta.
// - No máximo um: índice único parcial (account_id) WHERE role = 'owner' —
//   vale no PostgreSQL e no SQLite.
// - Pelo menos um: no PostgreSQL, gatilhos instalados pela migration seguinte
//   (depois da migração dos dados): a conta nova precisa ter dono ao fim da
//   transação; o papel do dono não é rebaixado; o dono não sai de conta com
//   outros membros (a transferência é o caminho), e a saída do dono de conta
//   sem outros membros apaga a conta. No código, o model AccountMembership e o
//   AccountService aplicam a mesma regra nos dois bancos.
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            Schema::create('accounts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('codigo_publico', 32)->unique();
                $table->string('name')->nullable();
                $table->foreignId('personal_user_id')->nullable()->unique()
                    ->constrained('users')->nullOnDelete();
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('account_memberships')) {
            Schema::create('account_memberships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 20);
                $table->timestampsTz();

                $table->unique(['account_id', 'user_id']);
                $table->index(['user_id', 'role']);
            });

            // No máximo UM dono por conta — índice parcial (PostgreSQL e SQLite).
            DB::statement("CREATE UNIQUE INDEX account_memberships_one_owner ON account_memberships (account_id) WHERE role = 'owner'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_memberships');
        Schema::dropIfExists('accounts');
    }
};
