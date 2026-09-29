<?php

declare(strict_types=1);

use Twstec\Kit\Accounts\Tests\TestCase;

// Todos os testes do pacote sobem a aplicação limpa do Testbench com os
// providers do pacote, do auth e do foundation — nada do starter.
pest()->extend(TestCase::class)->in('Feature', 'Protections', 'Architecture');
