<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Events\PersonDeleted;
use Twstec\Kit\Accounts\Account\Events\PersonDeleting;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionImpededException;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use WeakMap;

/**
 * O que acontece com as contas quando uma PESSOA nasce ou é excluída — ligado
 * pelo pacote aos eventos do model de usuário do aplicativo (o configurado em
 * `auth.providers.users.model`), sem o model precisar de trait nenhuma.
 *
 * - Criada: ganha a conta pessoal (mesmo uuid), como dona.
 * - Excluída:
 *   - recusada (exceção, nada muda) se ela é DONA de conta com outros
 *     membros — a propriedade precisa ser transferida antes;
 *   - recusada (Deletion\Exceptions\DeletionImpededException, nada muda) se
 *     um verificador declarado disser que ela, ou uma conta que sairia junto,
 *     não pode ser excluída agora (Deletion\DeletionImpediments) — com a
 *     recusa e o motivo na trilha de auditoria (`user.deleted`, `denied`),
 *     mesmo que quem chamou desfaça a transação em volta;
 *   - senão, as contas de que ela era dona (a pessoal e as que só ela usava)
 *     saem com os dados — o mesmo efeito da 1.x; nas contas em que era admin
 *     ou member ela só deixa de ser membro, e as chaves que criou continuam
 *     valendo (são da conta) — e o dono e os admins de cada uma dessas
 *     contas recebem o AVISO DE CHAVE ÓRFÃ (uma vez por conta, pela fila,
 *     depois do commit; exclusão recusada não avisa ninguém).
 *
 * A recusa roda no `deleting` (antes de tocar no banco) e não tem efeito
 * colateral: se outra guarda recusar depois (conta protegida, por exemplo),
 * nada foi apagado.
 *
 * Para quem guarda dado das contas fora deste pacote (os uploads), dois
 * eventos: Events\PersonDeleting (no `deleting`, depois da recusa, SÓ para
 * ler o que vai sair) e Events\PersonDeleted (no `deleted`, antes de o
 * pacote arrumar as contas, para apagar). A limpeza roda no `deleted` (a pessoa já saiu); no
 * PostgreSQL os gatilhos das contas fazem o mesmo dentro da própria sentença.
 */
final class PersonLifecycle
{
    /**
     * Contas de que cada pessoa em exclusão era dona.
     *
     * @var WeakMap<AuthUser, list<int>>
     */
    private WeakMap $owned;

    /**
     * Chaves órfãs que cada pessoa em exclusão vai deixar, por conta.
     *
     * @var WeakMap<AuthUser, list<array{account: Account, keys: list<array{name: string, code: string, public_key: string}>}>>
     */
    private WeakMap $orphans;

    public function __construct(
        private readonly AccountService $accounts,
        private readonly OrphanedApiKeys $notices,
    ) {
        $this->owned = new WeakMap;
        $this->orphans = new WeakMap;
    }

    public static function register(Dispatcher $events): void
    {
        $model = UserModel::name();

        $events->listen("eloquent.created: {$model}", static function (AuthUser $user): void {
            app(self::class)->created($user);
        });

        $events->listen("eloquent.deleting: {$model}", static function (AuthUser $user): void {
            app(self::class)->deleting($user);
        });

        $events->listen("eloquent.deleted: {$model}", static function (AuthUser $user): void {
            app(self::class)->deleted($user);
        });
    }

    public function created(AuthUser $user): void
    {
        $this->accounts->createPersonalAccount($user);
    }

    public function deleting(AuthUser $user): void
    {
        try {
            $this->accounts->ensurePersonCanBeDeleted($user);
        } catch (DeletionImpededException $exception) {
            $this->recordRefusal($user, $exception->getMessage());

            throw $exception;
        }

        $this->owned[$user] = $this->accounts->ownedAccountIds($user);
        $this->orphans[$user] = $this->accounts->orphanedKeysOnPersonExit($user);

        PersonDeleting::dispatch($user, $this->owned[$user]);
    }

    public function deleted(AuthUser $user): void
    {
        $ids = $this->owned[$user] ?? [];

        $orfas = $this->orphans[$user] ?? [];

        unset($this->owned[$user], $this->orphans[$user]);

        PersonDeleted::dispatch($user, $ids);

        $this->accounts->cleanUpAfterPersonDeleted($user->getKey(), $ids);

        // Chaves que a pessoa criou em contas de OUTROS donos continuam
        // valendo: o dono e os admins de cada uma são avisados (uma vez por
        // conta, pela fila, depois do commit). Quem saiu pela página da conta
        // já não é membro dela aqui — não há aviso duplicado.
        foreach ($orfas as $orfa) {
            $this->notices->notify($orfa['account'], $user, $orfa['keys'], removed: true, deleted: true);
        }
    }

    /**
     * A recusa vai para a trilha AGORA e — se quem chamou estava numa
     * transação e a desfizer por causa da exceção — de novo depois do
     * desfazer: a linha gravada dentro dela iria embora junto. Fora de
     * transação, uma linha só; numa transação que não é desfeita, também.
     */
    private function recordRefusal(AuthUser $user, string $reason): void
    {
        $trail = app(AuditTrail::class);
        $record = static fn () => $trail->denied(
            AuditTrail::subjectType(UserModel::name()).'.deleted',
            $user instanceof Model ? $user : null,
            $reason,
        );

        $record();

        if (DB::transactionLevel() > 0) {
            DB::afterRollBack($record);
        }
    }
}
