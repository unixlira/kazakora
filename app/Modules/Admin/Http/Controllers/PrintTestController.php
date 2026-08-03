<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Marketplace\Support\LabelProcessingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * Tela exclusivamente de teste — Admin/Integracoes/TesteImpressao — pra
 * validar manualmente o processamento de etiqueta (ZPL->PDF pra Shopee,
 * sobreposição da lista de produtos em negrito) antes de automatizar esse
 * fluxo. O agente local (KoraSync) não muda: continua só consumindo
 * PrintJob da fila normal, exatamente como já faz hoje.
 */
class PrintTestController extends Controller
{
    private const CHANNELS = [
        MarketplaceAccount::CHANNEL_SHOPEE => 'Shopee',
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE => 'Mercado Livre',
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP => 'TikTok Shop',
        MarketplaceAccount::CHANNEL_AMAZON => 'Amazon',
    ];

    public function index(): Response
    {
        $orders = Order::query()
            ->with('items:id,order_id,product_name,quantity')
            ->latest('id')
            ->limit(100)
            ->get(['id', 'origin', 'shipping_name', 'created_at'])
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'label' => "#{$order->id} — {$order->shipping_name} ({$order->origin})",
                'products' => $order->items->map(fn ($item) => $item->quantity > 1
                    ? "{$item->product_name} (x{$item->quantity})"
                    : $item->product_name)->all(),
            ]);

        return Inertia::render('Admin/Integracoes/TesteImpressao', [
            'channels' => collect(self::CHANNELS)->map(fn ($name, $channel) => ['value' => $channel, 'label' => $name])->values(),
            'orders' => $orders,
        ]);
    }

    public function store(Request $request, LabelProcessingService $processor): RedirectResponse
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::in(array_keys(self::CHANNELS))],
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $channel = $validated['channel'];
        $isShopee = $channel === MarketplaceAccount::CHANNEL_SHOPEE;

        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        $expectedExtension = $isShopee ? 'txt' : 'pdf';

        if ($extension !== $expectedExtension) {
            return back()->with('error', $isShopee
                ? 'Shopee espera um arquivo .txt com o conteúdo ZPL.'
                : "Esse canal espera um arquivo .pdf (recebido .{$extension}).");
        }

        $order = Order::query()->with('items')->findOrFail($validated['order_id']);
        $productNames = $order->items->map(fn ($item) => $item->quantity > 1
            ? "{$item->product_name} (x{$item->quantity})"
            : $item->product_name)->all();

        try {
            $pdfBytes = $isShopee
                ? $processor->convertZplToPdf($request->file('file')->get())
                : $request->file('file')->get();

            $finalPdf = $processor->overlayProductList($pdfBytes, $productNames);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Falha ao processar a etiqueta: '.$exception->getMessage());
        }

        $path = "labels/teste-{$order->id}-".now()->timestamp.'-'.uniqid().'.pdf';
        Storage::disk('local')->put($path, $finalPdf);

        $printJob = PrintJob::create([
            'order_id' => $order->id,
            'label_path' => $path,
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        return back()->with('success', "Job de impressão #{$printJob->id} criado pro pedido #{$order->id} — o KoraSync vai processar assim que estiver aberto.");
    }
}
