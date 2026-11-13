<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// LIMITES DA API POR CONTA (Twstec\Kit\Accounts\Tenancy\Limits\ApiLimits).
//
// Dois níveis, todos nulos = "segue o de cima":
// - `api_quota`, `api_burst_per_minute`, `api_concurrency`: o valor que a
//   PLATAFORMA dá a esta conta (o /admin sobe ou baixa); nulo = o padrão da
//   configuração (`api_keys.limits`).
// - `*_own`: a REDUÇÃO escolhida pelo dono da conta; nunca acima do valor da
//   plataforma (o que vale é o menor dos dois).
//
// Nada muda para as contas existentes: as colunas nascem nulas.
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedInteger('api_quota')->nullable();
            $table->unsignedInteger('api_burst_per_minute')->nullable();
            $table->unsignedInteger('api_concurrency')->nullable();
            $table->unsignedInteger('api_quota_own')->nullable();
            $table->unsignedInteger('api_burst_per_minute_own')->nullable();
            $table->unsignedInteger('api_concurrency_own')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'api_quota',
                'api_burst_per_minute',
                'api_concurrency',
                'api_quota_own',
                'api_burst_per_minute_own',
                'api_concurrency_own',
            ]);
        });
    }
};
