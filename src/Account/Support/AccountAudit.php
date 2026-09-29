<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyAttempt;
use Twstec\Kit\Accounts\Tenancy\Enums\ProjectAttempt;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;
use Twstec\Kit\Foundation\Logging\CorrelationId;

/**
 * A trilha de auditoria das ações de CONTA — no BANCO (`audit_events`), pela
 * porta única do foundation (AuditTrail, que redige o que mudou).
 *
 * Cada linha diz QUEM agiu (a pessoa, passada explicitamente — no aceite de
 * convite por quem acabou de criar a conta, ela ainda não está logada), em
 * qual CONTA (`tenant_uuid`), em qual ALVO (a pessoa, o convite ou a
 * própria conta), o antes/depois redigido, o IP, o User-Agent e o
 * `correlation_id` da requisição.
 *
 * - record(): a ação teve efeito. As Actions chamam DENTRO da transação da
 *   mudança: se o INSERT da trilha falhar, a exceção sobe e a mudança é
 *   desfeita (falha fechada, como no /admin).
 * - denied(): a ação foi recusada (papel, regra, token, limite). Grava FORA
 *   de transação — a recusa não tem mudança para desfazer — e ANTES de a
 *   exceção sair para a tela. Serve também às recusas das telas de chaves de
 *   API e de projetos (ApiKeyAttempt, ProjectAttempt — ver
 *   AccountResourceGuard) e da API v1 por chave (contexto `api`).
 */
final class AccountAudit
{
    public function __construct(private readonly AuditTrail $trail) {}

    /**
     * @param  array<string, array{before?: mixed, after?: mixed}>  $changes
     */
    public function record(
        AccountAuditEvent $event,
        Account|string|null $account,
        ?AuthUser $actor,
        ?Model $subject = null,
        array $changes = [],
        ?string $subjectType = null,
        ?string $subjectUuid = null,
    ): void {
        $this->trail->within($this->scope($actor), fn () => $this->trail->record(
            $event->value,
            $subject,
            $changes,
            $subjectType,
            $subjectUuid,
            $this->tenantUuid($account),
        ));
    }

    public function denied(
        AccountAuditEvent|ApiKeyAttempt|ProjectAttempt $event,
        Account|string|null $account,
        ?AuthUser $actor,
        string $reason,
        ?Model $subject = null,
        ?string $subjectType = null,
        ?string $subjectUuid = null,
        ?AuditContext $context = null,
    ): void {
        $this->trail->within($this->scope($actor, $context), fn () => $this->trail->denied(
            $event->value,
            $subject,
            $reason,
            $subjectType,
            $this->tenantUuid($account),
            $subjectUuid,
        ));
    }

    /**
     * O "de onde" e o "quem" da linha: a requisição corrente e a pessoa que
     * agiu. Fora de requisição HTTP (console), o contexto é `console`; quem
     * chama pode dizer outro (a API v1 por chave: `api`).
     */
    private function scope(?AuthUser $actor, ?AuditContext $context = null): AuditScope
    {
        $request = request();
        $console = app()->runningInConsole() && ! app()->runningUnitTests();
        $isAdmin = $actor?->getAttribute('is_admin');

        return new AuditScope(
            context: $context ?? ($console ? AuditContext::Console : AuditContext::Panel),
            actorUuid: $actor !== null ? (string) $actor->getAttribute('uuid') : null,
            actorIsAdmin: $actor !== null && $isAdmin !== null ? (bool) $isAdmin : null,
            correlationId: CorrelationId::resolve($request),
            ip: $request->ip(),
            userAgent: Str::limit((string) $request->userAgent(), AuditScope::USER_AGENT_MAX, '') ?: null,
        );
    }

    private function tenantUuid(Account|string|null $account): ?string
    {
        return $account instanceof Account ? (string) $account->uuid : $account;
    }
}
