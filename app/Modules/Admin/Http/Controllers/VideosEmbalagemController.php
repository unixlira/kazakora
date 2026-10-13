<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderPackingVideo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedidos › Vídeos da embalagem (pedido do Lira 2026-10-13): todos os vídeos
 * gravados no KoraSync num lugar só, para achar e assistir sem abrir pedido
 * por pedido. Tocar e baixar usam a mesma rota do pedido (pedidos.video).
 */
class VideosEmbalagemController extends Controller
{
    private const POR_PAGINA = 30;

    private const STATUS = ['processando', 'pronto', 'erro'];

    private const CANAIS = [
        Order::ORIGIN_STORE,
        Order::ORIGIN_MERCADO_LIVRE,
        Order::ORIGIN_SHOPEE,
        Order::ORIGIN_TIKTOK_SHOP,
        Order::ORIGIN_AMAZON,
    ];

    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:100'],
            'canal' => ['nullable', Rule::in(self::CANAIS)],
            'status' => ['nullable', Rule::in(self::STATUS)],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $busca = trim((string) ($filtros['busca'] ?? ''));

        $query = OrderPackingVideo::query()
            ->with('order:id,origin,external_order_id,shipping_name,shipping_recipient_name,channel_buyer_nickname,status')
            ->when($filtros['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filtros['canal'] ?? null, fn (Builder $q, $canal) => $q->whereHas('order', fn (Builder $o) => $o->where('origin', $canal)))
            ->when($filtros['de'] ?? null, fn (Builder $q, $de) => $q->where('created_at', '>=', $de.' 00:00:00'))
            ->when($filtros['ate'] ?? null, fn (Builder $q, $ate) => $q->where('created_at', '<=', $ate.' 23:59:59'))
            ->when($busca !== '', function (Builder $q) use ($busca) {
                $like = '%'.addcslashes($busca, '\\%_').'%';
                $q->whereHas('order', fn (Builder $o) => $o
                    ->where('external_order_id', 'like', $like)
                    ->orWhere('shipping_name', 'like', $like)
                    ->orWhere('shipping_recipient_name', 'like', $like)
                    ->orWhere('channel_buyer_nickname', 'like', $like)
                    ->when(ctype_digit($busca), fn (Builder $o) => $o->orWhere('id', (int) $busca)));
            });

        $totais = (clone $query)->toBase()
            ->selectRaw('COUNT(*) as videos, COALESCE(SUM(tamanho), 0) as bytes, COUNT(DISTINCT order_id) as pedidos')
            ->first();

        $videos = $query->latest('created_at')->latest('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (OrderPackingVideo $v) => $v->paraTela() + [
                'erro' => $v->erro,
                'pedido' => [
                    'id' => $v->order_id,
                    'numero' => $v->order?->external_order_id,
                    'canal' => $v->order?->origin,
                    'cliente' => $v->order?->shipping_recipient_name ?: $v->order?->shipping_name,
                    'apelido' => $v->order?->channel_buyer_nickname,
                ],
            ]);

        return Inertia::render('Admin/VideosEmbalagem/Index', [
            'videos' => $videos,
            'totais' => [
                'videos' => (int) ($totais->videos ?? 0),
                'pedidos' => (int) ($totais->pedidos ?? 0),
                'tamanho_mb' => round(((int) ($totais->bytes ?? 0)) / 1048576, 1),
            ],
            'filtros' => [
                'busca' => $busca,
                'canal' => $filtros['canal'] ?? '',
                'status' => $filtros['status'] ?? '',
                'de' => $filtros['de'] ?? '',
                'ate' => $filtros['ate'] ?? '',
            ],
            'canais' => self::CANAIS,
            'diasGuardado' => OrderPackingVideo::DIAS_GUARDADO,
        ]);
    }
}
