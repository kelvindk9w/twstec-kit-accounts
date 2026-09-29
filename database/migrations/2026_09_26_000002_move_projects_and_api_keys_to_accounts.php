<?php

declare(strict_types=1);

use Closure as SchemaChanges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Accounts\Account\Support\AccountDatabaseGuards;

// =============================================================================
// Projetos e chaves de API passam a pertencer à CONTA.
//
// Quem já usa a 1.x: cada pessoa vira dona de uma CONTA PESSOAL com o MESMO
// identificador dela (o mesmo `id` quando está livre — sempre, numa base que
// ainda não tinha contas — e sempre o mesmo `uuid`), e os projetos e as
// chaves dela passam para essa conta. `created_by` guarda quem criou (a
// própria pessoa, nos dados migrados).
//
// A trilha NÃO é reescrita: `request_logs.tenant_uuid` e `audit_events` gravam
// uuid, e o uuid da conta pessoal é o da pessoa — continuam apontando para o
// identificador certo. `uploads` fica para a fase seguinte (o dono do upload
// não muda aqui).
//
// Idempotente: cada passo confere o que já foi feito (coluna existente, conta
// pessoal já criada, linha já movida) — rodar de novo depois de uma falha no
// meio continua de onde parou. No PostgreSQL a migration inteira roda numa
// transação (uma falha desfaz tudo).
//
// Volume: as pessoas são lidas em lotes (`accounts.migration.chunk`, padrão
// 1000) e a mudança de dono das linhas é feita por faixas de id com um UPDATE
// por faixa — nada é carregado inteiro na memória.
//
// Reversível: o `down` devolve `user_id` (o dono da conta de cada linha) e
// remove `account_id`/`created_by`. As contas em si saem com o `down` da
// migration anterior.
// =============================================================================
return new class extends Migration
{
    /**
     * Tabelas que passam a pertencer à conta.
     *
     * @var list<string>
     */
    private const TABLES = ['projects', 'api_keys'];

    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function up(): void
    {
        $chunk = max(1, (int) config('accounts.migration.chunk', 1000));

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'account_id')) {
                    $blueprint->unsignedBigInteger('account_id')->nullable();
                }

                if (! Schema::hasColumn($table, 'created_by')) {
                    $blueprint->unsignedBigInteger('created_by')->nullable();
                }
            });
        }

        $this->createPersonalAccounts($chunk);

        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'user_id')) {
                $this->moveRowsToPersonalAccounts($table, $chunk);
            }
        }

        foreach (self::TABLES as $table) {
            $orphans = DB::table($table)->whereNull('account_id')->count();

            if ($orphans > 0) {
                throw new RuntimeException("Migração de contas: {$orphans} linha(s) de {$table} ficaram sem conta. Nada foi alterado.");
            }
        }

        $this->syncAccountSequence();

        $this->preservingKeyProjectLinks(function (): void {
            foreach (self::TABLES as $table) {
                $this->finishSchema($table);
            }
        });

        AccountDatabaseGuards::install();
    }

    public function down(): void
    {
        AccountDatabaseGuards::drop();

        $this->preservingKeyProjectLinks(fn () => $this->restoreUserOwnership());
    }

    private function restoreUserOwnership(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'user_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->unsignedBigInteger('user_id')->nullable();
                });
            }

            // O dono de cada linha volta a ser a pessoa dona da conta.
            DB::table($table)->whereNull('user_id')->update([
                'user_id' => DB::raw("(select m.user_id from account_memberships m where m.account_id = {$table}.account_id and m.role = 'owner')"),
            ]);

            $semDono = DB::table($table)->whereNull('user_id')->count();

            if ($semDono > 0) {
                throw new RuntimeException("Reversão de contas: {$semDono} linha(s) de {$table} sem dono. Nada foi alterado.");
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('user_id')->nullable(false)->change();
                $blueprint->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $blueprint->index(['user_id', 'status']);
            });

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if ($this->hasForeignKey($table, 'account_id')) {
                    $blueprint->dropForeign(['account_id']);
                }

                if ($this->hasForeignKey($table, 'created_by')) {
                    $blueprint->dropForeign(['created_by']);
                }

                if (Schema::hasIndex($table, ['account_id', 'status'])) {
                    $blueprint->dropIndex(['account_id', 'status']);
                }
            });

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['account_id', 'created_by']);
            });
        }
    }

    /**
     * Uma conta pessoal por pessoa que ainda não tem, com o uuid dela e — se
     * estiver livre — o mesmo id; e a pessoa como dona (owner).
     */
    private function createPersonalAccounts(int $chunk): void
    {
        DB::table('users')->select(['id', 'uuid'])->orderBy('id')->chunkById($chunk, function (Collection $users): void {
            $ids = $users->pluck('id')->all();

            $jaTem = DB::table('accounts')->whereIn('personal_user_id', $ids)->pluck('personal_user_id')
                ->map(fn (mixed $id): int => (int) $id)->all();
            $idsOcupados = DB::table('accounts')->whereIn('id', $ids)->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)->all();

            $novas = $users->reject(fn (object $user): bool => in_array((int) $user->id, $jaTem, true));

            if ($novas->isEmpty()) {
                $this->createOwnerMemberships($ids);

                return;
            }

            $codigos = $this->freshCodes($novas->count());
            $agora = now();
            $comMesmoId = [];
            $semId = [];

            foreach ($novas->values() as $indice => $user) {
                $linha = [
                    'uuid' => $user->uuid,
                    'codigo_publico' => $codigos[$indice],
                    'name' => null,
                    'personal_user_id' => $user->id,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];

                if (in_array((int) $user->id, $idsOcupados, true)) {
                    $semId[] = $linha;
                } else {
                    $comMesmoId[] = ['id' => $user->id, ...$linha];
                }
            }

            if ($comMesmoId !== []) {
                DB::table('accounts')->insert($comMesmoId);
            }

            if ($semId !== []) {
                DB::table('accounts')->insert($semId);
            }

            $this->createOwnerMemberships($ids);
        });
    }

    /**
     * @param  list<int>  $userIds
     */
    private function createOwnerMemberships(array $userIds): void
    {
        $agora = now();

        $faltando = DB::table('accounts')
            ->whereIn('personal_user_id', $userIds)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('account_memberships')
                ->whereColumn('account_memberships.account_id', 'accounts.id')
                ->where('account_memberships.role', 'owner'))
            ->get(['id', 'personal_user_id']);

        if ($faltando->isEmpty()) {
            return;
        }

        DB::table('account_memberships')->insert($faltando->map(fn (object $account): array => [
            'account_id' => $account->id,
            'user_id' => $account->personal_user_id,
            'role' => 'owner',
            'created_at' => $agora,
            'updated_at' => $agora,
        ])->all());
    }

    /**
     * Linhas ainda sem conta passam para a conta pessoal do dono (user_id) e
     * guardam o dono como quem criou. Um UPDATE por faixa de ids.
     */
    private function moveRowsToPersonalAccounts(string $table, int $chunk): void
    {
        $min = DB::table($table)->whereNull('account_id')->min('id');
        $max = DB::table($table)->whereNull('account_id')->max('id');

        if ($min === null || $max === null) {
            return;
        }

        for ($inicio = (int) $min; $inicio <= (int) $max; $inicio += $chunk) {
            DB::table($table)
                ->whereNull('account_id')
                ->whereBetween('id', [$inicio, $inicio + $chunk - 1])
                ->update([
                    'account_id' => DB::raw("(select a.id from accounts a where a.personal_user_id = {$table}.user_id)"),
                    'created_by' => DB::raw('user_id'),
                ]);
        }
    }

    /**
     * No PostgreSQL, a sequência de `accounts.id` pula para depois do maior id
     * gravado (as contas pessoais entraram com o id da pessoa).
     */
    private function syncAccountSequence(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SELECT setval(pg_get_serial_sequence('accounts', 'id'), GREATEST(COALESCE((SELECT MAX(id) FROM accounts), 0), 1), (SELECT COUNT(*) > 0 FROM accounts))");
    }

    /**
     * `account_id` obrigatório com cascata (excluir a conta leva os dados),
     * `created_by` opcional (quem criou pode sair ou ser excluído — a linha é
     * da conta), índice por conta + status; `user_id` sai.
     */
    private function finishSchema(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->unsignedBigInteger('account_id')->nullable(false)->change();

            if (! $this->hasForeignKey($table, 'account_id')) {
                $blueprint->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            }

            if (! $this->hasForeignKey($table, 'created_by')) {
                $blueprint->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }

            if (! Schema::hasIndex($table, ['account_id', 'status'])) {
                $blueprint->index(['account_id', 'status']);
            }
        });

        if (! Schema::hasColumn($table, 'user_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if ($this->hasForeignKey($table, 'user_id')) {
                $blueprint->dropForeign(['user_id']);
            }

            if (Schema::hasIndex($table, ['user_id', 'status'])) {
                $blueprint->dropIndex(['user_id', 'status']);
            }
        });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('user_id');
        });
    }

    /**
     * No SQLite, mudar uma coluna (ou tirar uma chave estrangeira) RECRIA a
     * tabela: cria uma cópia, apaga a original e renomeia. O Laravel desliga
     * as chaves estrangeiras para isso, mas dentro de uma transação — é como a
     * migration roda — o SQLite ignora o desligamento, e apagar `projects` ou
     * `api_keys` apagaria em cascata os vínculos chave ↔ projeto. Aqui os
     * vínculos são guardados antes e devolvidos depois (os ids das duas
     * tabelas não mudam na cópia). No PostgreSQL as colunas mudam no lugar e
     * nada disso acontece.
     */
    private function preservingKeyProjectLinks(SchemaChanges $schemaChanges): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $schemaChanges();

            return;
        }

        $vinculos = DB::table('api_key_project')->orderBy('id')->get()->map(fn (object $linha): array => (array) $linha)->all();

        $schemaChanges();

        $existentes = DB::table('api_key_project')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach (array_chunk(array_values(array_filter(
            $vinculos,
            fn (array $linha): bool => ! in_array((int) $linha['id'], $existentes, true),
        )), 500) as $lote) {
            DB::table('api_key_project')->insert($lote);
        }
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Códigos públicos ACC-xxxxxx novos (alfabeto sem ambiguidade, sufixo
     * aleatório criptográfico), sem repetir entre si nem com o banco.
     *
     * @return list<string>
     */
    private function freshCodes(int $count): array
    {
        $codigos = [];

        while (count($codigos) < $count) {
            $candidatos = [];

            for ($i = count($codigos); $i < $count; $i++) {
                $sufixo = '';

                for ($j = 0; $j < 6; $j++) {
                    $sufixo .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
                }

                $candidatos['ACC-'.$sufixo] = true;
            }

            $usados = DB::table('accounts')->whereIn('codigo_publico', array_keys($candidatos))->pluck('codigo_publico')->all();

            foreach (array_keys($candidatos) as $codigo) {
                if (! in_array($codigo, $usados, true) && ! in_array($codigo, $codigos, true) && count($codigos) < $count) {
                    $codigos[] = $codigo;
                }
            }
        }

        return $codigos;
    }
};
