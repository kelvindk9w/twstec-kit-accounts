<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Tenancy\Models\Project;

/**
 * Job de teste: anota em que contexto de conta rodou e o que enxergou.
 */
final class RecordsAccountContextJob implements ShouldQueue
{
    use Queueable;

    /**
     * @var list<array{account: int|null, system: string|null, projects: list<string>|string}>
     */
    public static array $runs = [];

    public function handle(): void
    {
        try {
            $projetos = Project::query()->orderBy('name')->pluck('name')->all();
        } catch (Throwable $exception) {
            $projetos = $exception::class;
        }

        self::$runs[] = [
            'account' => Accounts::current()?->getKey(),
            'system' => Accounts::systemReason(),
            'projects' => $projetos,
        ];
    }
}
