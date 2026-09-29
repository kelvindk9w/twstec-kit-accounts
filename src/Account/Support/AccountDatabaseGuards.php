<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Support;

use Illuminate\Support\Facades\DB;

/**
 * As regras das contas NO PRÓPRIO BANCO (PostgreSQL).
 *
 * O model (AccountMembership) e o serviço (AccountService) aplicam as mesmas
 * regras no código, mas `DB::table(...)`, um DELETE em massa, o psql ou um
 * cliente externo não passam por eles — o mesmo motivo que levou a trilha de
 * auditoria e a proteção das contas demo para gatilhos. Aqui:
 *
 * 1. Exatamente UM dono por conta:
 *    - no máximo um: o índice único parcial da migration (vale também no
 *      SQLite);
 *    - conta nova precisa terminar a transação COM dono (gatilho de
 *      restrição adiado — a conta e o dono entram na mesma transação);
 *    - o papel do dono não é rebaixado e ninguém muda de conta (UPDATE);
 *    - o dono não sai de uma conta que tem outros membros (a propriedade é
 *      transferida antes); a saída do dono de uma conta SEM outros membros
 *      apaga a conta — e, em cascata, os dados dela. É isso que faz a
 *      exclusão de uma pessoa levar a conta pessoal dela, como a 1.x levava
 *      os projetos e as chaves.
 * 2. Vínculo chave ↔ projeto só dentro da MESMA conta.
 *
 * Fora do PostgreSQL (SQLite dos testes) tudo aqui é no-op e as regras ficam
 * por conta do código.
 *
 * O QUE NENHUM GATILHO COBRE: quem é dono do esquema pode desligá-los. Contra
 * esse, a defesa é a separação de papéis no PostgreSQL de produção.
 */
final class AccountDatabaseGuards
{
    public const MEMBERSHIP_FUNCTION = 'tws_account_membership_guard';

    public const MEMBERSHIP_UPDATE_TRIGGER = 'account_memberships_guard_update';

    public const MEMBERSHIP_DELETE_TRIGGER = 'account_memberships_guard_delete';

    public const OWNER_FUNCTION = 'tws_account_requires_owner';

    public const OWNER_TRIGGER = 'accounts_require_owner';

    public const BINDING_FUNCTION = 'tws_api_key_project_same_account';

    public const BINDING_TRIGGER = 'api_key_project_same_account';

    /**
     * Prefixo das mensagens de erro dos gatilhos (o código as reconhece).
     */
    public const ERROR_PREFIX = 'TWS_ACCOUNT_INVARIANT';

    public static function supported(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    public static function install(): void
    {
        if (! self::supported()) {
            return;
        }

        $erro = self::ERROR_PREFIX;
        $membros = self::MEMBERSHIP_FUNCTION;
        $dono = self::OWNER_FUNCTION;
        $vinculo = self::BINDING_FUNCTION;

        DB::unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION {$membros}() RETURNS trigger AS \$\$
        DECLARE
            outros integer;
        BEGIN
            IF TG_OP = 'UPDATE' THEN
                IF NEW.account_id IS DISTINCT FROM OLD.account_id THEN
                    RAISE EXCEPTION '{$erro}: um vinculo de membro nao muda de conta'
                        USING ERRCODE = 'raise_exception';
                END IF;

                IF OLD.role = 'owner' AND NEW.role IS DISTINCT FROM 'owner' THEN
                    RAISE EXCEPTION '{$erro}: o dono da conta % nao pode ser rebaixado; transfira a propriedade', OLD.account_id
                        USING ERRCODE = 'raise_exception';
                END IF;

                RETURN NEW;
            END IF;

            -- DELETE (depois da linha sair): só importa o dono.
            IF OLD.role = 'owner' AND EXISTS (SELECT 1 FROM accounts WHERE id = OLD.account_id) THEN
                SELECT count(*) INTO outros FROM account_memberships WHERE account_id = OLD.account_id;

                IF outros > 0 THEN
                    RAISE EXCEPTION '{$erro}: o dono da conta % nao sai enquanto ela tiver outros membros; transfira a propriedade', OLD.account_id
                        USING ERRCODE = 'raise_exception';
                END IF;

                DELETE FROM accounts WHERE id = OLD.account_id;
            END IF;

            RETURN NULL;
        END;
        \$\$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION {$dono}() RETURNS trigger AS \$\$
        BEGIN
            IF EXISTS (SELECT 1 FROM accounts WHERE id = NEW.id)
               AND NOT EXISTS (SELECT 1 FROM account_memberships WHERE account_id = NEW.id AND role = 'owner') THEN
                RAISE EXCEPTION '{$erro}: a conta % terminou a transacao sem dono', NEW.id
                    USING ERRCODE = 'raise_exception';
            END IF;

            RETURN NULL;
        END;
        \$\$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION {$vinculo}() RETURNS trigger AS \$\$
        BEGIN
            IF (SELECT account_id FROM api_keys WHERE id = NEW.api_key_id)
               IS DISTINCT FROM (SELECT account_id FROM projects WHERE id = NEW.project_id) THEN
                RAISE EXCEPTION '{$erro}: chave e projeto de contas diferentes nao se vinculam'
                    USING ERRCODE = 'raise_exception';
            END IF;

            RETURN NEW;
        END;
        \$\$ LANGUAGE plpgsql;
        SQL);

        self::dropTriggers();

        DB::unprepared('CREATE TRIGGER '.self::MEMBERSHIP_UPDATE_TRIGGER.' BEFORE UPDATE ON account_memberships '
            .'FOR EACH ROW EXECUTE FUNCTION '.self::MEMBERSHIP_FUNCTION.'()');
        DB::unprepared('CREATE TRIGGER '.self::MEMBERSHIP_DELETE_TRIGGER.' AFTER DELETE ON account_memberships '
            .'FOR EACH ROW EXECUTE FUNCTION '.self::MEMBERSHIP_FUNCTION.'()');
        DB::unprepared('CREATE CONSTRAINT TRIGGER '.self::OWNER_TRIGGER.' AFTER INSERT ON accounts '
            .'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION '.self::OWNER_FUNCTION.'()');
        DB::unprepared('CREATE TRIGGER '.self::BINDING_TRIGGER.' BEFORE INSERT OR UPDATE ON api_key_project '
            .'FOR EACH ROW EXECUTE FUNCTION '.self::BINDING_FUNCTION.'()');
    }

    public static function drop(): void
    {
        if (! self::supported()) {
            return;
        }

        self::dropTriggers();

        DB::unprepared('DROP FUNCTION IF EXISTS '.self::MEMBERSHIP_FUNCTION.'()');
        DB::unprepared('DROP FUNCTION IF EXISTS '.self::OWNER_FUNCTION.'()');
        DB::unprepared('DROP FUNCTION IF EXISTS '.self::BINDING_FUNCTION.'()');
    }

    /**
     * Confere AGORA, dentro da transação corrente, que as contas criadas nela
     * têm dono — em vez de só no fim da transação — e volta o gatilho ao modo
     * adiado. Chamado por quem acabou de criar conta e dono (AccountService):
     * o erro aparece na hora e não fica evento pendente na transação (um
     * TRUNCATE em cascata de `users` mais adiante na mesma transação seria
     * recusado pelo PostgreSQL por causa dele).
     */
    public static function checkOwnershipNow(): void
    {
        if (! self::supported()) {
            return;
        }

        $instalado = DB::selectOne('SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = ?) AS instalado', [self::OWNER_TRIGGER]);

        if (! (bool) ($instalado->instalado ?? false)) {
            return;
        }

        DB::statement('SET CONSTRAINTS '.self::OWNER_TRIGGER.' IMMEDIATE');
        DB::statement('SET CONSTRAINTS '.self::OWNER_TRIGGER.' DEFERRED');
    }

    /**
     * Os gatilhos estão instalados neste banco?
     */
    public static function installed(): bool
    {
        if (! self::supported()) {
            return false;
        }

        return DB::table('pg_trigger')
            ->whereIn('tgname', [self::MEMBERSHIP_UPDATE_TRIGGER, self::MEMBERSHIP_DELETE_TRIGGER, self::OWNER_TRIGGER, self::BINDING_TRIGGER])
            ->count() === 4;
    }

    private static function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::MEMBERSHIP_UPDATE_TRIGGER.' ON account_memberships');
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::MEMBERSHIP_DELETE_TRIGGER.' ON account_memberships');
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::OWNER_TRIGGER.' ON accounts');
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::BINDING_TRIGGER.' ON api_key_project');
    }
}
