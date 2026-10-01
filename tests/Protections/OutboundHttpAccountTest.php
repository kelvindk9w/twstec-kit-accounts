<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Twstec\Kit\Foundation\Tracing\Models\OutboundHttpLog;

// =============================================================================
// A TRILHA DAS CHAMADAS HTTP DE SAÍDA (twstec/kit-foundation) SABE A CONTA.
//
// Instalado o pacote de contas, a linha de `outbound_http_logs` leva o uuid
// da conta em nome da qual a chamada foi feita — sem a aplicação informar.
// =============================================================================

it('a chamada feita dentro de uma conta grava o uuid dela; fora de conta, nulo', function (): void {
    Http::fake();
    $ana = $this->owner();

    $this->inAccountOf($ana, fn () => Http::get('https://api.example.test/v1/a'));
    Http::get('https://api.example.test/v1/b');

    expect(OutboundHttpLog::query()->orderBy('id')->pluck('tenant_uuid')->all())
        ->toBe([(string) $this->accountOf($ana)->uuid, null]);
});
