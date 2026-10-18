<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Mail\PedidoAvaliacao;
use App\Modules\Catalog\Models\AvaliacaoSolicitacao;
use App\Modules\Catalog\Support\SolicitacaoAvaliacao;
use App\Modules\Checkout\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Pedido de avaliação depois da entrega (pedido do Lira 2026-10-18). De hora
 * em hora, das 9h às 20h: pedido do kazakora.com entregue há N dias (rastreio
 * dos Correios ou troca manual para "concluído") recebe o e-mail "Avalie sua
 * compra" com um cupom só dele. Um e-mail por pedido (trava na tabela
 * avaliacao_solicitacoes); falha de envio volta na rodada seguinte, até 3
 * vezes. Ligado, desligado e editado na tela de Avaliações do admin.
 * Envio direto, sem fila: o homolog não tem worker.
 */
class SolicitarAvaliacoes extends Command
{
    protected $signature = 'avaliacoes:solicitar {--simular : só lista, não grava nem envia} {--pedido= : só este pedido, sem esperar os dias nem o horário}';

    protected $description = 'Manda o e-mail "Avalie sua compra" com cupom para os pedidos do site entregues';

    public function handle(): int
    {
        $config = SolicitacaoAvaliacao::config();
        $pedido = $this->option('pedido');
        $simular = (bool) $this->option('simular');

        if (! $config['ativo'] && ! $pedido) {
            $this->info('Rotina desligada na tela de Avaliações.');

            return self::SUCCESS;
        }
        if (! $pedido && ! SolicitacaoAvaliacao::emHorarioDeEnvio()) {
            $this->info('Fora do horário de envio (9h às 20h).');

            return self::SUCCESS;
        }

        $contagem = ['enviados' => 0, 'pulados' => 0, 'falhas' => 0, 'reenvios' => 0];

        $consulta = $pedido
            ? Order::query()->whereKey((int) $pedido)->where('origin', Order::ORIGIN_STORE)->whereDoesntHave('avaliacaoSolicitacao')
            : SolicitacaoAvaliacao::pedidosNaHora($config);

        $consulta->with('user')->chunkById(50, function ($lote) use ($config, $simular, &$contagem) {
            foreach ($lote as $order) {
                $motivo = SolicitacaoAvaliacao::motivoParaPular($order);
                $this->line("#{$order->id} ".($motivo ? "pula: {$motivo}" : 'envia para '.SolicitacaoAvaliacao::emailDo($order)));
                if ($simular) {
                    continue;
                }

                $chave = match (self::enviar($order, $config, $motivo)) {
                    AvaliacaoSolicitacao::STATUS_ENVIADO => 'enviados',
                    AvaliacaoSolicitacao::STATUS_PULADO => 'pulados',
                    AvaliacaoSolicitacao::STATUS_FALHOU => 'falhas',
                    default => null,
                };
                if ($chave) {
                    $contagem[$chave]++;
                }
            }
        });

        // Falha de envio da rodada anterior (SMTP fora do ar etc.).
        if (! $simular && ! $pedido) {
            AvaliacaoSolicitacao::query()
                ->with('order.user', 'coupon')
                ->where('status', AvaliacaoSolicitacao::STATUS_FALHOU)
                ->where('tentativas', '<', AvaliacaoSolicitacao::MAX_TENTATIVAS)
                ->where('updated_at', '<=', now()->subMinutes(50))
                ->get()
                ->each(function (AvaliacaoSolicitacao $solicitacao) use ($config, &$contagem) {
                    if ($solicitacao->order && self::enviar($solicitacao->order, $config, null, $solicitacao) === AvaliacaoSolicitacao::STATUS_ENVIADO) {
                        $contagem['reenvios']++;
                    }
                });
        }

        $this->info(json_encode($contagem));

        return self::SUCCESS;
    }

    /**
     * Grava a trava, cria o cupom e manda o e-mail. Devolve o status final,
     * ou null quando outra rodada já pegou o pedido.
     */
    public static function enviar(Order $order, array $config, ?string $motivoParaPular, ?AvaliacaoSolicitacao $solicitacao = null): ?string
    {
        if (! $solicitacao) {
            try {
                $solicitacao = AvaliacaoSolicitacao::query()->create([
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'email' => SolicitacaoAvaliacao::emailDo($order),
                    'status' => $motivoParaPular ? AvaliacaoSolicitacao::STATUS_PULADO : AvaliacaoSolicitacao::STATUS_ENVIANDO,
                    'erro' => $motivoParaPular,
                ]);
            } catch (QueryException) {
                return null;
            }
        }

        if ($motivoParaPular) {
            return AvaliacaoSolicitacao::STATUS_PULADO;
        }

        $produtos = SolicitacaoAvaliacao::produtosParaAvaliar($order);
        if ($produtos === []) {
            $solicitacao->update(['status' => AvaliacaoSolicitacao::STATUS_PULADO, 'erro' => 'cliente já avaliou todos os produtos']);

            return AvaliacaoSolicitacao::STATUS_PULADO;
        }

        try {
            $cupom = $solicitacao->coupon ?? SolicitacaoAvaliacao::criarCupom($order, $config);
            if ($cupom && ! $solicitacao->coupon_id) {
                $solicitacao->update(['coupon_id' => $cupom->id]);
            }

            Mail::to($solicitacao->email)->send(new PedidoAvaliacao($order, $produtos, $cupom, $config));
        } catch (Throwable $erro) {
            $solicitacao->update([
                'status' => AvaliacaoSolicitacao::STATUS_FALHOU,
                'tentativas' => $solicitacao->tentativas + 1,
                'erro' => mb_substr($erro->getMessage(), 0, 1000),
            ]);
            Log::warning('avaliacao_pos_compra_falhou', ['order_id' => $order->id, 'erro' => $erro->getMessage()]);

            return AvaliacaoSolicitacao::STATUS_FALHOU;
        }

        $solicitacao->update([
            'status' => AvaliacaoSolicitacao::STATUS_ENVIADO,
            'tentativas' => $solicitacao->tentativas + 1,
            'erro' => null,
            'enviado_em' => now(),
        ]);

        return AvaliacaoSolicitacao::STATUS_ENVIADO;
    }
}
