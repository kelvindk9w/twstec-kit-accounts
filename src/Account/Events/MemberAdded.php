<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Twstec\Kit\Accounts\Account\Models\AccountMembership;

/**
 * Uma pessoa entrou numa conta (admin ou member). Ponto de extensão.
 */
final class MemberAdded
{
    use Dispatchable;

    public function __construct(public readonly AccountMembership $membership) {}
}
