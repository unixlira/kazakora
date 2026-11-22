<?php

namespace Tests\Unit\Infoprodutos;

use App\Modules\Infoprodutos\Support\UtmDoAnuncio;
use PHPUnit\Framework\TestCase;

class UtmDoAnuncioTest extends TestCase
{
    public function test_id_e_nome_do_anuncio_continuam_valendo(): void
    {
        $c = new UtmDoAnuncio(['52577975689569' => ['x'], 'JARVIS | AD02' => ['dms_jarvis'], 'v1' => ['cd_v1']]);
        $this->assertSame('52577975689569', $c->chave('52577975689569', 'qualquer', ''));
        $this->assertSame('JARVIS | AD02', $c->chave('1', 'JARVIS | AD02', ''));
        $this->assertSame('v1', $c->chave('1', 'V1', 'CD V1'));
    }

    public function test_codigo_curto_do_homer_casa_pelo_nome_do_anuncio_e_da_campanha(): void
    {
        $c = new UtmDoAnuncio(['V01' => ['dms_homer_br'], 'V02' => ['dms_homer_br']]);
        $this->assertSame('V01', $c->chave('52577975689569', 'AD01 | MC_homer-lancamento_V01-demo-homer_9x16', 'DMS | VENDAS | BR | HOMER | V01 demo-homer | 21-11'));
        $this->assertSame('V02', $c->chave('52577975708769', 'AD01 | MC_homer-lancamento_V02-conversa-homer_9x16', 'DMS | VENDAS | BR | HOMER | V02 conversa-homer | 21-11'));
    }

    public function test_campanha_de_outro_produto_nao_pega_o_codigo(): void
    {
        $c = new UtmDoAnuncio(['V01' => ['dms_homer_br']]);
        $nome = 'AD01 | MC_jarvis_V01_9x16';
        $this->assertSame($nome, $c->chave('9', $nome, 'DMS | VENDAS | MX | JARVIS | 20-11'));
    }
}
