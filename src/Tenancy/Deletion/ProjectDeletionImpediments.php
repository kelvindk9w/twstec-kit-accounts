<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Deletion;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Tenancy\Contracts\ProjectDeletionCheck;
use Twstec\Kit\Accounts\Tenancy\Exceptions\ProjectDeletionRefusedException;
use Twstec\Kit\Accounts\Tenancy\Models\Project;

/**
 * IMPEDIMENTOS DE EXCLUSÃO DE PROJETO — o ponto de extensão para o aplicativo
 * dizer se e por que um projeto NÃO pode ser excluído agora (ex.: há
 * cobranças, pedidos ou registros guardados por lei ligados a ele).
 *
 * As regras (Contracts\ProjectDeletionCheck) vêm de dois lugares, nesta
 * ordem:
 * 1. a configuração do aplicativo: `accounts.projects.deletion_checks` (lista
 *    de classes, resolvidas pelo container a cada consulta);
 * 2. o registro em código: `app(ProjectDeletionImpediments::class)->register(...)`,
 *    no boot de um service provider — a instância, a classe ou um Closure que
 *    recebe o projeto e devolve os impedimentos.
 *
 * Quem pergunta: o ProjectService::delete(), o caminho único da API v1 e das
 * telas de projetos, ANTES de o projeto sair. Havendo impedimento:
 * Exceptions\ProjectDeletionRefusedException (409, com o motivo).
 *
 * A exclusão da CONTA inteira não passa por aqui: ela leva os projetos junto e
 * tem os impedimentos dela (Deletion\DeletionImpediments, `accounts.deletion`).
 */
final class ProjectDeletionImpediments
{
    /**
     * @var list<ProjectDeletionCheck|class-string<ProjectDeletionCheck>|Closure(Project): iterable<DeletionImpediment>>
     */
    private array $registered = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Acrescenta uma regra: a instância, a classe (resolvida pelo container a
     * cada consulta) ou um Closure que recebe o projeto e devolve os
     * impedimentos.
     *
     * @param  ProjectDeletionCheck|class-string<ProjectDeletionCheck>|Closure(Project): iterable<DeletionImpediment>  $check
     */
    public function register(ProjectDeletionCheck|string|Closure $check): void
    {
        $this->registered[] = $check;
    }

    /**
     * Todos os impedimentos do projeto (vazio = pode excluir). Exceção de uma
     * regra sobe: na dúvida, não exclui.
     *
     * @return list<DeletionImpediment>
     */
    public function check(Project $project): array
    {
        $found = [];

        foreach ($this->checks() as $check) {
            $result = $check instanceof Closure ? $check($project) : $check->impediments($project);

            foreach ($result as $impediment) {
                if (! $impediment instanceof DeletionImpediment) {
                    throw new InvalidArgumentException('Uma regra de exclusão de projeto devolveu algo que não é '.DeletionImpediment::class.'.');
                }

                $found[] = $impediment;
            }
        }

        return $found;
    }

    /**
     * @throws ProjectDeletionRefusedException
     */
    public function ensureNone(Project $project): void
    {
        $found = $this->check($project);

        if ($found !== []) {
            throw ProjectDeletionRefusedException::impeded($found);
        }
    }

    /**
     * @return list<ProjectDeletionCheck|Closure(Project): iterable<DeletionImpediment>>
     */
    private function checks(): array
    {
        $checks = [];

        foreach ([...array_values((array) config('accounts.projects.deletion_checks', [])), ...$this->registered] as $check) {
            if (is_string($check)) {
                $check = $this->container->make($check);
            }

            if (! $check instanceof ProjectDeletionCheck && ! $check instanceof Closure) {
                throw new InvalidArgumentException('Regra de exclusão de projeto inválida: use uma classe que implementa '.ProjectDeletionCheck::class.' ou um Closure.');
            }

            $checks[] = $check;
        }

        return $checks;
    }
}
