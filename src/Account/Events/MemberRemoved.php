<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Twstec\Kit\Accounts\Account\Models\Account;

/**
 * Uma pessoa saiu de uma conta. As chaves de API que ela criou continuam
 * valendo — são da conta. Ponto de extensão (o aviso aos donos chega com as
 * telas de membros).
 *
 * @param  mixed  $userId  Id da pessoa que saiu.
 */
final class MemberRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly Account $account,
        public readonly mixed $userId,
    ) {}
}
