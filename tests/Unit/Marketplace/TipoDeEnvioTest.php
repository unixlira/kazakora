<?php

namespace Tests\Unit\Marketplace;

use App\Modules\Marketplace\Support\TipoDeEnvio;
use PHPUnit\Framework\TestCase;

/**
 * Os valores testados aqui são os que existem de verdade em produção
 * (contagem de channel_shipments em 14/09/2026), não exemplos inventados —
 * é o que garante que o selo do KoraSync não vai dizer "Envio não
 * informado" pra 400 pedidos de coleta do Mercado Livre.
 */
class TipoDeEnvioTest extends TestCase
{
    public function test_traduz_os_tres_tipos_do_mercado_livre(): void
    {
        $flex = TipoDeEnvio::montar('mercado_livre', 'self_service');
        $this->assertSame(TipoDeEnvio::FLEX, $flex['tipo']);
        $this->assertSame('Flex', $flex['curto']);

        $full = TipoDeEnvio::montar('mercado_livre', 'fulfillment');
        $this->assertSame(TipoDeEnvio::FULL, $full['tipo']);
        $this->assertSame('Full', $full['curto']);

        // Agência e coleta são o mesmo "Mercado Envios" no selo, e se
        // diferenciam no texto completo.
        foreach (['drop_off' => 'agência', 'xd_drop_off' => 'coleta', 'cross_docking' => 'coleta'] as $metodo => $detalhe) {
            $envio = TipoDeEnvio::montar('mercado_livre', $metodo);
            $this->assertSame(TipoDeEnvio::MERCADO_ENVIOS, $envio['tipo'], $metodo);
            $this->assertSame('Mercado Envios', $envio['curto'], $metodo);
            $this->assertStringContainsString($detalhe, $envio['label'], $metodo);
        }
    }

    public function test_traduz_shopee_tiktok_e_amazon(): void
    {
        // Shopee e TikTok são a MESMA coisa na bancada (pedido do usuário
        // em 2026-09-21): pacote no ponto de coleta, transportadora leva.
        $xpress = TipoDeEnvio::montar('shopee', 'Shopee Xpress');
        $this->assertSame(TipoDeEnvio::EXPRESS_COLETA, $xpress['tipo']);
        $this->assertSame('Express-Ponto Coleta', $xpress['curto']);

        $retirada = TipoDeEnvio::montar('shopee', 'Retirada pelo Comprador');
        $this->assertSame(TipoDeEnvio::RETIRADA, $retirada['tipo']);
        $this->assertSame('Retirada', $retirada['curto']);

        // O TikTok manda o código do serviço; o sufixo PICKUP é o que diz
        // que a transportadora vem buscar aqui.
        $tiktok = TipoDeEnvio::montar('tiktok_shop', 'LSV-Standard-BR PICKUP');
        $this->assertSame(TipoDeEnvio::EXPRESS_COLETA, $tiktok['tipo']);
        $this->assertSame('Express-Ponto Coleta', $tiktok['curto']);
        $this->assertSame($xpress['curto'], $tiktok['curto'], 'os dois canais dizem a mesma coisa no selo');
        $this->assertStringContainsString('LSV-Standard-BR PICKUP', $tiktok['label']);

        $amazon = TipoDeEnvio::montar('amazon', 'merchant_fulfillment');
        $this->assertSame(TipoDeEnvio::PROPRIO, $amazon['tipo']);
        $this->assertStringContainsString('vendedor', $amazon['label']);
    }

    public function test_metodo_desconhecido_aparece_cru_e_ausente_diz_que_nao_sabe(): void
    {
        $novo = TipoDeEnvio::montar('mercado_livre', 'metodo_que_ainda_nao_existe');
        $this->assertSame(TipoDeEnvio::OUTRO, $novo['tipo']);
        $this->assertSame('metodo_que_ainda_nao_existe', $novo['curto'], 'método novo aparece cru, não vira palpite');

        foreach (['mercado_livre', 'shopee', 'tiktok_shop', 'amazon', 'loja', null] as $canal) {
            $vazio = TipoDeEnvio::montar($canal, null);
            $this->assertSame(TipoDeEnvio::DESCONHECIDO, $vazio['tipo'], (string) $canal);
            $this->assertSame('—', $vazio['curto'], (string) $canal);
        }
    }

    public function test_loja_usa_a_transportadora_do_pedido(): void
    {
        $envio = TipoDeEnvio::montar('loja', null, 'PAC — Correios');

        $this->assertSame(TipoDeEnvio::OUTRO, $envio['tipo']);
        $this->assertSame('PAC — Correios', $envio['label']);
    }
}
