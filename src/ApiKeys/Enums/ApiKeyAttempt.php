<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Enums;

/**
 * As TENTATIVAS RECUSADAS sobre chaves de API gravadas na trilha de auditoria
 * (`audit_events.action`, com `outcome = denied` e o motivo).
 *
 * - Pelo PAINEL (telas dos starters, contexto `panel`): gerir chaves sem o
 *   papel (403), chave que não está na conta atual (404 — a resposta não muda)
 *   e projeto de fora da conta no vínculo (erro de validação). A ação é a que
 *   foi tentada (`api_key.created`, `api_key.rotated`...).
 * - Pela API v1 (contexto `api`), a chave AUTENTICADA que tenta além do que
 *   pode: escopo que ela não tem e operação de conta por chave vinculada a
 *   projetos (403). O alvo é a própria chave.
 *
 * FORA da trilha, de propósito: os 401 (credencial ausente ou inválida — não
 * há quem registrar) e os 404 da API v1 (recurso de outra conta ou
 * inexistente). Os dois são sinal de ataque e já ficam no `request_logs`, com
 * o status e, no 404, a conta da chave; gravá-los um a um em `audit_events`
 * inflaria a trilha com a varredura de quem ataca.
 */
enum ApiKeyAttempt: string
{
    case Created = 'api_key.created';
    case Rotated = 'api_key.rotated';
    case Revoked = 'api_key.revoked';
    case ProjectsSynced = 'api_key.projects_synced';

    case ScopeDenied = 'api_key.scope_denied';
    case AccountKeyRequired = 'api_key.account_key_required';
}
