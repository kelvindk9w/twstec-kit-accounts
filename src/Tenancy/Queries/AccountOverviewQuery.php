<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

/**
 * Visão geral da conta (o dashboard do cliente): chaves, projetos e o
 * tráfego real da API do tenant (request_logs).
 *
 * Mora no backend para que qualquer tela — o painel Livewire hoje, um painel
 * React amanhã — mostre os MESMOS números sem refazer a conta.
 *
 * Isolamento: tudo é da CONTA ATUAL — chaves e projetos pelo escopo das
 * contas (sem conta atual, exceção); o tráfego por `request_logs.tenant_uuid`
 * = uuid da conta, vinculado pelo middleware ResolveTenant (a trilha de
 * requisições é do foundation e não tem o escopo; na conta pessoal o uuid é o
 * da pessoa, o mesmo gravado na 1.x). Log sem tenant não é da conta e nunca
 * entra.
 */
final class AccountOverviewQuery
{
    /** Janela padrão do gráfico de requisições por dia. */
    public const CHART_DAYS = 30;

    /** Janela padrão da métrica "requisições recentes". */
    public const RECENT_DAYS = 7;

    /** Quantas chamadas entram, por padrão, na lista de atividade. */
    public const RECENT_CALLS = 5;

    public function forCurrentAccount(
        int $chartDays = self::CHART_DAYS,
        int $recentDays = self::RECENT_DAYS,
        int $recentCalls = self::RECENT_CALLS,
    ): AccountOverview {
        $tenantUuid = (string) Accounts::currentOrFail()->uuid;

        // A janela é contada no fuso de EXIBIÇÃO (o "dia" do gráfico é o dia
        // do usuário, não o dia UTC): sem isso a série ganha um dia a mais na
        // ponta e o primeiro rótulo fica pela metade.
        $timezone = platform()->displayTimezone;
        $today = Carbon::now($timezone)->startOfDay();
        $since = $today->copy()->subDays($chartDays - 1);

        // UMA leitura da janela do gráfico serve o gráfico E o contador
        // recente: agrupar por dia em PHP mantém a consulta portável entre
        // PostgreSQL (dev/prod) e SQLite (suíte Pest) sem SQL específico de
        // driver — a janela é pequena por definição.
        $window = RequestLog::query()
            ->where('tenant_uuid', $tenantUuid)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->pluck('created_at');

        return new AccountOverview(
            activeKeysCount: ApiKey::query()
                ->where('status', ApiKeyStatus::Active)
                ->count(),
            projectsCount: Project::query()->count(),
            recentRequestsCount: $window
                ->filter(fn (Carbon $moment): bool => $moment->greaterThanOrEqualTo(
                    $today->copy()->subDays($recentDays - 1),
                ))
                ->count(),
            recentRequestsDays: $recentDays,
            lastKeyUsedAt: ApiKey::query()
                ->whereNotNull('last_used_at')
                ->max('last_used_at'),
            chartDays: $chartDays,
            chart: $this->dailySeries($window, $since, $today, $timezone),
            recentCalls: RequestLog::query()
                ->where('tenant_uuid', $tenantUuid)
                ->latest('created_at')
                ->limit($recentCalls)
                ->get(['uuid', 'method', 'endpoint', 'status', 'http_status_response', 'created_at']),
        );
    }

    /**
     * Série diária COMPLETA da janela: dias sem tráfego entram com zero.
     * Sem isso o gráfico mentiria — ligaria dois dias distantes como se o
     * intervalo não existisse.
     *
     * @param  Collection<int, Carbon>  $moments
     * @return array{labels: list<string>, values: list<int>}
     */
    private function dailySeries(Collection $moments, Carbon $since, Carbon $today, string $timezone): array
    {
        $counts = $moments
            ->groupBy(fn (Carbon $moment): string => $moment->copy()->setTimezone($timezone)->toDateString())
            ->map(fn (Collection $day): int => $day->count());

        $labels = [];
        $values = [];

        for ($day = $since->copy(); $day->lessThanOrEqualTo($today); $day->addDay()) {
            $labels[] = $day->format('d/m');
            $values[] = (int) ($counts[$day->toDateString()] ?? 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
