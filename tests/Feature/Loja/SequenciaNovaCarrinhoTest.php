<?php

namespace Tests\Feature\Loja;

use App\Models\User;
use App\Modules\Cart\Mail\CadernoDoCarrinho;
use App\Modules\Cart\Mail\SequenciaCarrinho;
use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Cart\Support\Sequencia\CalendarioSequencia;
use App\Modules\Cart\Support\Sequencia\MateriaDoCarrinho;
use App\Modules\Cart\Support\Sequencia\ParadasSequencia;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Editorial\Models\EditorialPost;
use App\Modules\Newsletter\Models\NewsletterInscrito;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sequência nova de carrinho abandonado (pedido do Lira 26/11/2026: "tá
 * disparando demais"): D+1 WhatsApp, D+3 WhatsApp, D+5 e-mail do Caderno
 * KazaKora com a matéria do produto, D+7 último WhatsApp, e acabou.
 */
class SequenciaNovaCarrinhoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-26 11:00', 'America/Sao_Paulo'));
        config([
            'loja.carrinho_sequencia_ativa' => true,
            'services.gemini.api_key' => null,
            'services.whatsapp.access_token' => 'token-teste',
            'services.whatsapp.phone_number_id' => '123456',
            'services.whatsapp.templates.carrinho_lembrete' => 'carrinho_lembrete_kazakora',
            'services.whatsapp.templates.carrinho_novidade' => 'carrinho_novidade_kazakora',
            'loja.carrinho_promocoes' => [],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.teste']]])]);
        Mail::fake();
    }

    private function microfone(): Product
    {
        $categoria = Category::factory()->create(['name' => 'Gadgets']);

        return Product::factory()->create(['name' => 'Áudio Limpo nas Gravações: Microfone de Lapela Sem Fio', 'slug' => 'microfone-de-lapela', 'category_id' => $categoria->id, 'price' => 100, 'stock' => 5, 'is_active' => true]);
    }

    private function materia(array $extra = [], array $produtos = []): EditorialPost
    {
        static $n = 0;
        $n++;
        $post = EditorialPost::create([
            'slug' => 'materia-'.$n,
            'title' => 'Matéria '.$n,
            'excerpt' => 'Resumo da matéria '.$n,
            'body' => 'Corpo',
            'type' => 'news',
            'category' => 'eletroportateis-e-casa',
            'status' => 'published',
            'published_at' => now()->subDays(10 - $n),
            'site' => 'kazakora',
            ...$extra,
        ]);
        $post->products()->sync($produtos);

        return $post;
    }

    private function registro(Product $produto, array $extra = []): Registro
    {
        return Registro::create([
            'site' => 'kazakora',
            'origem' => Registro::ORIGEM_LEAD,
            'origem_id' => random_int(1000, 999999),
            'nome' => 'Cliente Teste',
            'email' => 'cliente.teste@example.com',
            'telefone' => '5511991234567',
            'itens' => [['product_id' => $produto->id, 'nome' => $produto->name, 'quantidade' => 1, 'subtotal' => 100]],
            'produto_id' => $produto->id,
            'subtotal' => 100,
            'abandonado_em' => now()->subDays(6),
            'inicio_sequencia_em' => now()->subDays(5)->subMinute(),
            'disparos_enviados' => 2,
            'ultimo_disparo_em' => now()->subDays(2),
            'proximo_disparo_em' => now()->subMinute(),
            'historico' => [],
            ...$extra,
        ]);
    }

    public function test_materia_escolhida_e_a_mais_aderente_ao_produto(): void
    {
        $produto = $this->microfone();
        $this->materia(['title' => 'Decoração de Natal com segurança']);
        $porPalavra = $this->materia(['title' => 'Microfone sem fio: como gravar com áudio limpo', 'category' => 'gadgets']);
        $this->materia(['title' => 'Cabo de celular: cuidados', 'category' => 'gadgets']);

        $materia = app(MateriaDoCarrinho::class)->escolher('kazakora', $produto);
        $this->assertSame($porPalavra->id, $materia['post']->id);
        $this->assertStringContainsString('/blog/'.$porPalavra->slug, $materia['link']);

        // Ligada ao produto ganha de tudo.
        $ligada = $this->materia(['title' => 'Outra coisa qualquer'], [$produto->id]);
        $this->assertSame($ligada->id, app(MateriaDoCarrinho::class)->escolher('kazakora', $produto)['post']->id);

        // Matéria da FerraKora nunca entra no Caderno KazaKora.
        $this->materia(['title' => 'Microfone de lapela sem fio gravações áudio limpo', 'site' => 'ferrakora'], [$produto->id]);
        $this->assertSame($ligada->id, app(MateriaDoCarrinho::class)->escolher('kazakora', $produto)['post']->id);
    }

    public function test_sem_materia_aderente_vai_a_mais_recente(): void
    {
        $produto = $this->microfone();
        $this->materia(['title' => 'Árvore de Natal']);
        $recente = $this->materia(['title' => 'Janela sem manchas', 'published_at' => now()->subHour()]);

        $this->assertSame($recente->id, app(MateriaDoCarrinho::class)->escolher('kazakora', $produto)['post']->id);
    }

    public function test_d5_e_o_email_do_caderno_com_a_materia_e_o_link_do_produto(): void
    {
        $produto = $this->microfone();
        $post = $this->materia(['title' => 'Microfone de lapela: dicas para gravar melhor', 'category' => 'gadgets']);
        $registro = $this->registro($produto);

        $this->artisan('loja:carrinho-abandonado-sequencia')->assertSuccessful();

        Http::assertNothingSent();
        Mail::assertSent(CadernoDoCarrinho::class, function (CadernoDoCarrinho $mail) use ($post) {
            $html = $mail->render();

            return $mail->hasTo('cliente.teste@example.com')
                && $mail->materia['titulo'] === $post->title
                && str_contains($mail->linkProduto, '/produtos/microfone-de-lapela')
                && str_contains($html, 'Caderno KazaKora')
                && str_contains($html, 'Ver o produto')
                && str_contains($html, 'Sair com 1 clique');
        });
        $registro->refresh();
        $this->assertSame(3, $registro->disparos_enviados);
        $this->assertSame($post->id, collect($registro->historico)->last()['materia_id']);
        // D+7 (último WhatsApp) fica para depois, nunca no mesmo dia.
        $this->assertSame('2026-11-28', $registro->proximo_disparo_em->setTimezone('America/Sao_Paulo')->format('Y-m-d'));
    }

    public function test_d5_pula_quem_saiu_do_caderno_e_d7_e_o_ultimo(): void
    {
        $produto = $this->microfone();
        NewsletterInscrito::create(['email' => 'cliente.teste@example.com', 'status' => NewsletterInscrito::DESCADASTRADO, 'descadastrado_em' => now()]);
        $registro = $this->registro($produto);

        $this->artisan('loja:carrinho-abandonado-sequencia');

        Mail::assertNothingSent();
        Http::assertNothingSent();
        $registro->refresh();
        $this->assertSame(3, $registro->disparos_enviados);
        $this->assertSame('pulado', collect($registro->historico)->last()['status']);

        // D+7: último WhatsApp e acabou.
        $this->travelTo($registro->proximo_disparo_em->copy()->addMinute());
        $this->artisan('loja:carrinho-abandonado-sequencia');
        Http::assertSent(fn ($r) => ($r['template']['name'] ?? null) === 'carrinho_novidade_kazakora'
            && str_contains($r['template']['components'][1]['parameters'][2]['text'] ?? '', 'última mensagem'));
        $registro->refresh();
        $this->assertTrue($registro->parado);
        $this->assertSame(Registro::PAROU_FIM, $registro->motivo_parada);
        Mail::assertNothingSent();
    }

    public function test_sem_celular_o_whatsapp_vai_por_email(): void
    {
        $produto = $this->microfone();
        $registro = $this->registro($produto, ['telefone' => null, 'disparos_enviados' => 3]);
        $registro->forceFill(['inicio_sequencia_em' => now()->subDays(7)->subMinute()])->save();

        $this->artisan('loja:carrinho-abandonado-sequencia');

        Http::assertNothingSent();
        Mail::assertSent(SequenciaCarrinho::class, fn ($m) => $m->numero === 4 && str_contains($m->build()->subject, 'Última lembrança'));
    }

    public function test_nunca_duas_mensagens_de_carrinho_no_mesmo_dia_para_a_mesma_pessoa(): void
    {
        $produto = $this->microfone();
        // Outro carrinho da mesma pessoa (outra loja) já recebeu hoje.
        $this->registro($produto, ['site' => 'ferrakora', 'telefone' => '11991234567', 'email' => 'outro@exemplo.com', 'ultimo_disparo_em' => now()->subHour(), 'proximo_disparo_em' => now()->addDays(2)]);
        $registro = $this->registro($produto, ['disparos_enviados' => 1]);

        $this->artisan('loja:carrinho-abandonado-sequencia');

        Http::assertNothingSent();
        Mail::assertNothingSent();
        $registro->refresh();
        $this->assertSame(1, $registro->disparos_enviados);
        $this->assertSame('2026-11-27 09:00', $registro->proximo_disparo_em->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'));
    }

    public function test_compra_detectada_pelo_usuario_mesmo_com_telefone_errado_no_pedido(): void
    {
        $produto = $this->microfone();
        $registro = $this->registro($produto, ['email' => 'carrinho@exemplo.com', 'user_id' => null, 'abandonado_em' => now()->subDay()]);
        $user = User::factory()->create(['email' => 'cadastro@exemplo.com', 'phone' => '(11) 99123-4567']);
        Order::create([
            'user_id' => $user->id, 'status' => Order::STATUS_PAID, 'shipping_email' => 'cadastro@exemplo.com',
            'shipping_name' => 'Cliente', 'shipping_phone' => '(55) 11991-2345', 'shipping_zip' => '01310100',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);

        $this->assertSame(Registro::PAROU_COMPROU, app(ParadasSequencia::class)->aplicar($registro));
        $this->artisan('loja:carrinho-abandonado-sequencia');
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_comando_encerra_quem_passou_e_recalcula_quem_esta_no_meio(): void
    {
        Storage::fake('local');
        $produto = $this->microfone();
        $tresDisparos = $this->registro($produto, ['disparos_enviados' => 3, 'inicio_sequencia_em' => now()->subDays(6), 'proximo_disparo_em' => now()->addDays(2)]);
        $quatro = $this->registro($produto, ['email' => 'b@exemplo.com', 'telefone' => '5511988887777', 'disparos_enviados' => 4]);
        $passou = $this->registro($produto, ['email' => 'c@exemplo.com', 'telefone' => '5511977776666', 'disparos_enviados' => 2, 'inicio_sequencia_em' => now()->subDays(8)]);

        $this->artisan('loja:carrinho-sequencia-nova', ['--seco' => true])->assertSuccessful();
        $this->assertFalse($quatro->fresh()->parado);
        Storage::disk('local')->assertDirectoryEmpty('backups');

        $this->artisan('loja:carrinho-sequencia-nova')->assertSuccessful();
        $this->assertSame(Registro::PAROU_SEQUENCIA_NOVA, $quatro->fresh()->motivo_parada);
        $this->assertSame(Registro::PAROU_SEQUENCIA_NOVA, $passou->fresh()->motivo_parada);
        $tresDisparos->refresh();
        $this->assertFalse($tresDisparos->parado);
        // Falta só o D+7 (último WhatsApp): amanhã às 11h (início + 7 dias).
        $this->assertSame('2026-11-27 11:00', $tresDisparos->proximo_disparo_em->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'));
        $this->assertCount(1, Storage::disk('local')->files('backups'));

        // Encerrados não recebem nada.
        $this->artisan('loja:carrinho-abandonado-sequencia');
        Http::assertNothingSent();
        Mail::assertNothingSent();

        // Desfazer volta como estava.
        $this->artisan('loja:carrinho-sequencia-nova', ['--desfazer' => Storage::disk('local')->files('backups')[0]])->assertSuccessful();
        $this->assertFalse($quatro->fresh()->parado);
    }

    public function test_previa_lista_o_que_falta_com_data_e_canal(): void
    {
        $produto = $this->microfone();
        $this->materia(['title' => 'Microfone de lapela: dicas', 'category' => 'gadgets']);
        $this->registro($produto, ['disparos_enviados' => 1, 'inicio_sequencia_em' => now()->subDays(2), 'ultimo_disparo_em' => now()->subDay(), 'proximo_disparo_em' => now()->addDay()]);

        Artisan::call('loja:carrinho-abandonado-sequencia', ['--previa' => true]);
        $saida = Artisan::output();

        $this->assertStringContainsString('2º D+3', $saida);
        $this->assertStringContainsString('3º D+5', $saida);
        $this->assertStringContainsString('4º D+7', $saida);
        $this->assertStringContainsString('Microfone de lapela: dicas', $saida);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        $this->assertSame(1, Registro::sole()->disparos_enviados);
    }

    public function test_calendario_tem_quatro_contatos(): void
    {
        $this->assertSame([1, 3, 5, 7], CalendarioSequencia::DIAS);
        $this->assertSame(4, CalendarioSequencia::TOTAL);
    }
}
