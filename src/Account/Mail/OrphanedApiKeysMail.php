<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Mail;

use Twstec\Kit\Foundation\Mail\KitMailable;

/**
 * AVISO DE CHAVE ÓRFÃ: quem criou chaves de API saiu da conta, foi removido
 * dela ou teve o acesso à plataforma excluído, e elas CONTINUAM VALENDO — são da conta, não da pessoa. Vai para
 * quem pode revisá-las (o dono e os admins).
 *
 * Só identificadores PÚBLICOS de cada chave (nome, código público, chave
 * pública) — nunca a secreta nem o hash. O link leva à tela de chaves da
 * conta certa (a view monta a rota assinada do aplicativo).
 */
final class OrphanedApiKeysMail extends KitMailable
{
    /**
     * @param  list<array{name: string, code: string, public_key: string}>  $keys
     */
    public function __construct(
        public readonly string $accountName,
        public readonly string $accountUuid,
        public readonly string $departedName,
        public readonly bool $removed,
        public readonly array $keys,
        public readonly bool $deleted = false,
    ) {}

    protected function subjectLine(): string
    {
        return trans_choice('mail.orphaned_api_keys.subject', count($this->keys), [
            'platform' => platform()->name,
            'account' => $this->accountName,
        ]);
    }

    protected function messageView(): string
    {
        return 'mail.messages.orphaned-api-keys';
    }
}
