<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Convites para entrar numa conta.
//
// - O TOKEN do link nunca é gravado: só o hash SHA-256 (`token_hash`, único).
//   Quem tem acesso ao banco não consegue montar um link válido.
// - Uso ÚNICO: o aceite grava `accepted_at` com UPDATE condicional (só se o
//   convite ainda está pendente) — dois aceites ao mesmo tempo não viram dois
//   membros.
// - Pendente = sem `accepted_at`, `revoked_at` e `declined_at`, e antes de
//   `expires_at`. O reenvio troca o hash (o link antigo morre) e renova a
//   validade.
// - `role`: admin ou member (dono só por transferência).
// - `created_by`: quem convidou (pode sair da conta; o convite continua da
//   conta). `accepted_by`: quem entrou por ele.
// - É dado DA CONTA: o model tem o escopo da conta atual (a busca pelo token,
//   no aceite, é a única leitura em modo sistema — ver InvitationTokens).
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('account_invitations')) {
            return;
        }

        Schema::create('account_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_sent_at')->nullable();
            $table->unsignedInteger('send_count')->default(1);
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->timestampsTz();

            $table->index(['account_id', 'email']);
            $table->index(['account_id', 'accepted_at', 'revoked_at', 'declined_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitations');
    }
};
