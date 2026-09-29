<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Support;

use Illuminate\Support\Facades\RateLimiter;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * O INTERVALO dos convites — novos e reenvios contam juntos, em dois baldes:
 * por CONTA e por PESSOA que convida (`accounts.invitations.throttle.*`). O
 * e-mail sai em nome da plataforma: sem limite, uma conta vira canal de spam.
 */
final class InvitationThrottle
{
    /**
     * Conta a tentativa — ou, se algum balde estourou, devolve em quantos
     * segundos volta a caber (e não conta nada).
     */
    public static function attempt(Account $account, AuthUser $actor): ?int
    {
        $janela = max(1, (int) config('accounts.invitations.throttle.minutes', 60)) * 60;

        $baldes = [
            'accounts-invite:account:'.$account->getKey() => (int) config('accounts.invitations.throttle.per_account', 30),
            'accounts-invite:person:'.$actor->getKey() => (int) config('accounts.invitations.throttle.per_person', 20),
        ];

        foreach ($baldes as $chave => $maximo) {
            if (RateLimiter::tooManyAttempts($chave, max(1, $maximo))) {
                return max(1, RateLimiter::availableIn($chave));
            }
        }

        foreach (array_keys($baldes) as $chave) {
            RateLimiter::hit($chave, $janela);
        }

        return null;
    }

    public static function message(int $seconds): string
    {
        return __('accounts.invitations.throttled', ['minutes' => max(1, (int) ceil($seconds / 60))]);
    }
}
