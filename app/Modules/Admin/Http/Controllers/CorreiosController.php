<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Services\Correios\CorreiosPrePostagemService;
use App\Services\Correios\Exceptions\CorreiosException;
use App\Services\Correios\Exceptions\CorreiosNotConfiguredException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu Correios — pedido explícito 2026-08-11: lista/histórico das
 * pré-postagens (QR Code) geradas pela loja, identificadas por
 * cliente + "onde comprou", tela de criação e impressão.
 *
 * Credenciais reais (CNPJ + código de acesso do CWS) já configuradas no
 * .env (local e homolog) em 2026-08-11, mas sem CORREIOS_CONTRATO/
 * CARTAO_POSTAGEM ainda — ver CorreiosTokenService. Enquanto isso,
 * store() ainda funciona e grava a tentativa (sucesso ou erro) no
 * histórico, pra tela já estar pronta assim que a Correios liberar o
 * contrato.
 */
class CorreiosController extends Controller
{
    public const ORIGIN_LABELS = [
        Order::ORIGIN_STORE => 'Loja própria',
        Order::ORIGIN_MERCADO_LIVRE => 'Mercado Livre',
        Order::ORIGIN_SHOPEE => 'Shopee',
        Order::ORIGIN_TIKTOK_SHOP => 'TikTok Shop',
        Order::ORIGIN_AMAZON => 'Amazon',
        Order::ORIGIN_SHEIN => 'Shein',
    ];

    private const SERVICE_OPTIONS = [
        ['value' => '03298', 'label' => 'PAC (contrato)'],
        ['value' => '03220', 'label' => 'SEDEX (contrato)'],
    ];

    public function __construct(private readonly CorreiosPrePostagemService $service)
    {
    }

    public function index(Request $request): Response
    {
        $today = Carbon::today();
        $selectedMonth = $request->filled('mes')
            ? Carbon::createFromFormat('Y-m', $request->string('mes'))
            : $today;

        $search = $request->string('pedido')->toString() ?: null;

        $items = CorreiosPrePostagem::query()
            ->whereBetween('created_at', [$selectedMonth->copy()->startOfMonth(), $selectedMonth->copy()->endOfMonth()])
            // Agrupado num where(closure) — orWhere solto aqui escaparia do
            // whereBetween acima (viraria "no mês OU bate a busca", não "no
            // mês E bate a busca").
            ->when($search, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('customer_name', 'like', "%{$search}%")
                ->orWhere('external_order_id', 'like', "%{$search}%")
                ->orWhere('order_id', $search)
                ->orWhere('codigo_objeto', 'like', "%{$search}%")))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CorreiosPrePostagem $item) => $this->presentListItem($item));

        return Inertia::render('Admin/Correios/Index', [
            'items' => $items,
            'filters' => [
                'mes' => $selectedMonth->format('Y-m'),
                'pedido' => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Correios/Create', [
            'serviceOptions' => self::SERVICE_OPTIONS,
            'configured' => $this->service->isConfigured(),
        ]);
    }

    /**
     * Busca um pedido pra pré-preencher o formulário — não usa Inertia
     * (só devolve os dados pro form já aberto completar), mesmo padrão de
     * endpoint JSON simples já usado em outros lugares do admin (ver
     * ProductController).
     */
    public function buscarPedido(Request $request): JsonResponse
    {
        $numero = $request->string('numero')->trim()->toString();

        if ($numero === '') {
            return response()->json(['message' => 'Informe um número de pedido.'], 422);
        }

        $order = Order::query()
            ->with('items:id,order_id,product_name,product_price,quantity')
            ->where('external_order_id', $numero)
            ->when(is_numeric($numero), fn ($query) => $query->orWhere('id', $numero))
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Pedido não encontrado.'], 404);
        }

        return response()->json([
            'orderId' => $order->id,
            'externalOrderId' => $order->external_order_id,
            'origin' => $order->origin,
            'originLabel' => self::ORIGIN_LABELS[$order->origin] ?? $order->origin,
            'customer' => [
                'name' => $order->shipping_name,
                'document' => $order->buyer_document,
                'phone' => $order->shipping_phone,
                'email' => $order->shipping_email,
            ],
            'address' => [
                'zip' => $order->shipping_zip,
                'street' => $order->shipping_street,
                'number' => $order->shipping_number,
                'complement' => $order->shipping_complement,
                'neighborhood' => $order->shipping_neighborhood,
                'city' => $order->shipping_city,
                'state' => $order->shipping_state,
            ],
            'items' => $order->items->map(fn ($item) => [
                'conteudo' => $item->product_name,
                'quantidade' => $item->quantity,
                'valor' => (float) $item->product_price,
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'origin' => ['nullable', 'string'],
            'external_order_id' => ['nullable', 'string'],
            'customer.name' => ['required', 'string', 'min:3', 'max:50'],
            'customer.document' => ['nullable', 'string', 'max:20'],
            'customer.phone' => ['nullable', 'string', 'max:20'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'address.zip' => ['required', 'string', 'max:17'],
            'address.street' => ['required', 'string', 'max:50'],
            'address.number' => ['required', 'string', 'max:6'],
            'address.complement' => ['nullable', 'string', 'max:30'],
            'address.neighborhood' => ['required', 'string', 'max:30'],
            'address.city' => ['required', 'string', 'max:30'],
            'address.state' => ['required', 'string', 'size:2'],
            'service_code' => ['required', 'string', 'max:10'],
            'weight_grams' => ['required', 'integer', 'min:1', 'max:30000'],
            'dimensions.format' => ['required', 'in:1,2,3'],
            'dimensions.height' => ['nullable', 'numeric', 'min:0'],
            'dimensions.width' => ['nullable', 'numeric', 'min:0'],
            'dimensions.length' => ['nullable', 'numeric', 'min:0'],
            'dimensions.diameter' => ['nullable', 'numeric', 'min:0'],
            'content_items' => ['required', 'array', 'min:1'],
            'content_items.*.conteudo' => ['required', 'string', 'max:60'],
            'content_items.*.quantidade' => ['required', 'integer', 'min:1'],
            'content_items.*.valor' => ['required', 'numeric', 'min:0.01'],
        ]);

        $serviceLabel = collect(self::SERVICE_OPTIONS)->firstWhere('value', $validated['service_code'])['label'] ?? $validated['service_code'];

        $record = new CorreiosPrePostagem([
            'order_id' => $validated['order_id'] ?? null,
            'created_by' => Auth::id(),
            'origin' => $validated['origin'] ?? null,
            'external_order_id' => $validated['external_order_id'] ?? null,
            'customer_name' => $validated['customer']['name'],
            'customer_document' => $validated['customer']['document'] ?? null,
            'customer_phone' => $validated['customer']['phone'] ?? null,
            'customer_email' => $validated['customer']['email'] ?? null,
            'zip' => $validated['address']['zip'],
            'street' => $validated['address']['street'],
            'number' => $validated['address']['number'],
            'complement' => $validated['address']['complement'] ?? null,
            'neighborhood' => $validated['address']['neighborhood'],
            'city' => $validated['address']['city'],
            'state' => strtoupper($validated['address']['state']),
            'service_code' => $validated['service_code'],
            'service_label' => $serviceLabel,
            'weight_grams' => $validated['weight_grams'],
            'content_items' => $validated['content_items'],
        ]);

        try {
            $result = $this->service->create($validated);

            $record->status = CorreiosPrePostagem::STATUS_GERADA;
            $record->correios_id = (string) ($result['id'] ?? '');
            $record->codigo_objeto = $result['codigoObjeto'] ?? null;
            $record->qr_payload = $record->codigo_objeto ?: $record->correios_id;
            $record->raw_response = $result;
            $record->save();

            return redirect()->route('admin.correios.ver', $record)->with('success', 'Pré-postagem gerada — QR Code pronto pra impressão.');
        } catch (CorreiosNotConfiguredException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (CorreiosException $exception) {
            $record->status = CorreiosPrePostagem::STATUS_ERRO;
            $record->error_message = $exception->getMessage();
            $record->save();

            return redirect()->route('admin.correios.ver', $record)->with('error', $exception->getMessage());
        }
    }

    public function show(CorreiosPrePostagem $correio): Response
    {
        $correio->load('order:id,external_order_id');

        return Inertia::render('Admin/Correios/Show', [
            'item' => $this->presentDetail($correio),
        ]);
    }

    public function destroy(CorreiosPrePostagem $correio): RedirectResponse
    {
        $correio->delete();

        return redirect()->route('admin.correios.listar')->with('success', 'Registro removido.');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentListItem(CorreiosPrePostagem $item): array
    {
        return [
            'id' => $item->id,
            'customerName' => $item->customer_name,
            'origin' => $item->origin,
            'originLabel' => self::ORIGIN_LABELS[$item->origin] ?? ($item->origin ?: '—'),
            'externalOrderId' => $item->external_order_id,
            'orderId' => $item->order_id,
            'serviceLabel' => $item->service_label,
            'status' => $item->status,
            'codigoObjeto' => $item->codigo_objeto,
            'createdAt' => $item->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(CorreiosPrePostagem $item): array
    {
        return [
            ...$this->presentListItem($item),
            'address' => implode(' — ', array_filter([
                trim("{$item->street} {$item->number}"),
                $item->neighborhood,
                trim("{$item->city}/{$item->state}"),
                $item->zip,
            ])),
            'customerDocument' => $item->customer_document,
            'customerPhone' => $item->customer_phone,
            'weightGrams' => $item->weight_grams,
            'contentItems' => $item->content_items,
            'correiosId' => $item->correios_id,
            'qrPayload' => $item->qr_payload,
            'errorMessage' => $item->error_message,
        ];
    }
}
