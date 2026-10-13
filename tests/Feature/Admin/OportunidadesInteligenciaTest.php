<?php

namespace Tests\Feature\Admin;

use App\Models\MercadoLivreToken;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\Oportunidades\InteligenciaMercadoLivre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Botão "Inteligência" de Oportunidades › Mercado Livre (Lira 2026-10-13):
 * demanda (posição), concorrência (vendedores / marca forte) e margem
 * (custo ÷ 0,56) em cima dos mais vendidos da categoria.
 */
class OportunidadesInteligenciaTest extends TestCase
{
    use RefreshDatabase;

    private function produtoMl(array $dados): array
    {
        static $n = 0;
        $n++;

        return $dados + [
            'id' => "MLB{$n}", 'posicao' => $n, 'nome' => "Produto {$n}", 'imagem' => null,
            'link' => "https://www.mercadolivre.com.br/p/MLB{$n}", 'preco_min' => 50.0, 'preco_max' => 60.0,
            'vendedores' => 3, 'voce_vende' => false,
        ];
    }

    private function grupoDe(array $resultado, string $id): array
    {
        foreach ($resultado['grupos'] as $grupo => $itens) {
            foreach ($itens as $item) {
                if ($item['id'] === $id) {
                    return [$grupo, $item];
                }
            }
        }
        $this->fail("Produto {$id} não apareceu em nenhum grupo.");
    }

    public function test_classifica_oportunidade_evitar_e_ja_vendemos(): void
    {
        Product::factory()->create(['name' => 'Caminhão Dinossauro T-Rex Maleta Com 2 Carrinhos', 'price' => 156.36, 'cost_price' => 65, 'is_active' => true]);
        Product::factory()->create(['name' => 'Brinquedo Cozinha Pia Lava-Louças Faz De Conta', 'price' => 172.48, 'cost_price' => 40, 'is_active' => true]);
        Product::factory()->create(['name' => 'Organizador de Gaveta Modular', 'price' => 49.9, 'cost_price' => 10, 'is_active' => true]);
        // Inativo não entra no casamento.
        Product::factory()->create(['name' => 'Patinete Infantil 3 Rodas Led Dobrável', 'price' => 99, 'cost_price' => 20, 'is_active' => false]);

        $r = app(InteligenciaMercadoLivre::class)->classificar(['produtos' => [
            $this->produtoMl(['id' => 'PAT', 'posicao' => 1, 'nome' => 'Patinete Infantil 3 Rodas com Led/Dobrável Menino/Menina', 'preco_min' => 51.9, 'preco_max' => 84.57, 'vendedores' => 2]),
            $this->produtoMl(['id' => 'FIG', 'posicao' => 4, 'nome' => 'Copa do Mundo 2026 Kit com 12 envelopes de figurinhas', 'preco_min' => 24, 'preco_max' => 200, 'vendedores' => 59]),
            $this->produtoMl(['id' => 'OMO', 'posicao' => 2, 'nome' => 'Sabão Líquido Omo Lavagem Perfeita 3L', 'preco_min' => 45, 'preco_max' => 70, 'vendedores' => 4]),
            $this->produtoMl(['id' => 'CAM', 'posicao' => 5, 'nome' => 'Caminhão Dinossauro Pista Com Carrinhos Maleta Transporte Cor Roxo', 'preco_min' => 78.9, 'preco_max' => 114, 'vendedores' => 2]),
            $this->produtoMl(['id' => 'COZ', 'posicao' => 8, 'nome' => 'Cozinha Infantil Brinquedo Com Luz 33 Acessórios Sai Água Rosa', 'preco_min' => 64.9, 'preco_max' => 169.9, 'vendedores' => 2]),
            $this->produtoMl(['id' => 'ORG', 'posicao' => 9, 'nome' => 'Organizador De Gaveta Modular Encaixe', 'preco_min' => 39.9, 'preco_max' => 59.9, 'vendedores' => 6]),
            $this->produtoMl(['id' => 'CAMA', 'posicao' => 15, 'nome' => 'Cama Elástica Pula Pula Trampolim 1,4 Metros', 'preco_min' => 289.9, 'preco_max' => 479.8, 'vendedores' => 4]),
        ], 'ocultos' => 3, 'atualizado_em' => '2026-10-13T10:00:00-03:00', 'erro' => null]);

        // Oportunidade: 2 vendedores, R$ 51,90, sem marca; custo máx. = 51,90 × 0,56.
        [$grupo, $pat] = $this->grupoDe($r, 'PAT');
        $this->assertSame('oportunidade', $grupo);
        $this->assertSame(29.06, $pat['custo_max_15']);
        $this->assertNull($pat['nosso']);
        $this->assertSame('https://www.tiktok.com/search?q='.rawurlencode('Patinete Infantil 3 Rodas com'), $pat['videos']['tiktok']);
        $this->assertStringEndsWith('&sp=EgIYAQ%253D%253D', $pat['videos']['youtube']);

        // Evitar por vendedores e por marca forte.
        [$grupo, $fig] = $this->grupoDe($r, 'FIG');
        $this->assertSame('evitar', $grupo);
        $this->assertSame('59 vendedores', $fig['motivo_guerra']);
        [$grupo, $omo] = $this->grupoDe($r, 'OMO');
        $this->assertSame('evitar', $grupo);
        $this->assertSame('marca forte: Omo', $omo['motivo_guerra']);

        // Já vendemos: Caminhão #118 (custo 65 → piso 116,07) não compete.
        [$grupo, $cam] = $this->grupoDe($r, 'CAM');
        $this->assertSame('ja_vendemos', $grupo);
        $this->assertSame(65.0, $cam['nosso']['custo']);
        $this->assertSame(116.07, $cam['nosso']['piso_15']);
        $this->assertFalse($cam['nosso']['confirmado']);
        $this->assertStringStartsWith('Não compete', $cam['veredito']);
        $this->assertStringContainsString('R$ 44,18', $cam['veredito']);

        // Cozinha #116 (custo 40 → piso 71,43) cabe até o maior preço.
        [$grupo, $coz] = $this->grupoDe($r, 'COZ');
        $this->assertSame('ja_vendemos', $grupo);
        $this->assertSame('Cabe entre R$ 71,43 e R$ 169,90.', $coz['veredito']);

        // Organizador (custo 10 → piso 17,86) dá margem.
        [$grupo, $org] = $this->grupoDe($r, 'ORG');
        $this->assertSame('ja_vendemos', $grupo);
        $this->assertStringStartsWith('Dá margem', $org['veredito']);

        [$grupo] = $this->grupoDe($r, 'CAMA');
        $this->assertSame('outros', $grupo);

        $this->assertSame(['oportunidade' => 1, 'ja_vendemos' => 3, 'evitar' => 2, 'outros' => 1, 'total' => 7], array_diff_key($r['resumo'], ['texto' => 1]));
        $this->assertSame('1 oportunidade, 3 que já vendemos, 2 para evitar.', $r['resumo']['texto']);
        $this->assertSame(3, $r['ocultos']);
        // Ordem pela posição dentro do grupo.
        $this->assertSame(['OMO', 'FIG'], array_column($r['grupos']['evitar'], 'id'));
    }

    public function test_rota_json_usa_os_mais_vendidos_da_categoria(): void
    {
        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(), 'ml_user_id' => 123456789, 'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token', 'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6), 'scopes' => ['offline_access', 'read', 'write'],
        ]);
        MarketplaceAccount::query()->create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123456789', 'connected_at' => now(),
        ]);
        $api = 'https://api.mercadolibre.com/';
        Http::fake([
            $api.'highlights/MLB/category/MLB1132*' => Http::response(['content' => [['id' => 'MLB111', 'position' => 1, 'type' => 'PRODUCT']]]),
            $api.'products/MLB111/items*' => Http::response(['paging' => ['total' => 2], 'results' => [['seller_id' => 9, 'price' => 69.9], ['seller_id' => 8, 'price' => 138.5]]]),
            $api.'products/MLB111*' => Http::response(['name' => 'Bicicleta Infantil De Equilíbrio Sem Pedal', 'pictures' => []]),
            $api.'trends/MLB/MLB1132*' => Http::response([]),
            $api.'*' => Http::response([]),
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->getJson('/admin/oportunidades/mercado-livre/inteligencia?categoria=MLB1132')
            ->assertOk()
            ->assertJsonPath('categoria', 'MLB1132')
            ->assertJsonPath('resumo.oportunidade', 1)
            ->assertJsonPath('grupos.oportunidade.0.nome', 'Bicicleta Infantil De Equilíbrio Sem Pedal')
            ->assertJsonPath('grupos.oportunidade.0.custo_max_15', 39.14);

        // Categoria inválida volta para a padrão, como na tela.
        $this->actingAs($admin)->getJson('/admin/oportunidades/mercado-livre/inteligencia?categoria=lixo')
            ->assertOk()
            ->assertJsonPath('categoria', 'MLB1574');
    }

    public function test_rota_json_exige_permissao(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))
            ->getJson('/admin/oportunidades/mercado-livre/inteligencia')->assertForbidden();
    }
}
