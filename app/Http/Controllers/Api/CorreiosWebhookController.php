<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Support\AtualizacaoPeloRastreio;
use App\Modules\Marketplace\Support\RegistroDeRastreio;
use App\Services\Correios\CorreiosRastroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook de rastreio dos Correios (2026-10-19, pedido do Lira): quando os
 * Correios avisam a entrega, o pedido vira "Entregue" na hora, sem esperar a
 * consulta de 5 em 5 minutos (loja:acompanhar-rastreio), e a entrega (data,
 * unidade, cidade e quem recebeu) vai para a linha do tempo do pedido.
 *
 * O SRO Rastro do contrato (swagger 3.8.2) ainda não tem cadastro de
 * webhook; a rota aceita o formato do próprio SRO para quem for chamar
 * (Correios, intermediário de rastreio ou script):
 * - `{ "objetos": [ { "codObjeto": "...", "eventos": [...] } ] }` (igual ao GET /v1/objetos);
 * - um objeto só `{ "codObjeto": "...", "eventos": [...] }`;
 * - um evento só `{ "codObjeto": "...", "evento": {...} }` ou com os campos do evento soltos;
 * - só o código (`{ "codObjeto": "..." }`): a loja consulta o SRO na hora.
 *
 * Autenticação: `Authorization: Bearer <CORREIOS_WEBHOOK_TOKEN>` (ou
 * cabeçalho `X-Webhook-Token`, ou `?token=`). Sem token configurado, recusa
 * tudo. Objeto desconhecido responde 200 com "ignorado" para o remetente não
 * ficar reenviando.
 */
class CorreiosWebhookController extends Controller
{
    private const MAXIMO_OBJETOS = 200;

    public function __construct(
        private readonly RegistroDeRastreio $registro,
        private readonly AtualizacaoPeloRastreio $atualizacao,
        private readonly CorreiosRastroService $rastro,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $esperado = (string) config('services.correios.webhook_token');
        $recebido = (string) ($request->bearerToken() ?: $request->header('X-Webhook-Token') ?: $request->query('token'));

        if ($esperado === '' || ! hash_equals($esperado, $recebido)) {
            Log::channel('correios')->warning('correios.webhook_recusado', ['ip' => $request->ip()]);

            return response()->json(['ok' => false, 'erro' => 'Token inválido.'], 401);
        }

        $objetos = array_slice(self::objetos($request->json()->all() ?: $request->all()), 0, self::MAXIMO_OBJETOS);
        if ($objetos === []) {
            return response()->json(['ok' => false, 'erro' => 'Nenhum objeto com código válido no corpo.'], 422);
        }

        // Só o código, sem eventos: busca o objeto completo no SRO.
        $semEventos = array_keys(array_filter($objetos, fn (array $o) => ($o['eventos'] ?? []) === []));
        if ($semEventos !== []) {
            try {
                $objetos = array_replace($objetos, array_intersect_key($this->rastro->objetos($semEventos), $objetos));
            } catch (Throwable $e) {
                Log::channel('correios')->warning('correios.webhook_consulta_falhou', ['objetos' => $semEventos, 'error' => $e->getMessage()]);
            }
        }

        $whatsapp = (bool) config('services.correios.rastreio_whatsapp');
        $resultado = [];
        $contagem = [];

        foreach ($objetos as $codigo => $objeto) {
            $order = self::pedidoDoObjeto($codigo);
            if ($order === null) {
                $resultado[$codigo] = 'ignorado: objeto sem pedido';

                continue;
            }

            try {
                $r = CorreiosRastroService::interpretar($objeto);
                $foraDoDestino = $r['entregue'] && AtualizacaoPeloRastreio::foraDoDestino($r['entregue_cidade'], $order->shipping_city);
                $this->registro->registrar($codigo, $order->id, $objeto, $foraDoDestino);

                if ($order->origin === Order::ORIGIN_STORE && in_array($order->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED], true)) {
                    $this->atualizacao->aplicarNaLoja($order, $codigo, $objeto, $whatsapp, $contagem, 'webhook');
                } else {
                    // Outros canais, pedido já concluído ou cancelado: só o histórico.
                    $this->atualizacao->registrarEntrega($order, $codigo, $objeto, 'webhook');
                }

                $resultado[$codigo] = $order->fresh()->status;
            } catch (Throwable $e) {
                report($e);
                $resultado[$codigo] = 'erro';
            }
        }

        Log::channel('correios')->info('correios.webhook_recebido', ['resultado' => $resultado, 'contagem' => $contagem]);

        return response()->json(['ok' => true, 'objetos' => $resultado]);
    }

    /**
     * Normaliza o corpo para [codigo => objeto no formato do SRO].
     *
     * @return array<string, array>
     */
    public static function objetos(array $corpo): array
    {
        $lista = match (true) {
            is_array($corpo['objetos'] ?? null) => $corpo['objetos'],
            Arr::isList($corpo) => $corpo,
            default => [$corpo],
        };

        $saida = [];
        foreach ($lista as $item) {
            if (! is_array($item)) {
                continue;
            }

            $codigo = strtoupper(trim((string) ($item['codObjeto'] ?? $item['codigoObjeto'] ?? $item['objeto'] ?? '')));
            if (! CorreiosRastroService::codigoValido($codigo)) {
                continue;
            }

            $eventos = match (true) {
                is_array($item['eventos'] ?? null) => $item['eventos'],
                is_array($item['evento'] ?? null) => [$item['evento']],
                filled($item['codigo'] ?? null) && filled($item['dtHrCriado'] ?? null) => [Arr::except($item, ['codObjeto', 'codigoObjeto', 'objeto'])],
                default => [],
            };
            $eventos = array_values(array_filter($eventos, 'is_array'));

            // Mais de um aviso do mesmo objeto no corpo: junta os eventos.
            $anterior = $saida[$codigo]['eventos'] ?? [];
            $todos = collect(array_merge($anterior, $eventos))
                ->unique(fn ($e) => ($e['codigo'] ?? '').'|'.($e['tipo'] ?? '').'|'.($e['dtHrCriado'] ?? ''))
                ->sortByDesc(fn ($e) => (string) ($e['dtHrCriado'] ?? ''))
                ->values()
                ->all();

            $saida[$codigo] = array_merge(Arr::only($item, ['dtPrevista', 'tipoPostal', 'mensagem']), ['codObjeto' => $codigo, 'eventos' => $todos]);
        }

        return $saida;
    }

    /** Pedido dono do código: pré-postagem da loja ou envio do canal. */
    public static function pedidoDoObjeto(string $codigo): ?Order
    {
        $orderId = CorreiosPrePostagem::query()
            ->where('codigo_objeto', $codigo)
            ->whereNotNull('order_id')
            ->where('status', '!=', CorreiosPrePostagem::STATUS_CANCELADA)
            ->latest('id')
            ->value('order_id')
            ?? ChannelShipment::query()->where('tracking_code', $codigo)->whereNotNull('order_id')->latest('id')->value('order_id');

        return $orderId ? Order::query()->find($orderId) : null;
    }
}
