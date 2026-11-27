<?php

namespace Tests\Feature\Loja;

use App\Models\User;
use App\Modules\Cart\Mail\SequenciaCarrinho;
use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Cart\Support\Sequencia\CalendarioSequencia;
use App\Modules\Cart\Support\Sequencia\LinkDoCarrinho;
use App\Modules\Cart\Support\Sequencia\PromocaoDoCarrinho;
use App\Modules\Cart\Support\Sequencia\NoticiaDoProduto;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Sequência de carrinho abandonado: desde 26/11/2026, D+1 e D+3 WhatsApp, D+5 e-mail do Caderno, D+7 último WhatsApp; sem desconto. */
class SequenciaCarrinhoAbandonadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Quarta-feira, 11h em São Paulo: dentro da janela de envio.
        $this->travelTo(Carbon::parse('2026-11-18 11:00', 'America/Sao_Paulo'));
        config([
            // Fora do kazakora.com o envio fica desligado; os testes de envio ligam.
            'loja.carrinho_sequencia_ativa' => true,
            'services.gemini.api_key' => null,
            'services.whatsapp.access_token' => null,
            'services.whatsapp.phone_number_id' => null,
            'services.whatsapp.templates.carrinho_lembrete' => null,
            'services.whatsapp.templates.carrinho_novidade' => null,
        ]);
        Http::preventStrayRequests();
    }

    private function produto(array $extra = []): Product
    {
        return Product::factory()->create(['price' => 100, 'stock' => 10, 'is_active' => true, ...$extra]);
    }

    private function lead(array $extra = [], int $minutosParado = 90, ?Product $produto = null): LeadCheckout
    {
        $produto ??= $this->produto();
        $lead = LeadCheckout::create([
            'site' => 'kazakora',
            'nome' => 'MARIA DA SILVA',
            'email' => 'maria@exemplo.com',
            'telefone' => '11999990000',
            'itens' => [['product_id' => $produto->id, 'nome' => $produto->name, 'quantidade' => 1, 'preco_unitario' => 100, 'subtotal' => 100]],
            'subtotal' => 100,
            'status' => LeadCheckout::STATUS_ABERTO,
            'etapa' => LeadCheckout::ETAPA_ENTREGA,
            ...$extra,
        ]);
        $lead->forceFill(['updated_at' => now()->subMinutes($minutosParado), 'created_at' => now()->subMinutes($minutosParado)])->saveQuietly();

        return $lead;
    }

    private function ligarWhatsApp(): void
    {
        config([
            'services.whatsapp.access_token' => 'token-teste',
            'services.whatsapp.phone_number_id' => '123456',
            'services.whatsapp.templates.carrinho_lembrete' => 'carrinho_lembrete_kazakora',
            'services.whatsapp.templates.carrinho_novidade' => 'carrinho_novidade_kazakora',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.teste']]])]);
    }

    public function test_calendario_d1_d3_d5_d7_na_janela_e_para_no_quarto(): void
    {
        $registro = new Registro(['inicio_sequencia_em' => Carbon::parse('2026-11-01 15:00', 'America/Sao_Paulo'), 'disparos_enviados' => 0]);

        $esperado = ['2026-11-02 15:00', '2026-11-04 15:00', '2026-11-06 15:00', '2026-11-08 15:00'];
        foreach ($esperado as $i => $quando) {
            $registro->disparos_enviados = $i;
            $this->assertSame($quando, CalendarioSequencia::proximoEm($registro)->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'));
        }
        $this->assertSame(['whatsapp', 'whatsapp', 'email', 'whatsapp'], array_map(fn ($n) => CalendarioSequencia::canal($n), [1, 2, 3, 4]));

        // Nunca dois no mesmo dia: o último saiu às 9h do dia do próximo, então ele vai para o dia seguinte.
        $registro->disparos_enviados = 1;
        $registro->ultimo_disparo_em = Carbon::parse('2026-11-04 09:00', 'America/Sao_Paulo');
        $this->assertSame('2026-11-05 09:00', CalendarioSequencia::proximoEm($registro)->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'));
        $registro->ultimo_disparo_em = null;

        $registro->disparos_enviados = 4;
        $this->assertNull(CalendarioSequencia::proximoEm($registro));

        // Fora da janela: 22h vai para as 9h do dia seguinte; 6h vai para as 9h do mesmo dia.
        $registro->disparos_enviados = 0;
        $registro->inicio_sequencia_em = Carbon::parse('2026-11-01 22:30', 'America/Sao_Paulo');
        $this->assertSame('2026-11-03 09:00', CalendarioSequencia::proximoEm($registro)->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'));
        $registro->inicio_sequencia_em = Carbon::parse('2026-11-01 06:10', 'America/Sao_Paulo');
        $this->assertSame('2026-11-02 09:00', CalendarioSequencia::proximoEm($registro)->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-01 20:00', CalendarioSequencia::janela(Carbon::parse('2026-11-01 19:59', 'America/Sao_Paulo'))->addMinute()->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'));
    }

    public function test_sequencia_inteira_manda_quatro_e_para(): void
    {
        Mail::fake();
        $this->lead();

        $this->artisan('loja:carrinho-abandonado-sequencia')->assertSuccessful();
        $registro = Registro::sole();
        // Abandonado há 90 min: o 1º sai em D+1.
        $this->assertSame(0, $registro->disparos_enviados);

        foreach ([1, 2, 2, 2] as $i => $dias) {
            $this->travel($dias)->days();
            $this->artisan('loja:carrinho-abandonado-sequencia')->assertSuccessful();
            $this->assertSame($i + 1, $registro->fresh()->disparos_enviados, "disparo ".($i + 1));
        }

        $registro->refresh();
        $this->assertTrue($registro->parado);
        $this->assertSame(Registro::PAROU_FIM, $registro->motivo_parada);
        // Sem template de WhatsApp no teste: os 3 lembretes vão por e-mail, e o D+5 é o Caderno.
        Mail::assertSentCount(4);
        Mail::assertSent(\App\Modules\Cart\Mail\CadernoDoCarrinho::class, 1);
        $this->assertSame([1, 2, 3, 4], array_column($registro->historico, 'numero'));

        $this->travel(30)->days();
        $this->artisan('loja:carrinho-abandonado-sequencia');
        Mail::assertSentCount(4);
    }

    public function test_estoque_antigo_sai_na_primeira_rodada_e_mais_de_30_dias_nao_entra(): void
    {
        Mail::fake();
        $antigo = $this->lead([], 60 * 24 * 10);
        $velho = $this->lead(['email' => 'velho@exemplo.com', 'telefone' => '11988887777'], 60 * 24 * 31);

        $this->artisan('loja:carrinho-abandonado-sequencia')->assertSuccessful();

        $registro = Registro::sole();
        $this->assertSame($antigo->id, $registro->origem_id);
        $this->assertSame(1, $registro->disparos_enviados);
        Mail::assertSent(SequenciaCarrinho::class, fn ($m) => $m->hasTo('maria@exemplo.com') && $m->numero === 1);
        $this->assertNull(Registro::where('origem_id', $velho->id)->first());

        // O 2º (D+3) vem 2 dias depois do 1º.
        $this->assertSame(now()->addDays(2)->format('Y-m-d'), $registro->proximo_disparo_em->format('Y-m-d'));
    }

    public function test_fora_da_janela_espera_as_9h(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-18 22:00', 'America/Sao_Paulo'));
        $this->lead([], 60 * 24 * 3);

        $this->artisan('loja:carrinho-abandonado-sequencia');
        Mail::assertNothingSent();
        $this->assertSame('2026-11-19 09:00', Registro::sole()->proximo_disparo_em->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'));

        $this->travelTo(Carbon::parse('2026-11-19 09:05', 'America/Sao_Paulo'));
        $this->artisan('loja:carrinho-abandonado-sequencia');
        Mail::assertSentCount(1);
    }

    public function test_para_quando_compra(): void
    {
        Mail::fake();
        $this->lead([], 60 * 24 * 5);
        Order::create([
            'status' => Order::STATUS_PAID, 'shipping_email' => 'outro@exemplo.com',
            'shipping_name' => 'Maria', 'shipping_phone' => '(11) 99999-0000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);

        $this->artisan('loja:carrinho-abandonado-sequencia');

        Mail::assertNothingSent();
        $registro = Registro::sole();
        $this->assertTrue($registro->parado);
        $this->assertSame(Registro::PAROU_COMPROU, $registro->motivo_parada);
    }

    /** Decisão do Lira (25/11/2026): pedido só gerado (Pix/boleto esperando) ou cancelado não para; pago para. */
    public function test_pedido_so_gerado_ou_cancelado_nao_para_e_pago_para(): void
    {
        Mail::fake();
        $this->lead([], 60 * 24 * 5);
        $pedido = fn (string $status) => Order::create([
            'status' => $status, 'shipping_email' => 'maria@exemplo.com',
            'shipping_name' => 'Maria', 'shipping_phone' => '(11) 99999-0000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);
        $aguardando = $pedido(Order::STATUS_AWAITING_PAYMENT);
        $pedido(Order::STATUS_CANCELLED);
        $pedido(Order::STATUS_PENDING);

        $this->artisan('loja:carrinho-abandonado-sequencia')->assertSuccessful();

        Mail::assertSent(\App\Modules\Cart\Mail\SequenciaCarrinho::class, 1);
        $registro = Registro::sole();
        $this->assertFalse($registro->parado);
        $this->assertSame(1, $registro->disparos_enviados);

        // Pagou: a próxima rodada para.
        $aguardando->forceFill(['status' => Order::STATUS_PAID])->saveQuietly();
        $this->artisan('loja:carrinho-abandonado-sequencia')->assertSuccessful();
        $this->assertTrue($registro->fresh()->parado);
        $this->assertSame(Registro::PAROU_COMPROU, $registro->fresh()->motivo_parada);

        // A lista de "pago" da sequência é a mesma do lead (LeadsDigitados::pago).
        foreach ([Order::STATUS_PENDING, Order::STATUS_AWAITING_PAYMENT, Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED] as $status) {
            $this->assertSame(
                \App\Modules\Checkout\Support\LeadsDigitados::pago(new Order(['status' => $status])),
                in_array($status, \App\Modules\Cart\Support\Sequencia\ParadasSequencia::STATUS_PAGOS, true),
                $status,
            );
        }
    }

    public function test_para_quando_o_lead_vira_convertido(): void
    {
        Mail::fake();
        $lead = $this->lead();
        $this->artisan('loja:carrinho-abandonado-sequencia');

        LeadCheckout::query()->whereKey($lead->id)->update(['status' => LeadCheckout::STATUS_CONVERTIDO]);
        $this->travel(1)->days();
        $this->artisan('loja:carrinho-abandonado-sequencia');

        Mail::assertNothingSent();
        $this->assertSame(Registro::PAROU_COMPROU, Registro::sole()->motivo_parada);
    }

    public function test_para_quando_saiu_das_promocoes_e_quando_pede_para_parar(): void
    {
        Mail::fake();
        $cliente = User::factory()->create(['email' => 'maria@exemplo.com']);
        $cliente->forceFill(['recebe_promocoes' => false])->save();
        $this->lead([], 60 * 24 * 3);
        $this->lead(['email' => 'joao@exemplo.com', 'telefone' => '21977776666', 'nome' => 'João'], 60 * 24 * 3);

        $this->artisan('loja:carrinho-abandonado-sequencia');

        $this->assertSame(Registro::PAROU_SEM_PROMOCOES, Registro::where('email', 'maria@exemplo.com')->sole()->motivo_parada);
        $joao = Registro::where('email', 'joao@exemplo.com')->sole();
        $this->assertSame(1, $joao->disparos_enviados);

        // Descadastro do e-mail.
        $this->get(URL::signedRoute('carrinho.sequencia.parar', ['registro' => $joao->id]))->assertRedirect('/');
        $this->assertSame(Registro::PAROU_PEDIU, $joao->fresh()->motivo_parada);
        $this->get(route('carrinho.sequencia.parar', ['registro' => $joao->id]))->assertForbidden();
    }

    public function test_parar_no_whatsapp_tira_da_sequencia(): void
    {
        Mail::fake();
        $this->lead([], 60 * 24 * 3);
        $this->artisan('loja:carrinho-abandonado-sequencia');

        // Webhook manda o número sem o nono dígito.
        $this->postJson('/api/webhooks/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'messages' => [['from' => '551199990000', 'id' => 'wamid.parar', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Parar']]],
            ]]]]],
        ])->assertOk();

        $this->assertSame(Registro::PAROU_PEDIU, Registro::sole()->motivo_parada);
    }

    public function test_texto_nunca_fala_de_desconto_e_gemini_fora_da_regra_vira_reserva(): void
    {
        $produto = $this->produto(['name' => 'Organizador de Gaveta']);

        foreach (range(2, 6) as $numero) {
            $texto = app(NoticiaDoProduto::class)->escrever($produto, $numero)['texto'];
            $this->assertDoesNotMatchRegularExpression('/desconto|cupom|%/iu', $texto);
            $this->assertStringContainsString('Organizador de Gaveta', $texto);
            $this->assertLessThanOrEqual(NoticiaDoProduto::MAXIMO, mb_strlen($texto));
        }

        // Gemini respondeu com desconto: descarta e usa a reserva.
        config(['services.gemini.api_key' => 'chave-teste']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'Aproveite 10% de desconto com o cupom VOLTA!']]]]]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => "Separe os itens por tipo antes de\nguardar: fica mais fácil achar tudo."]]]]]]),
        ]);
        $resultado = app(NoticiaDoProduto::class)->escrever($produto, 2);
        $this->assertSame('reserva', $resultado['fonte']);
        $this->assertDoesNotMatchRegularExpression('/desconto|cupom|%/iu', $resultado['texto']);

        // Gemini dentro da regra: usa o texto dele.
        $resultado = app(NoticiaDoProduto::class)->escrever($produto, 3);
        $this->assertSame('gemini', $resultado['fonte']);
        $this->assertSame('Separe os itens por tipo antes de guardar: fica mais fácil achar tudo.', $resultado['texto']);
    }

    public function test_emails_da_sequencia_sem_desconto(): void
    {
        Mail::fake();
        $this->lead([], 60 * 24 * 3);
        $this->artisan('loja:carrinho-abandonado-sequencia');
        foreach ([2, 2, 2] as $dias) {
            $this->travel($dias)->days();
            $this->artisan('loja:carrinho-abandonado-sequencia');
        }

        Mail::assertSentCount(4);
        Mail::assertSent(\App\Modules\Cart\Mail\CadernoDoCarrinho::class, function ($mail) {
            $html = $mail->render();
            $texto = strip_tags((string) preg_replace('#<(style|head)[^>]*>.*?</\1>#si', '', $html));
            $this->assertDoesNotMatchRegularExpression('/desconto|cupom|%/iu', $texto);

            return str_contains($html, 'Caderno KazaKora');
        });
        Mail::assertSent(SequenciaCarrinho::class, function (SequenciaCarrinho $mail) {
            $html = $mail->render();
            $texto = strip_tags((string) preg_replace('#<(style|head)[^>]*>.*?</\1>#si', '', $html));
            $this->assertDoesNotMatchRegularExpression('/desconto|cupom|%/iu', $texto.' '.$mail->subject);
            $this->assertStringContainsString('utm_medium=carrinho_abandonado', $html);

            return true;
        });

        foreach (Registro::sole()->historico as $linha) {
            $this->assertDoesNotMatchRegularExpression('/desconto|cupom|%/iu', (string) ($linha['texto'] ?? ''));
        }
    }

    public function test_promocao_do_livro_entra_no_lugar_da_novidade_ate_30_11(): void
    {
        Mail::fake();
        $this->ligarWhatsApp();
        $this->travelTo(Carbon::parse('2026-11-22 10:00', 'America/Sao_Paulo'));
        $livro = $this->produto(['name' => 'Livro Falante', 'slug' => 'livro-falante-infantil-bilingue-portugues-e-ingles-com-som-11-temas-e-300-palavras']);
        $this->lead([], 60 * 24 * 3, $livro);

        $this->artisan('loja:carrinho-abandonado-sequencia'); // D+1: lembrete, sem promoção
        $this->travel(2)->days();
        $this->artisan('loja:carrinho-abandonado-sequencia'); // D+3: promoção

        $historico = collect(Registro::sole()->historico);
        $this->assertNull($historico->firstWhere('numero', 1)['texto'] ?? null);
        $segundo = $historico->where('numero', 2)->first();
        $this->assertSame('promocao', $segundo['fonte_texto']);
        $this->assertStringContainsString('Lousa Mágica LED vai de graça', $segundo['texto']);
        $this->assertStringContainsString('3%', $segundo['texto']);
        Http::assertSent(fn ($request) => ($request['template']['name'] ?? null) === 'carrinho_novidade_kazakora'
            && str_contains($request['template']['components'][1]['parameters'][2]['text'] ?? '', 'Black Friday'));

        // Com WhatsApp, os lembretes saem só por ele (um canal por disparo).
        Mail::assertNothingSent();

        // Depois de 30/11 volta a novidade comum.
        $this->assertNull(PromocaoDoCarrinho::texto($livro, Carbon::parse('2026-12-01 00:00:01', 'America/Sao_Paulo')));
        $this->assertNull(PromocaoDoCarrinho::texto($this->produto(['slug' => 'organizador'])));
    }

    public function test_link_cai_no_produto_sem_blog(): void
    {
        $produto = $this->produto(['slug' => 'organizador-de-gaveta']);

        $link = app(LinkDoCarrinho::class)->resolver('kazakora', $produto, 3, 'whatsapp');

        $this->assertSame('produto', $link['tipo']);
        $this->assertSame('https://kazakora.com/produtos/organizador-de-gaveta?utm_source=whatsapp&utm_medium=carrinho_abandonado&utm_campaign=d5', $link['url']);
        $this->assertSame('produtos/organizador-de-gaveta?utm_source=whatsapp&utm_medium=carrinho_abandonado&utm_campaign=d5', $link['sufixo']);

        $email = app(LinkDoCarrinho::class)->resolver('kazakora', $produto, 4, 'email');
        $this->assertStringEndsWith('utm_source=email&utm_medium=carrinho_abandonado&utm_campaign=d7', $email['url']);
    }

    public function test_template_vazio_nao_manda_whatsapp_e_manda_email(): void
    {
        Mail::fake();
        Http::fake();
        config(['services.whatsapp.access_token' => 'token-teste', 'services.whatsapp.phone_number_id' => '123456']);
        $this->lead([], 60 * 24 * 3);

        $this->artisan('loja:carrinho-abandonado-sequencia')->assertSuccessful();

        Http::assertNothingSent();
        Mail::assertSentCount(1);
        $historico = Registro::sole()->historico;
        $this->assertSame(['email'], array_column($historico, 'canal'));
    }

    public function test_com_template_manda_whatsapp_com_os_parametros(): void
    {
        Mail::fake();
        $this->ligarWhatsApp();
        $produto = $this->produto(['name' => 'Organizador de Gaveta', 'slug' => 'organizador']);
        $this->lead([], 60 * 24 * 3, $produto);

        $this->artisan('loja:carrinho-abandonado-sequencia');

        Http::assertSent(function ($request) {
            $template = $request['template'] ?? [];

            // D+1 com a foto do produto (27/11/2026): modelo da Manuela, botão em /produtos/{{1}}.
            return ($request['to'] ?? null) === '5511999990000'
                && $template['name'] === 'recuperacao_carrinho_kazakora'
                && $template['components'][0]['type'] === 'header'
                && $template['components'][0]['parameters'][0]['type'] === 'image'
                && $template['components'][1]['parameters'][0]['text'] === 'Maria'
                && $template['components'][1]['parameters'][1]['text'] === 'Organizador de Gaveta'
                && $template['components'][2]['sub_type'] === 'url'
                && $template['components'][2]['parameters'][0]['text'] === 'organizador?utm_source=whatsapp&utm_medium=carrinho_abandonado&utm_campaign=d1';
        });
        // Um canal por disparo: o lembrete sai só no WhatsApp.
        $this->assertSame(['whatsapp'], array_column(Registro::sole()->historico, 'canal'));
        Mail::assertNothingSent();

        // 2º disparo: novidade com imagem no cabeçalho e o texto no {{3}}.
        $this->travel(2)->days();
        $this->artisan('loja:carrinho-abandonado-sequencia');
        Http::assertSent(fn ($request) => ($request['template']['name'] ?? null) === 'carrinho_novidade_kazakora'
            && $request['template']['components'][0]['type'] === 'header'
            && count($request['template']['components'][1]['parameters']) === 3);
    }

    public function test_sem_modelo_com_imagem_o_lembrete_sai_no_modelo_de_texto(): void
    {
        Mail::fake();
        $this->ligarWhatsApp();
        config(['services.whatsapp.templates.carrinho_lembrete_imagem' => null]);
        $produto = $this->produto(['name' => 'Organizador de Gaveta', 'slug' => 'organizador']);
        $this->lead([], 60 * 24 * 3, $produto);

        $this->artisan('loja:carrinho-abandonado-sequencia');

        Http::assertSent(fn ($request) => ($request['template']['name'] ?? null) === 'carrinho_lembrete_kazakora'
            && $request['template']['components'][1]['parameters'][0]['text'] === 'produtos/organizador?utm_source=whatsapp&utm_medium=carrinho_abandonado&utm_campaign=d1');
    }

    public function test_so_telefone_e_sem_template_espera_sem_gastar_o_disparo(): void
    {
        Mail::fake();
        $this->lead(['email' => null], 60 * 24 * 3);

        $this->artisan('loja:carrinho-abandonado-sequencia');

        $registro = Registro::sole();
        $this->assertSame(0, $registro->disparos_enviados);
        $this->assertFalse($registro->parado);
        $this->assertTrue($registro->proximo_disparo_em->isFuture());
    }

    public function test_um_registro_por_pessoa(): void
    {
        Mail::fake();
        $produtoA = $this->produto();
        $produtoB = $this->produto(['price' => 300]);
        $this->lead([], 60 * 24 * 2, $produtoA);
        // Outro carrinho da mesma pessoa (outro aparelho), mais recente, com o produto B.
        CartSnapshot::create([
            'session_id' => 'sessao-b', 'items_count' => 1, 'total' => 300, 'email' => 'MARIA@exemplo.com',
            'itens' => [$produtoB->id => 1], 'ultima_atividade_em' => now()->subHours(3),
        ]);
        // Mesmo telefone em outro lead, mais antigo.
        $this->lead(['email' => 'maria.outro@exemplo.com'], 60 * 24 * 4, $produtoA);

        $this->artisan('loja:carrinho-abandonado-sequencia')->assertSuccessful();

        $registro = Registro::sole();
        $this->assertSame(Registro::ORIGEM_SNAPSHOT, $registro->origem);
        $this->assertSame($produtoB->id, $registro->produto_id);
        $this->assertSame('5511999990000', $registro->telefone);
        Mail::assertSentCount(1);

        // Rodando de novo não cria outro nem repete o disparo.
        $this->artisan('loja:carrinho-abandonado-sequencia');
        $this->assertSame(1, Registro::count());
        Mail::assertSentCount(1);
    }

    public function test_desligada_importa_e_para_mas_nao_envia_nada(): void
    {
        Mail::fake();
        $this->ligarWhatsApp();
        config(['loja.carrinho_sequencia_ativa' => false]);
        $this->lead([], 60 * 24 * 3);
        $this->lead(['email' => 'comprou@exemplo.com', 'telefone' => '21966665555'], 60 * 24 * 3);
        Order::create([
            'status' => Order::STATUS_PAID, 'shipping_email' => 'comprou@exemplo.com',
            'shipping_name' => 'Ana', 'shipping_phone' => '21966665555', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);

        $this->artisan('loja:carrinho-abandonado-sequencia')
            ->expectsOutputToContain('Envio desligado')
            ->assertSuccessful();

        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->assertSame(2, Registro::count());
        $this->assertSame(0, (int) Registro::sum('disparos_enviados'));
        $this->assertSame(Registro::PAROU_COMPROU, Registro::where('email', 'comprou@exemplo.com')->sole()->motivo_parada);

        // --simular funciona mesmo desligada.
        $this->artisan('loja:carrinho-abandonado-sequencia', ['--simular' => true])
            ->expectsOutputToContain('Mandaria 1 disparo(s).')
            ->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_chave_so_liga_no_kazakora_com(): void
    {
        // Ambiente de teste (APP_URL fora do kazakora.com): padrão desligado.
        $config = fn () => require base_path('config/loja.php');
        $this->assertFalse($config()['carrinho_sequencia_ativa']);
    }

    public function test_simular_nao_manda_nem_grava(): void
    {
        Mail::fake();
        $this->lead([], 60 * 24 * 3);

        $this->artisan('loja:carrinho-abandonado-sequencia', ['--simular' => true])
            ->expectsOutputToContain('Mandaria 1 disparo(s).')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, Registro::count());
    }

    public function test_admin_ve_a_tela_da_sequencia(): void
    {
        $this->withoutVite();
        Mail::fake();
        $this->lead([], 60 * 24 * 3);
        $this->artisan('loja:carrinho-abandonado-sequencia');

        $this->get('/admin/carrinhos-abandonados/sequencia')->assertRedirect();

        // Desde 25/11/2026 a sequência aparece na tela única de Leads, chip Em remarketing.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/admin/carrinhos-abandonados/sequencia')->assertRedirect('/admin/leads?status=remarketing');
        $this->actingAs($admin)->get('/admin/leads?status=remarketing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/LeadsUnicos/Index', false)
                ->where('leads.data.0.email', 'maria@exemplo.com')
                ->where('leads.data.0.disparos', 1)
                ->where('resumo.remarketing', 1));
    }
}
