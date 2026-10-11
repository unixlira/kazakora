<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\FlexPickupReceipt;
use App\Modules\Marketplace\Support\FlexControlService;
use App\Services\MercadoLivre\Services\ShipmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Envios Flex — o controle do que saiu com o entregador do Flex (pedido
 * explícito 2026-09-11).
 *
 * Duas abas: "Alertas" (tudo que pede ação, de qualquer mês) e "Todos"
 * (o mês escolhido). Cada linha já traz o detalhe inteiro — linha do tempo
 * aqui x Mercado Livre, comprovante de retirada, reclamações — então abrir
 * o detalhe não faz requisição.
 *
 * FOTO E ASSINATURA SÃO PROVA, NÃO VITRINE: servidas só por rota
 * autenticada, com `no-store`, e TODA visualização vai pra auditoria (quem,
 * quando, qual recibo). O uso combinado com o entregador no consentimento é
 * comprovar a entrega, inclusive em processo — nunca expor.
 */
class EnviosFlexController extends Controller
{
    /** Janela da aba Alertas: pendência mais velha que isso já foi tratada ou virou arquivo morto. */
    private const DIAS_DE_ALERTA = 120;

    public function __construct(private readonly FlexControlService $controle) {}

    public function index(Request $request): Response
    {
        $mes = $request->filled('mes') && preg_match('/^\d{4}-\d{2}$/', (string) $request->string('mes'))
            ? Carbon::createFromFormat('Y-m-d', $request->string('mes').'-01')->startOfMonth()
            : Carbon::today()->startOfMonth();

        $busca = trim((string) $request->string('busca')) ?: null;

        // --- Alertas: qualquer mês, calculados agora --------------------
        $candidatos = $this->controle->query()
            ->where('channel_shipments.created_at', '>=', now()->subDays(self::DIAS_DE_ALERTA))
            ->get();

        $impressasAlerta = $this->controle->etiquetasImpressas($candidatos->pluck('order_id'));

        $comAlerta = $candidatos
            ->filter(fn (ChannelShipment $envio) => $this->controle->alertasAbertos($envio, $impressasAlerta[$envio->order_id] ?? null) !== [])
            ->values();

        // --- O mês escolhido ---------------------------------------------
        $doMes = $this->controle->query()
            ->whereHas('order', fn ($q) => $q->whereBetween('created_at', [$mes, $mes->copy()->endOfMonth()]))
            ->get();

        $aba = in_array($request->string('aba')->toString(), ['alertas', 'todos'], true)
            ? $request->string('aba')->toString()
            : ($comAlerta->isNotEmpty() ? 'alertas' : 'todos');

        $lista = $aba === 'alertas' ? $comAlerta : $doMes;

        if ($busca) {
            // Busca vale nas duas abas e ignora o mês: quem digita um número
            // de pedido quer achar o pedido, não saber em que mês ele caiu.
            $lista = $this->controle->query()->get()->filter(fn (ChannelShipment $envio) => $this->casaBusca($envio, $busca))->values();
        }

        $impressas = $this->controle->etiquetasImpressas($lista->pluck('order_id'));
        $usuarios = $this->nomesDeUsuarios($lista);

        $linhas = $lista
            ->map(fn (ChannelShipment $envio) => $this->controle->linha($envio, $impressas, $usuarios))
            ->sort(function (array $a, array $b) {
                // Pendência primeiro; dentro dela e fora dela, venda mais recente no topo.
                return [$b['alertasAbertos'] > 0, $b['linhaDoTempo']['vendidaEm']] <=> [$a['alertasAbertos'] > 0, $a['linhaDoTempo']['vendidaEm']];
            })
            ->values()
            ->all();

        $situacoes = $doMes->map(fn (ChannelShipment $envio) => $this->controle->situacao($envio)['codigo']);

        return Inertia::render('Admin/EnviosFlex/Index', [
            'linhas' => $linhas,
            'resumo' => [
                'vendas' => $doMes->count(),
                'sairam' => $doMes->filter(fn (ChannelShipment $e) => $e->order?->collected_at || $e->channel_shipped_at || $e->channel_delivered_at)->count(),
                'emRota' => $situacoes->filter(fn ($s) => in_array($s, ['em_rota', 'com_entregador'], true))->count(),
                'entregues' => $situacoes->filter(fn ($s) => $s === 'entregue')->count(),
                'canceladas' => $situacoes->filter(fn ($s) => $s === 'cancelada')->count(),
                'comComprovante' => $doMes->filter(fn (ChannelShipment $e) => $e->order?->pickupReceipt?->photo_path)->count(),
                'alertas' => $comAlerta->count(),
            ],
            'filtros' => [
                'mes' => $mes->format('Y-m'),
                'aba' => $aba,
                'busca' => $busca,
            ],
            'resolucoes' => collect(FlexControlService::RESOLUCOES)->map(fn ($rotulo, $tipo) => ['tipo' => $tipo, 'rotulo' => $rotulo])->values(),
            'retencaoDias' => (int) config('services.koraflex.receipt_retention_days', 180),
            'horasAlertaRota' => (int) config('services.koraflex.route_alert_hours', 2),
        ]);
    }

    /** "Produto está na loja", "Encerrado sem o produto voltar" ou "Conferido". */
    public function resolver(Request $request, ChannelShipment $envio): RedirectResponse
    {
        $this->garantirFlex($envio);

        $dados = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(FlexControlService::RESOLUCOES))],
            'nota' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->controle->resolver($envio, $dados['tipo'], $dados['nota'] ?? null, $request->user());

        $this->auditar($request, 'flex_resolver', $envio, ['tipo' => $dados['tipo'], 'nota' => $dados['nota'] ?? null]);

        return back()->with('success', 'Pedido #'.$envio->order_id.': '.FlexControlService::RESOLUCOES[$dados['tipo']].'.');
    }

    public function desfazerResolucao(Request $request, ChannelShipment $envio): RedirectResponse
    {
        $this->garantirFlex($envio);

        $antes = ['tipo' => $envio->return_resolution, 'nota' => $envio->return_note];
        $this->controle->desfazerResolucao($envio);
        $this->auditar($request, 'flex_desfazer_resolucao', $envio, $antes);

        return back()->with('success', 'Resolução desfeita — os alertas do pedido #'.$envio->order_id.' voltaram.');
    }

    /** Consulta o envio no Mercado Livre agora (só leitura), sem esperar a rotina. */
    public function sincronizar(ChannelShipment $envio, ShipmentService $shipments): RedirectResponse
    {
        $this->garantirFlex($envio);

        try {
            $shipments->syncOrderStatusFromShipment($envio->loadMissing('order'));
        } catch (Throwable $exception) {
            return back()->with('error', 'O Mercado Livre não respondeu: '.$exception->getMessage());
        }

        return back()->with('success', 'Status do envio atualizado com o Mercado Livre.');
    }

    /** Retém o recibo como prova: a rotina de retenção não apaga as imagens dele. */
    public function reter(Request $request, FlexPickupReceipt $recibo): RedirectResponse
    {
        $dados = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

        $recibo->forceFill([
            'legal_hold_at' => now(),
            'legal_hold_reason' => trim($dados['motivo']),
            'legal_hold_by' => $request->user()->id,
        ])->save();

        $this->auditar($request, 'flex_reter_recibo', $recibo, ['motivo' => $dados['motivo']]);

        return back()->with('success', "Comprovante #{$recibo->id} retido como prova — as imagens não serão apagadas.");
    }

    public function liberar(Request $request, FlexPickupReceipt $recibo): RedirectResponse
    {
        $antes = ['motivo' => $recibo->legal_hold_reason, 'em' => $recibo->legal_hold_at?->toDateTimeString()];

        $recibo->forceFill(['legal_hold_at' => null, 'legal_hold_reason' => null, 'legal_hold_by' => null])->save();

        $this->auditar($request, 'flex_liberar_recibo', $recibo, $antes);

        return back()->with('success', "Comprovante #{$recibo->id} liberado — volta a seguir o prazo de retenção.");
    }

    /** A imagem do recibo (assinatura ou foto). Toda abertura fica na auditoria. */
    public function imagem(Request $request, FlexPickupReceipt $recibo, string $tipo): HttpResponse
    {
        $caminho = $tipo === 'assinatura' ? $recibo->signature_path : ($tipo === 'foto' ? $recibo->photo_path : null);

        abort_unless($caminho && Storage::disk('local')->exists($caminho), 404, 'Esse comprovante não tem essa imagem.');

        $this->auditar($request, 'flex_ver_imagem', $recibo, ['tipo' => $tipo]);

        return response(Storage::disk('local')->get($caminho), 200, [
            'Content-Type' => str_ends_with($caminho, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Content-Disposition' => 'inline; filename="recibo-'.$recibo->id.'-'.$tipo.(str_ends_with($caminho, '.png') ? '.png' : '.jpg').'"',
        ]);
    }

    /**
     * O comprovante inteiro numa página pra imprimir/salvar em PDF: pacotes,
     * hora, entregador, consentimento com o texto exato, as imagens e o
     * SHA-256 de cada uma.
     */
    public function comprovante(Request $request, FlexPickupReceipt $recibo): Response
    {
        $this->auditar($request, 'flex_ver_comprovante', $recibo, []);

        $pedidos = Order::query()
            ->whereIn('id', $recibo->order_ids ?? [])
            ->with(['items:id,order_id,product_id,product_name,quantity', 'items.product:id,sku', 'channelShipment'])
            ->get();

        $integridade = fn (?string $caminho, ?string $hash) => [
            'existe' => $caminho !== null && Storage::disk('local')->exists($caminho),
            'hash' => $hash,
            // Recalcula na hora: é o que permite afirmar que o arquivo
            // mostrado é o mesmo gravado na entrega.
            'confere' => $caminho && $hash && Storage::disk('local')->exists($caminho)
                ? hash_equals($hash, hash('sha256', Storage::disk('local')->get($caminho)))
                : null,
        ];

        return Inertia::render('Admin/EnviosFlex/Comprovante', [
            'recibo' => [
                ...$this->controle->recibo($recibo),
                'dispositivo' => $recibo->device,
                'ip' => $recibo->ip_address,
                'navegador' => $recibo->user_agent,
                'textoDoConsentimento' => $recibo->consent_text,
                'assinaturaIntegridade' => $integridade($recibo->signature_path, $recibo->signature_sha256),
                'fotoIntegridade' => $integridade($recibo->photo_path, $recibo->photo_sha256),
            ],
            'pedidos' => $pedidos->map(fn (Order $pedido) => [
                'id' => $pedido->id,
                'venda' => $pedido->external_order_id,
                'envio' => $pedido->channelShipment?->external_shipment_id,
                'cliente' => trim((string) ($pedido->shipping_recipient_name ?: $pedido->shipping_name)) ?: null,
                'cidade' => trim(($pedido->shipping_city ?: '').($pedido->shipping_state ? '/'.$pedido->shipping_state : '')),
                'itens' => $pedido->items->map(fn ($item) => ['nome' => $item->product_name, 'sku' => $item->product?->sku, 'qtd' => (int) $item->quantity])->values(),
            ])->values(),
            'emitidoEm' => now()->toIso8601String(),
            'emitidoPor' => $request->user()?->name,
        ]);
    }

    private function garantirFlex(ChannelShipment $envio): void
    {
        abort_unless($envio->shipping_method === ChannelShipment::METHOD_FLEX, 404);
    }

    private function casaBusca(ChannelShipment $envio, string $busca): bool
    {
        $pedido = $envio->order;
        $texto = mb_strtolower($busca);

        return (string) $envio->order_id === $busca
            || str_contains((string) $pedido?->external_order_id, $busca)
            || str_contains((string) $envio->external_shipment_id, $busca)
            || str_contains(mb_strtolower((string) ($pedido?->shipping_recipient_name ?: $pedido?->shipping_name)), $texto)
            || str_contains(mb_strtolower((string) $pedido?->pickupReceipt?->carrier_name), $texto);
    }

    /** @return array<int, string> */
    private function nomesDeUsuarios(Collection $envios): array
    {
        $ids = $envios->pluck('return_resolved_by')->filter()->unique();

        return $ids->isEmpty() ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Auditoria de quem mexeu ou OLHOU a prova. `action` é texto livre na
     * tabela; o prefixo flex_ agrupa tudo desta tela na busca da auditoria.
     *
     * @param  array<string, mixed>  $dados
     */
    private function auditar(Request $request, string $acao, ChannelShipment|FlexPickupReceipt $alvo, array $dados): void
    {
        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => $acao,
            'entity' => class_basename($alvo),
            'entity_id' => $alvo->getKey(),
            'old_values' => null,
            'new_values' => [...$dados, 'ip' => $request->ip()],
            'created_at' => now(),
        ]);
    }
}
