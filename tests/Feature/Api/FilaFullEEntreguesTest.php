<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Duas regras pedidas em 2026-09-14, as duas sobre o que a fila do KoraSync
 * mostra:
 *
 * 1. Pedido do FULL aparece na fila (a venda conta no dia e o pessoal dá
 *    baixa quando vê), mas não é trabalho de separação — não entra na conta
 *    de estoque nem no contador de "falta separar". Quem embala é o Mercado
 *    Livre, o estoque é de lá.
 * 2. "Se já foram entregues, deve sumir da fila de embalado ou entrega
 *    futura": o que o canal já despachou ou entregou sai, e o que foi
 *    embalado sai depois do dia — a aba Separados é o que se embalou hoje,
 *    não um arquivo (tinha 478 pedidos em 14/09, com baixa de até um mês).
 */
class FilaFullEEntreguesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `created_at` vai por UPDATE direto: não está no fillable do Order, e
     * passar na criação não tem efeito nenhum — o Eloquent carimba a hora
     * atual. Um teste de janela de data que não controla a data de verdade
     * passa sem testar nada.
     */
    private function pedido(array $attributes = [], ?string $criadoEm = null): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ], $attributes));

        if ($criadoEm !== null) {
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $criadoEm]);
            $order->refresh();
        }

        return $order;
    }

    private function envio(Order $order, array $attributes = []): ChannelShipment
    {
        return ChannelShipment::create(array_merge([
            'order_id' => $order->id,
            'channel' => 'mercado_livre',
            'status' => ChannelShipment::STATUS_CONFIRMED,
        ], $attributes));
    }

    private function fila(): array
    {
        $resposta = $this->getJson('/api/print-agent/dashboard/queue', ['Authorization' => 'Bearer test-print-agent-token']);
        $resposta->assertOk();
        $json = $resposta->json();

        return [
            'ids' => collect($json['queue'])->pluck('id')->all(),
            'semEstoque' => collect($json['out_of_stock'])->pluck('id')->all(),
            'faltaSeparar' => $json['pending_separation_count'],
        ];
    }

    public function test_pedido_do_full_aparece_na_fila_mas_nao_conta_como_separacao(): void
    {
        $full = $this->pedido();
        $this->envio($full, ['shipping_method' => ChannelShipment::METHOD_FULFILLMENT]);

        $normal = $this->pedido();
        $this->envio($normal, ['shipping_method' => 'xd_drop_off']);

        $fila = $this->fila();

        $this->assertContains($full->id, $fila['ids'], 'Full tem que aparecer — é a venda do dia');
        $this->assertContains($normal->id, $fila['ids']);
        $this->assertNotContains($full->id, $fila['semEstoque'], 'Full não pode pedir reposição: o estoque é do Mercado Livre');
        $this->assertSame(1, $fila['faltaSeparar'], 'só o pedido normal é trabalho de separação');
    }

    public function test_pedido_do_full_continua_visivel_depois_da_baixa_no_mesmo_dia(): void
    {
        $full = $this->pedido(['packed_at' => now()]);
        $this->envio($full, ['shipping_method' => ChannelShipment::METHOD_FULFILLMENT]);

        $this->assertContains($full->id, $this->fila()['ids']);
    }

    /**
     * A baixa do Full tem que SIGNIFICAR alguma coisa (2026-09-21): depois
     * dela o pedido segue a janela do dia como qualquer outro. Antes o Full
     * ficava visível pra sempre — o #2315 deu baixa em 19/09 e continuava na
     * aba Separados no dia 21, empilhando com todo Full novo que chegava.
     */
    public function test_full_com_baixa_velha_sai_da_lista(): void
    {
        $antigo = $this->pedido(['packed_at' => now()->subDays(5)], now()->subDays(5)->toDateTimeString());
        $this->envio($antigo, ['shipping_method' => ChannelShipment::METHOD_FULFILLMENT]);

        $this->assertNotContains($antigo->id, $this->fila()['ids'], 'baixa velha do Full sai como a de qualquer pedido');
    }

    /** Sem baixa, o Full continua esperando alguém conferir — não some por idade. */
    public function test_full_sem_baixa_nunca_some_por_idade(): void
    {
        $esperando = $this->pedido([], now()->subDays(12)->toDateTimeString());
        $this->envio($esperando, ['shipping_method' => ChannelShipment::METHOD_FULFILLMENT]);

        $fila = $this->fila();

        $this->assertContains($esperando->id, $fila['ids'], 'Full sem baixa fica até alguém dar baixa');
        $this->assertSame(0, $fila['faltaSeparar'], 'e continua fora do contador de separação');
    }

    public function test_pedido_que_o_canal_ja_despachou_ou_entregou_sai_da_fila(): void
    {
        $despachado = $this->pedido(['packed_at' => now()]);
        $this->envio($despachado, ['channel_status' => ChannelShipment::CHANNEL_STATUS_SHIPPED]);

        $entregue = $this->pedido(['packed_at' => now()]);
        $this->envio($entregue, ['channel_delivered_at' => now()->subHours(2)]);

        $naMao = $this->pedido(['packed_at' => now()]);
        $this->envio($naMao, ['channel_status' => 'ready_to_ship']);

        $fila = $this->fila();

        $this->assertNotContains($despachado->id, $fila['ids'], 'o ponto de coleta já escaneou');
        $this->assertNotContains($entregue->id, $fila['ids'], 'já chegou no comprador');
        $this->assertContains($naMao->id, $fila['ids'], 'esse ainda está aqui');
    }

    public function test_embalado_fica_pelo_dia_e_depois_sai_da_lista(): void
    {
        $hoje = $this->pedido(['packed_at' => now()]);
        $ontem = $this->pedido(['packed_at' => now()->subDay()]);
        $semanaPassada = $this->pedido(['packed_at' => now()->subDays(7)], now()->subDays(7)->toDateTimeString());

        $fila = $this->fila();

        $this->assertContains($hoje->id, $fila['ids']);
        $this->assertContains($ontem->id, $fila['ids']);
        $this->assertNotContains($semanaPassada->id, $fila['ids'], 'baixa velha sai da lista — quem procura usa a busca');
    }

    public function test_baixa_de_hoje_em_venda_antiga_conta_no_dia(): void
    {
        // A janela é sobre a BAIXA, não sobre a venda: separar hoje um
        // pedido represado é trabalho de hoje e tem que aparecer.
        $antigo = $this->pedido(['packed_at' => now()], now()->subDays(6)->toDateTimeString());

        $this->assertContains($antigo->id, $this->fila()['ids']);
    }

    public function test_pedido_pago_e_ainda_nao_embalado_nunca_some_por_idade(): void
    {
        $represado = $this->pedido([], now()->subDays(12)->toDateTimeString());

        $fila = $this->fila();

        $this->assertContains($represado->id, $fila['ids'], 'trabalho pendente não some com o tempo');
        $this->assertSame(1, $fila['faltaSeparar']);
    }
}
