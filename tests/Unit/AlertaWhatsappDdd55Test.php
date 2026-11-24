<?php

namespace Tests\Unit;

use App\Modules\Checkout\Support\AlertaWhatsappDdd55;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Alerta de WhatsApp com DDD 55 (pedido do Lira 2026-11-23): a regra em PHP e
 * a mesma regra no JS do checkout (alertaWhatsapp.js), com os mesmos casos.
 */
class AlertaWhatsappDdd55Test extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function casos(): array
    {
        return [
            'DDD 55 com CEP de São Paulo alerta' => ['(55) 11987-6543', '01310-100', true],
            'DDD 55 com CEP do Rio alerta' => ['55119876543', '20040020', true],
            'DDD 55 com CEP de Porto Alegre (DDD 51) alerta' => ['(55) 99999-8888', '90010-000', true],
            'DDD 55 com CEP de Santa Maria não alerta' => ['(55) 99999-8888', '97010-000', false],
            'DDD 55 com CEP de Ijuí não alerta' => ['(55) 99999-8888', '98700-000', false],
            'DDD 55 no limite da área (97000-000) não alerta' => ['(55) 99999-8888', '97000-000', false],
            'DDD 55 no limite da área (98999-999) não alerta' => ['(55) 99999-8888', '98999-999', false],
            'outro DDD não alerta' => ['(11) 98765-4321', '01310-100', false],
            'outro DDD com CEP da área não alerta' => ['(21) 98765-4321', '97010-000', false],
            'celular incompleto não alerta' => ['(55) 1198', '01310-100', false],
            'CEP incompleto não alerta' => ['(55) 11987-6543', '0131', false],
        ];
    }

    #[DataProvider('casos')]
    public function test_regra_do_alerta(string $celular, string $cep, bool $alerta): void
    {
        $this->assertSame($alerta, AlertaWhatsappDdd55::deveAlertar($celular, $cep));
    }

    public function test_sugere_o_numero_sem_o_55_do_pais(): void
    {
        $this->assertSame('11987654321', AlertaWhatsappDdd55::sugestaoSem55('55 11 98765-4321'));
        $this->assertSame('1132654321', AlertaWhatsappDdd55::sugestaoSem55('+55 (11) 3265-4321'));
        $this->assertNull(AlertaWhatsappDdd55::sugestaoSem55('(55) 99999-8888'));
        $this->assertNull(AlertaWhatsappDdd55::sugestaoSem55('11987654321'));
    }

    /** O JS da tela dá o mesmo resultado que o PHP em todos os casos. */
    public function test_regra_do_js_e_a_mesma_do_php(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node não instalado');
        }

        $modulo = 'file://'.realpath(__DIR__.'/../../resources/js/Modules/Checkout/alertaWhatsapp.js');
        $casos = array_values(self::casos());
        $sugestoes = ['55 11 98765-4321', '+55 (11) 3265-4321', '(55) 99999-8888', '11987654321'];
        $script = 'import { deveAlertarDdd55, sugestaoSem55 } from '.json_encode($modulo).';'
            .'const casos = '.json_encode($casos).'; const sugestoes = '.json_encode($sugestoes).';'
            .'console.log(JSON.stringify({ alertas: casos.map(([c, cep]) => deveAlertarDdd55(c, cep)), sugestoes: sugestoes.map(sugestaoSem55) }));';

        $saida = shell_exec(escapeshellarg($node).' --input-type=module -e '.escapeshellarg($script).' 2>&1');
        $resultado = json_decode((string) $saida, true);
        $this->assertIsArray($resultado, 'Saída do node: '.$saida);

        $this->assertSame(array_column($casos, 2), $resultado['alertas']);
        $this->assertSame(array_map(AlertaWhatsappDdd55::sugestaoSem55(...), $sugestoes), $resultado['sugestoes']);
    }
}
