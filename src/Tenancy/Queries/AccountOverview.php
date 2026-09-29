<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Queries;

use Illuminate\Database\Eloquent\Collection;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

/**
 * Números e listas da visão geral da conta — o resultado de
 * AccountOverviewQuery, pronto para qualquer tela (Livewire, React, JSON).
 *
 * Só dados: nenhuma formatação de exibição além dos rótulos de dia da série
 * (d/m, no fuso de exibição da plataforma).
 */
final readonly class AccountOverview
{
    /**
     * @param  int  $activeKeysCount  Chaves de API ativas da conta.
     * @param  int  $projectsCount  Projetos da conta.
     * @param  int  $recentRequestsCount  Requisições da API da conta nos últimos $recentRequestsDays dias (contando hoje).
     * @param  int  $recentRequestsDays  Janela do contador acima.
     * @param  mixed  $lastKeyUsedAt  Último uso de qualquer chave da conta (valor bruto do banco; null se nunca).
     * @param  int  $chartDays  Janela da série diária.
     * @param  array{labels: list<string>, values: list<int>}  $chart  Série diária completa (dia sem tráfego = 0).
     * @param  Collection<int, RequestLog>  $recentCalls  Últimas chamadas da API da conta, mais novas primeiro.
     */
    public function __construct(
        public int $activeKeysCount,
        public int $projectsCount,
        public int $recentRequestsCount,
        public int $recentRequestsDays,
        public mixed $lastKeyUsedAt,
        public int $chartDays,
        public array $chart,
        public Collection $recentCalls,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
