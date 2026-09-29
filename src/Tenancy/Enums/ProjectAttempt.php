<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Enums;

/**
 * As TENTATIVAS RECUSADAS sobre projetos no painel gravadas na trilha de
 * auditoria (`audit_events.action`, com `outcome = denied` e o motivo): o
 * papel que não permite (403) e o projeto que não está na conta atual (404 —
 * a resposta não muda). A ação é a que foi tentada.
 *
 * Os 404 de projeto da API v1 ficam FORA da trilha (ver
 * ApiKeys\Enums\ApiKeyAttempt): já vão para o `request_logs`.
 */
enum ProjectAttempt: string
{
    case Created = 'project.created';
    case Updated = 'project.updated';
    case Deleted = 'project.deleted';
}
