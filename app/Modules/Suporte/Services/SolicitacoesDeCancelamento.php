<?php

namespace App\Modules\Suporte\Services;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Suporte\Models\Chamado;
use App\Modules\Suporte\Models\MensagemSuporte;
use App\Modules\Suporte\Models\SolicitacaoCancelamento;
use App\Notifications\CancelamentoSolicitadoAposPostagemNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Cancelamento de compra já postada (Lira, 2026-11-17): o botão "Cancelar
 * pedido" registra a solicitação como mensagem do cliente num chamado do
 * suporte (o mesmo chat da página da compra), avisa os admins no sino e
 * aparece em Atendimento > Cancelamentos. Nada é cancelado aqui: nota,
 * estorno e estoque ficam com a equipe, que decide com o pacote na rua.
 */
class SolicitacoesDeCancelamento
{
    public const MENSAGEM_AO_CLIENTE = 'Seu pedido já foi postado. Recebemos sua solicitação de cancelamento e nossa equipe vai te responder por aqui.';

    public const MENSAGEM_REPETIDA = 'Já recebemos sua solicitação de cancelamento deste pedido. Nossa equipe vai te responder por aqui.';

    /**
     * @param  array{fonte: string, postado_em: ?\Illuminate\Support\Carbon, rastreio: ?string}  $postagem  PostagemDoPedido::encontrar()
     * @return array{nova: bool, solicitacao: SolicitacaoCancelamento, mensagem: string}
     */
    public function registrar(Order $order, User $cliente, string $motivo, array $postagem): array
    {
        $motivo = mb_substr(trim($motivo), 0, 500);

        try {
            $resultado = DB::transaction(fn () => $this->registrarComTrava($order, $cliente, $motivo, $postagem));
        } catch (QueryException $erro) {
            // Dois cliques ao mesmo tempo: o índice único de order_id barra o
            // segundo, que vira "já recebemos".
            $existente = SolicitacaoCancelamento::query()->where('order_id', $order->id)->first();

            if (! $existente) {
                throw $erro;
            }

            $resultado = ['nova' => false, 'solicitacao' => $existente];
        }

        if ($resultado['nova']) {
            $this->avisarAdmins($resultado['solicitacao']);
        }

        return $resultado + ['mensagem' => $resultado['nova'] ? self::MENSAGEM_AO_CLIENTE : self::MENSAGEM_REPETIDA];
    }

    public function pendenteDoPedido(Order $order): ?SolicitacaoCancelamento
    {
        return SolicitacaoCancelamento::query()
            ->where('order_id', $order->id)
            ->where('status', SolicitacaoCancelamento::PENDENTE)
            ->first();
    }

    /** Marca como resolvida e deixa registrado no chamado. */
    public function resolver(SolicitacaoCancelamento $solicitacao, User $admin): void
    {
        if (! $solicitacao->pendente()) {
            return;
        }

        $solicitacao->forceFill([
            'status' => SolicitacaoCancelamento::RESOLVIDO,
            'resolvido_em' => now(),
            'resolvido_por' => $admin->id,
        ])->save();

        $solicitacao->chamado?->registrar(MensagemSuporte::SISTEMA, 'Solicitação de cancelamento marcada como resolvida pela equipe.');
    }

    /** @return array{nova: bool, solicitacao: SolicitacaoCancelamento} */
    private function registrarComTrava(Order $order, User $cliente, string $motivo, array $postagem): array
    {
        // Mesma trava do cancelamento: dois cliques entram em fila.
        Order::query()->whereKey($order->id)->lockForUpdate()->first();

        $existente = SolicitacaoCancelamento::query()->where('order_id', $order->id)->lockForUpdate()->first();

        if ($existente?->pendente()) {
            return ['nova' => false, 'solicitacao' => $existente];
        }

        $chamado = $this->chamadoDoPedido($order, $cliente, $motivo);
        $chamado->registrar(MensagemSuporte::CLIENTE, "Quero cancelar meu pedido {$order->numero}. Motivo: {$motivo}", $cliente->id);
        $chamado->registrar(MensagemSuporte::SISTEMA, self::MENSAGEM_AO_CLIENTE);

        // Resolvida antes e o cliente pediu de novo: reabre a mesma linha.
        $solicitacao = $existente ?? new SolicitacaoCancelamento(['order_id' => $order->id]);
        $solicitacao->forceFill([
            'user_id' => $cliente->id,
            'chamado_id' => $chamado->id,
            'motivo' => $motivo,
            'postagem_fonte' => $postagem['fonte'] ?? null,
            'postado_em' => $postagem['postado_em'] ?? null,
            'rastreio' => isset($postagem['rastreio']) ? mb_substr((string) $postagem['rastreio'], 0, 60) : null,
            'status' => SolicitacaoCancelamento::PENDENTE,
            'solicitado_em' => now(),
            'resolvido_em' => null,
            'resolvido_por' => null,
        ])->save();

        return ['nova' => true, 'solicitacao' => $solicitacao];
    }

    /**
     * A conversa ativa da compra (a do chat "Falar com suporte"), ou uma
     * nova. Vira chamado aberto com atendimento humano: quem responde é a
     * equipe, não a Manuela.
     */
    private function chamadoDoPedido(Order $order, User $cliente, string $motivo): Chamado
    {
        $chamado = Chamado::query()
            ->where('order_id', $order->id)
            ->whereIn('status', Chamado::ATIVOS)
            ->latest('id')
            ->first()
            ?? Chamado::create([
                'numero' => Chamado::gerarNumero(),
                'user_id' => $cliente->id,
                'order_id' => $order->id,
                'aberto_por' => 'cliente',
            ]);

        $chamado->forceFill([
            'status' => $chamado->status === Chamado::CONVERSA ? Chamado::ABERTO : $chamado->status,
            'assunto' => mb_substr("Cancelamento do pedido {$order->numero} (já postado)", 0, 160),
            'prioridade' => 'alta',
            'humano_ativo' => true,
            'resumo' => "Cliente pediu o cancelamento depois da postagem. Motivo: {$motivo}",
            'aberto_em' => $chamado->aberto_em ?? now(),
        ])->save();

        return $chamado;
    }

    private function avisarAdmins(SolicitacaoCancelamento $solicitacao): void
    {
        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            $solicitacao->loadMissing('order');
            rescue(fn () => Notification::send($admins, new CancelamentoSolicitadoAposPostagemNotification($solicitacao)), report: false);
        }
    }
}
