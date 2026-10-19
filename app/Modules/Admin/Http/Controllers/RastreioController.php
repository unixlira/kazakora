<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\Rastreio;
use App\Modules\Marketplace\Models\RastreioEvento;
use App\Modules\Marketplace\Support\RegistroDeRastreio;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Logística › Rastreio (pedido do Lira 2026-10-19): objetos dos Correios
 * acompanhados pelo SRO Rastro, com a situação e a linha do tempo de cada
 * um. Só leitura: quem consulta e grava é loja:acompanhar-rastreio.
 */
class RastreioController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = [
            'situacao' => $request->string('situacao')->toString(),
            'busca' => trim($request->string('busca')->toString()),
        ];

        $rastreios = Rastreio::query()
            ->with(['order:id,external_order_id,origin,status,shipping_name,shipping_city,shipping_state', 'eventos'])
            ->when($filtros['situacao'] === 'abertos', fn ($q) => $q->whereNull('encerrado_em'))
            ->when($filtros['situacao'] !== '' && $filtros['situacao'] !== 'abertos', fn ($q) => $q->where('situacao', $filtros['situacao']))
            ->when($filtros['busca'] !== '', function ($q) use ($filtros) {
                $busca = $filtros['busca'];
                $q->where(fn ($w) => $w->where('codigo', 'like', '%'.strtoupper($busca).'%')
                    ->orWhere('order_id', ctype_digit(ltrim($busca, '#')) ? (int) ltrim($busca, '#') : 0));
            })
            ->orderByRaw('encerrado_em is not null')
            ->orderByDesc('ultimo_evento_em')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Rastreio $r) => [
                'id' => $r->id,
                'codigo' => $r->codigo,
                'situacao' => $r->situacao,
                'situacao_rotulo' => RegistroDeRastreio::SITUACOES[$r->situacao] ?? $r->situacao,
                'encerrado' => $r->encerrado_em !== null,
                'ultimo_evento' => $r->ultimo_evento,
                'ultimo_evento_em' => $r->ultimo_evento_em?->toIso8601String(),
                'previsao_entrega' => $r->previsao_entrega?->toDateString(),
                'postado_em' => $r->postado_em?->toIso8601String(),
                'entregue_em' => $r->entregue_em?->toIso8601String(),
                'consultado_em' => $r->consultado_em?->toIso8601String(),
                'ultimo_erro' => $r->ultimo_erro,
                'pedido' => $r->order ? [
                    'id' => $r->order->id,
                    'numero' => $r->order->numero,
                    'canal' => $r->order->origin,
                    'status' => $r->order->status,
                    'cliente' => $r->order->shipping_name,
                    'destino' => trim(implode('/', array_filter([$r->order->shipping_city, $r->order->shipping_state]))),
                ] : null,
                'eventos' => $r->eventos->map(fn (RastreioEvento $e) => [
                    'id' => $e->id,
                    'codigo' => $e->codigo.($e->tipo !== '' ? '/'.$e->tipo : ''),
                    'quando' => $e->ocorrido_em->toIso8601String(),
                    'descricao' => $e->descricao,
                    'detalhe' => $e->detalhe,
                    'local' => trim(implode(' · ', array_filter([$e->unidade, trim(implode('/', array_filter([$e->cidade, $e->uf])))]))),
                    'destino' => trim(implode('/', array_filter([$e->destino_cidade, $e->destino_uf]))),
                ])->values(),
            ]);

        $contagem = Rastreio::query()->selectRaw('situacao, count(*) as total')->groupBy('situacao')->pluck('total', 'situacao');

        return Inertia::render('Admin/Logistica/Rastreio', [
            'rastreios' => $rastreios,
            'filtros' => $filtros,
            'situacoes' => RegistroDeRastreio::SITUACOES,
            'contagem' => $contagem,
            'abertos' => Rastreio::query()->whereNull('encerrado_em')->count(),
            'whatsappLigado' => (bool) config('services.correios.rastreio_whatsapp'),
        ]);
    }
}
