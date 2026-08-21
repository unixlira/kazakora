<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Pedido explícito 2026-08-06: tela pra buscar manualmente um pedido de
 * marketplace pelo ID que ele tem LÁ (ex: número do pedido no Mercado
 * Livre) quando o webhook falhou/atrasou e o pedido nunca chegou sozinho —
 * usa exatamente o mesmo caminho real que um webhook usaria
 * (OrderImportService::import(), que já é idempotente: se o pedido já
 * existe localmente, só resincroniza o status em vez de duplicar), então
 * um pedido importado por aqui gera envio/etiqueta/nota fiscal do mesmo
 * jeito automático de sempre (ver ConfirmChannelShippingJob/
 * GenerateInvoiceJob disparados dentro do import). Diferente da tela de
 * Etiquetas Manuais (ManualLabelController): aqui não existe ZPL
 * colado/PDF avulso — é um pedido de verdade, buscado ao vivo na API do
 * canal.
 */
class OrderImportController extends Controller
{
    private const CHANNEL_LABELS = [
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE => 'Mercado Livre',
        MarketplaceAccount::CHANNEL_SHOPEE => 'Shopee',
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP => 'TikTok Shop',
    ];

    public function __construct(private readonly MarketplaceDriverManager $drivers)
    {
    }

    public function create(): Response
    {
        return Inertia::render('Admin/ImportarPedido/Form', [
            'channels' => $this->channelOptions(),
        ]);
    }

    public function store(Request $request, OrderImportService $importer): RedirectResponse
    {
        // Só os 3 canais com driver registrado de verdade (ver
        // MarketplaceDriverManager) — Amazon/Shein têm constante de canal
        // mas nenhuma integração implementada, driver('amazon') estoura
        // InvalidArgumentException antes mesmo de chegar na API.
        $validated = $request->validate([
            'channel' => ['required', Rule::in($this->drivers->channels())],
            'external_order_id' => ['required', 'string', 'max:100'],
        ]);

        try {
            $order = $importer->import($validated['channel'], $validated['external_order_id']);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with(
                'error',
                "Não foi possível buscar esse pedido no {$this->channelLabel($validated['channel'])}: {$exception->getMessage()}"
            );
        }

        // Pedido explícito 2026-08-21: Shopee com pagamento ainda pendente
        // não vira Order nenhum (ver OrderImportService::importNormalized())
        // — null aqui é esse caso normal, não uma falha.
        if (! $order) {
            return back()->withInput()->with(
                'error',
                "Esse pedido do {$this->channelLabel($validated['channel'])} ainda está com pagamento pendente — não é importado até o pagamento ser confirmado no canal."
            );
        }

        // Envio/etiqueta/nota fiscal são disparados de dentro do próprio
        // import() (afterCommit) quando o pedido já vem pago — mesma fila
        // assíncrona que um webhook real usaria, por isso a mensagem avisa
        // que ainda leva um instante em vez de prometer a etiqueta já
        // pronta nesse redirect.
        $message = $order->wasRecentlyCreated
            ? "Pedido #{$order->id} importado do {$this->channelLabel($validated['channel'])}."
            : "Pedido #{$order->id} já existia — status resincronizado ({$order->status}).";

        if ($order->status === Order::STATUS_PAID) {
            $message .= ' Como está pago, o envio e a etiqueta serão processados em instantes (mesmo fluxo automático de sempre).';
        }

        return redirect()->route('admin.pedidos.exibir', $order)->with('success', $message);
    }

    private function channelLabel(string $channel): string
    {
        return self::CHANNEL_LABELS[$channel] ?? $channel;
    }

    private function channelOptions(): array
    {
        return collect($this->drivers->channels())
            ->map(fn (string $channel) => ['value' => $channel, 'label' => $this->channelLabel($channel)])
            ->values()
            ->all();
    }
}
