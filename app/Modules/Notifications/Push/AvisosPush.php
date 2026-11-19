<?php

namespace App\Modules\Notifications\Push;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Notifications\Push\Models\PushInscricao;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Monta e manda os avisos dos apps de vendas. Nunca lança: aviso que falha
 * fica no log e na contagem de falhas da inscrição, e inscrição que o
 * serviço de push diz que morreu (404/410: app apagado, permissão tirada)
 * é apagada.
 */
class AvisosPush
{
    public function __construct(private readonly WebPush $webPush)
    {
    }

    /**
     * Texto da venda, sem nenhum dado do cliente (nome, endereço, telefone):
     * o aviso aparece na tela bloqueada.
     *
     * @return array<string, mixed>
     */
    public function mensagemDaVenda(Order $order, string $app): array
    {
        $order->loadMissing('items.product.images');
        $itens = $order->items->sortByDesc(fn ($item) => (float) $item->subtotal)->values();
        $principal = $itens->first();

        $nome = $principal ? Str::limit(trim((string) $principal->product_name), 70) : 'pedido';
        $quantidade = (int) ($principal->quantity ?? 1);
        $titulo = 'Vendeu: '.($quantidade > 1 ? "{$quantidade}x " : '').$nome;
        if ($itens->count() > 1) {
            $titulo .= ' e mais '.($itens->count() - 1);
        }

        $imagem = null;
        $fotos = $principal?->product?->images;
        if ($fotos && $fotos->isNotEmpty()) {
            $foto = $fotos->sortByDesc('is_primary')->first();
            $imagem = rescue(fn () => $foto->thumb_url, null, false);
        }

        return [
            'title' => $titulo,
            'body' => 'Pedido #'.$order->id.' · R$ '.number_format((float) $order->total, 2, ',', '.'),
            'icon' => AppsDeVendas::url($app, 'icons/icon-192.png'),
            'badge' => AppsDeVendas::url($app, 'icons/badge-96.png'),
            'image' => $imagem,
            'tag' => 'pedido-'.$order->id,
            // Endereço dentro do app (site próprio desde 2026-11-19): abre o detalhe do pedido nele.
            'url' => '/#/pedidos/'.$order->id,
        ];
    }

    /** @return array<string, mixed> */
    public function mensagemDeTeste(string $app): array
    {
        $nome = AppsDeVendas::NOMES[$app];

        return [
            'title' => "Teste: avisos de venda da {$nome} ligados",
            'body' => "Cada venda da {$nome} vai chegar assim: produto, número do pedido e valor.",
            'icon' => AppsDeVendas::url($app, 'icons/icon-192.png'),
            'badge' => AppsDeVendas::url($app, 'icons/badge-96.png'),
            'image' => null,
            'tag' => 'teste-'.$app,
            'url' => '/#/pedidos',
        ];
    }

    /**
     * Manda para os aparelhos inscritos no app (só de usuários admin); com
     * $usuario, só para os dele.
     *
     * @param  array<string, mixed>  $mensagem
     * @return array{enviados: int, falhas: int, removidas: int}
     */
    public function enviar(string $app, array $mensagem, ?User $usuario = null): array
    {
        $resultado = ['enviados' => 0, 'falhas' => 0, 'removidas' => 0];

        if (! $this->webPush->configurado()) {
            Log::warning('push.sem_chaves_vapid', ['app' => $app]);

            return $resultado;
        }

        $inscricoes = PushInscricao::query()
            ->where('app', $app)
            ->when($usuario, fn ($q) => $q->where('user_id', $usuario->id))
            ->whereHas('user', fn ($q) => $q->where('role', User::ROLE_ADMIN))
            ->get();

        $json = json_encode(array_filter($mensagem, fn ($valor) => $valor !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($inscricoes as $inscricao) {
            try {
                $status = $this->webPush->enviar($inscricao->paraEnvio(), $json, topico: $mensagem['tag'] ?? null);
            } catch (Throwable $e) {
                $status = 0;
                Log::warning('push.erro', ['app' => $app, 'inscricao' => $inscricao->id, 'erro' => $e->getMessage()]);
            }

            if ($status >= 200 && $status < 300) {
                $resultado['enviados']++;
                $inscricao->forceFill(['ultimo_envio_em' => now(), 'falhas' => 0])->save();
            } elseif (in_array($status, [404, 410], true)) {
                $resultado['removidas']++;
                $inscricao->delete();
            } else {
                $resultado['falhas']++;
                $inscricao->increment('falhas');
                Log::warning('push.recusado', ['app' => $app, 'inscricao' => $inscricao->id, 'status' => $status]);
            }
        }

        return $resultado;
    }
}
