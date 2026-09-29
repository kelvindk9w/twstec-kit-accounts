<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Twstec\Kit\Accounts\Account\Models\Account;

/**
 * Uma conta nasceu (com o dono já vinculado). Ponto de extensão.
 */
final class AccountCreated
{
    use Dispatchable;

    public function __construct(public readonly Account $account) {}
}
