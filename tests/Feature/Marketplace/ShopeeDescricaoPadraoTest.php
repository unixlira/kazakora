<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Support\ShopeeDescricaoPadrao;
use App\Modules\WhatsApp\Services\GeminiClient;
use Mockery;
use Tests\TestCase;

class ShopeeDescricaoPadraoTest extends TestCase
{
    private function servico(?string $respostaGemini, bool $configurado = true): ShopeeDescricaoPadrao
    {
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn($configurado);

        if ($respostaGemini !== null) {
            $gemini->shouldReceive('generate')->andReturn($respostaGemini);
        }

        return new ShopeeDescricaoPadrao($gemini);
    }

    private function produto(string $descricao): Product
    {
        return new Product(['name' => 'Lixeira Inox 5L', 'description' => $descricao]);
    }

    private const NO_PADRAO = "✨ LIXEIRA INOX 5L\n\nPratica.\n\n🔥 PRINCIPAIS VANTAGENS\n✅ Inox\n\n📋 ESPECIFICAÇÕES TÉCNICAS\nCapacidade: 5 L\n\n📦 CONTEÚDO DA EMBALAGEM\n✅ 1 Lixeira\n\n🛒 Garanta a sua!";

    public function test_descricao_ja_no_padrao_passa_sem_mudanca_e_sem_chamar_ia(): void
    {
        $this->assertSame(self::NO_PADRAO, $this->servico(null)->padronizar($this->produto(self::NO_PADRAO)));
    }

    public function test_texto_sem_emoji_nao_e_conforme(): void
    {
        $this->assertFalse($this->servico(null)->conforme("LIXEIRA\nPRINCIPAIS VANTAGENS\nESPECIFICAÇÕES TÉCNICAS\nCONTEÚDO DA EMBALAGEM"));
    }

    public function test_usa_texto_do_gemini_quando_ele_segue_o_padrao(): void
    {
        $texto = $this->servico(self::NO_PADRAO)->padronizar($this->produto('Lixeira de inox com tampa.'));

        $this->assertSame(self::NO_PADRAO, $texto);
    }

    public function test_gemini_sem_emoji_cai_na_montagem_propria_com_emoji(): void
    {
        $servico = $this->servico('Lixeira sem formatação nenhuma.');
        $texto = $servico->padronizar($this->produto("Lixeira de inox com tampa e pedal.\nFácil de limpar e resistente à ferrugem."));

        $this->assertTrue($servico->conforme($texto));
        $this->assertStringContainsString('LIXEIRA INOX 5L', $texto);
        $this->assertStringContainsString('Fácil de limpar', $texto);
    }

    public function test_gemini_indisponivel_ou_nao_configurado_ainda_devolve_padrao_com_emoji(): void
    {
        $this->assertTrue($this->servico(null, false)->conforme($this->servico(null, false)->padronizar($this->produto('Texto simples.'))));
        $this->assertTrue($this->servico(null, false)->conforme($this->servico(null, false)->padronizar($this->produto(''))));
    }
}
