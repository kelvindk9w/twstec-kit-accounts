<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Enums;

/**
 * Status da chave de API.
 *
 * - Active: utilizável (sujeita ainda a expires_at, grace_ends_at e inatividade).
 * - Revoked: revogada manualmente pelo dono (irreversível).
 * - Expired: passou da validade definida pelo usuário (expires_at).
 * - ExpiredInactivity: desativada pelo job diário por inatividade prolongada.
 * - Rotated: substituída por uma nova chave na rotação (morta, fora do grace).
 */
enum ApiKeyStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case ExpiredInactivity = 'expired_inactivity';
    case Rotated = 'rotated';
}
