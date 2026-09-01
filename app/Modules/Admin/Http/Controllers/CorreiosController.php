<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Services\CorreiosFreightQuoteService;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Services\Correios\CorreiosPrePostagemService;
use App\Services\Correios\Exceptions\CorreiosException;
use App\Services\Correios\Exceptions\CorreiosNotConfiguredException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu Correios — pedido explícito 2026-08-11: lista/histórico das
 * pré-postagens (QR Code) geradas pela loja, identificadas por
 * cliente + "onde comprou", tela de criação e impressão.
 *
 * Credenciais reais (CNPJ + código de acesso do CWS) configuradas no .env
 * (local e homolog) desde 2026-08-11; contrato liberado e
 * CORREIOS_CONTRATO/CARTAO_POSTAGEM + suas chaves de acesso escopadas
 * preenchidos em 2026-08-19 — ver CorreiosTokenService. store() grava a
 * tentativa (sucesso ou erro) no histórico mesmo quando a API recusa, então
 * nunca fica sem rastro do que foi tentado.
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

    public function __construct(
        private readonly CorreiosPrePostagemService $service,
        private readonly CorreiosFreightQuoteService $freightQuote,
    ) {
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
            'sender' => $this->presentSender(Company::query()->first()),
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
            ->with([
                'items:id,order_id,product_name,product_price,quantity',
                'invoice:id,order_id,status,serie,numero,valor_total,chave_acesso,autorizada_em,danfe_path',
            ])
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
            'invoice' => $this->presentInvoiceFiscal($order->invoice),
            'items' => $order->items->map(fn ($item) => [
                'conteudo' => $this->summarizeProductDeclaration($item->product_name),
                'quantidade' => $item->quantity,
                'valor' => (float) $item->product_price,
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->validationRules());
        $validated['content_items'] = $this->safeContentItems($validated['content_items']);

        $record = new CorreiosPrePostagem(['created_by' => Auth::id()]);
        $this->hydrateRecord($record, $validated);

        return $this->attemptCreate($record, $validated, 'Pré-postagem gerada — QR Code pronto pra impressão.');
    }

    /**
     * Reabre o formulário pra corrigir e tentar de novo — só faz sentido
     * pra tentativa que falhou (pedido explícito 2026-08-19: "isso pode
     * ser editável até gerar o qrcode"). Uma pré-postagem já gerada tem
     * QR Code/código de rastreio reais emitidos pelos Correios — editar
     * dali pra frente reabriria um protocolo que já existe do lado deles,
     * então cai direto pra tela de detalhe em vez de deixar editar.
     */
    public function edit(CorreiosPrePostagem $correio): Response|RedirectResponse
    {
        if ($correio->status !== CorreiosPrePostagem::STATUS_ERRO) {
            return redirect()->route('admin.correios.ver', $correio)->with('error', 'Essa pré-postagem já foi gerada — não dá mais pra editar.');
        }

        $correio->load('order.invoice:id,order_id,status,serie,numero,valor_total,chave_acesso,autorizada_em,danfe_path');

        return Inertia::render('Admin/Correios/Create', [
            'serviceOptions' => self::SERVICE_OPTIONS,
            'configured' => $this->service->isConfigured(),
            'sender' => $this->presentSender(Company::query()->first()),
            'editing' => $this->presentEditItem($correio),
        ]);
    }

    public function update(Request $request, CorreiosPrePostagem $correio): RedirectResponse
    {
        if ($correio->status !== CorreiosPrePostagem::STATUS_ERRO) {
            return redirect()->route('admin.correios.ver', $correio)->with('error', 'Essa pré-postagem já foi gerada — não dá mais pra editar.');
        }

        $validated = $request->validate($this->validationRules());
        $validated['content_items'] = $this->safeContentItems($validated['content_items']);

        $this->hydrateRecord($correio, $validated);

        return $this->attemptCreate($correio, $validated, 'Pré-postagem gerada — QR Code pronto pra impressão.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validationRules(): array
    {
        return [
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
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function hydrateRecord(CorreiosPrePostagem $record, array $validated): void
    {
        $serviceLabel = collect(self::SERVICE_OPTIONS)->firstWhere('value', $validated['service_code'])['label'] ?? $validated['service_code'];

        $record->fill([
            'order_id' => $validated['order_id'] ?? null,
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
            'dimension_format' => $validated['dimensions']['format'],
            'dimension_height' => $validated['dimensions']['height'] ?? null,
            'dimension_width' => $validated['dimensions']['width'] ?? null,
            'dimension_length' => $validated['dimensions']['length'] ?? null,
            'dimension_diameter' => $validated['dimensions']['diameter'] ?? null,
            'content_items' => $validated['content_items'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function safeContentItems(array $items): array
    {
        return collect($items)->map(fn (array $item) => [
            'conteudo' => $this->summarizeProductDeclaration($item['conteudo'] ?? null),
            'quantidade' => $item['quantidade'],
            'valor' => $item['valor'],
        ])->all();
    }

    private function summarizeProductDeclaration(?string $productName): string
    {
        $text = html_entity_decode(strip_tags((string) $productName));
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';
        $text = trim($text);

        if ($text === '') {
            return 'Produto KazaKora';
        }

        $text = preg_replace('/\s*[\[(].*?[\])]/u', ' ', $text) ?: $text;
        $text = preg_replace('/\b(kazakora|novo|nova|original|oficial|promo[cç][aã]o|frete\s+gr[aá]tis|envio\s+imediato|pronta\s+entrega|mercado\s+livre|shopee|tiktok\s+shop)\b/iu', ' ', $text) ?: $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?: $text;

        $main = preg_split('/\s+[|–—]\s+|\s+-\s+/u', $text)[0] ?? $text;
        $words = array_values(array_filter(explode(' ', $main)));
        $summary = implode(' ', array_slice($words, 0, 8));
        $summary = trim($summary) ?: 'Produto KazaKora';

        return Str::limit($summary, 60, '');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function attemptCreate(CorreiosPrePostagem $record, array $validated, string $successMessage): RedirectResponse
    {
        try {
            $result = $this->service->create($validated);

            $record->status = CorreiosPrePostagem::STATUS_GERADA;
            $record->correios_id = (string) ($result['id'] ?? '');
            $record->codigo_objeto = $result['codigoObjeto'] ?? null;
            $record->qr_payload = $record->codigo_objeto ?: $record->correios_id;
            $record->raw_response = $result;
            $record->error_message = null;
            // A API de pré-postagem não devolve preço (pago/pesado na
            // agência) — cota à parte via API de Preço, mesma
            // credencial/produto do checkout. Nunca lança; fica null se a
            // cotação falhar, não trava a criação da pré-postagem por causa
            // disso.
            $record->postage_price = $this->freightQuote->priceFor(
                $record->service_code,
                $record->zip,
                $record->weight_grams,
                $record->dimension_format,
                $record->dimension_height ? (float) $record->dimension_height : null,
                $record->dimension_width ? (float) $record->dimension_width : null,
                $record->dimension_length ? (float) $record->dimension_length : null,
                $record->dimension_diameter ? (float) $record->dimension_diameter : null,
            );
            $record->save();

            return redirect()->route('admin.correios.ver', $record)->with('success', $successMessage);
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
        $correio->load(
            'order:id,external_order_id',
            'order.items:id,order_id,product_name,product_price,quantity',
            'order.invoice:id,order_id,status,serie,numero,valor_total,chave_acesso,autorizada_em,danfe_path',
        );

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
            'postagePrice' => $item->postage_price !== null ? (float) $item->postage_price : null,
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
            'address' => implode(' — ', $this->addressLines(
                $item->street,
                $item->number,
                $item->complement,
                $item->neighborhood,
                $item->city,
                $item->state,
                $item->zip,
            )),
            'recipient' => [
                'name' => $item->customer_name,
                'document' => $item->customer_document,
                'phone' => $item->customer_phone,
                'email' => $item->customer_email,
                'addressLines' => $this->addressLines(
                    $item->street,
                    $item->number,
                    $item->complement,
                    $item->neighborhood,
                    $item->city,
                    $item->state,
                    $item->zip,
                ),
            ],
            'sender' => $this->presentSender(Company::query()->first()),
            'invoice' => $this->presentInvoiceFiscal($item->order?->invoice),
            'customerDocument' => $item->customer_document,
            'customerPhone' => $item->customer_phone,
            'weightGrams' => $item->weight_grams,
            'contentItems' => $this->contentItemsForLabel($item),
            'correiosId' => $item->correios_id,
            'qrPayload' => $item->qr_payload,
            'errorMessage' => $item->error_message,
        ];
    }

    /**
     * @return array{hasInvoice: bool, status: ?string, serie: ?int, numero: ?int, valorTotal: ?float, chaveAcesso: ?string, chaveFormatada: ?string, autorizadaEm: ?string, danfePath: ?string}
     */
    private function presentInvoiceFiscal(?Invoice $invoice): array
    {
        $accessKey = $invoice?->chave_acesso;

        return [
            'hasInvoice' => (bool) $invoice,
            'status' => $invoice?->status,
            'serie' => $invoice?->serie,
            'numero' => $invoice?->numero,
            'valorTotal' => $invoice?->valor_total !== null ? (float) $invoice->valor_total : null,
            'chaveAcesso' => $accessKey,
            'chaveFormatada' => $accessKey ? trim(chunk_split($accessKey, 4, ' ')) : null,
            'autorizadaEm' => $invoice?->autorizada_em?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            'danfePath' => $invoice?->danfe_path,
        ];
    }

    /**
     * @return array<int, array{conteudo: string, quantidade: mixed, valor: mixed}>
     */
    private function contentItemsForLabel(CorreiosPrePostagem $item): array
    {
        if ($item->order?->items?->isNotEmpty()) {
            return $item->order->items->map(fn ($orderItem) => [
                'conteudo' => $this->summarizeProductDeclaration($orderItem->product_name),
                'quantidade' => $orderItem->quantity,
                'valor' => $orderItem->product_price,
            ])->all();
        }

        return $this->safeContentItems($item->content_items ?? []);
    }

    /**
     * @return array{name: string, document: ?string, phone: ?string, email: ?string, addressLines: array<int, string>}
     */
    private function presentSender(?Company $company): array
    {
        return [
            'name' => $company?->nome_fantasia ?: $company?->razao_social ?: 'KazaKora',
            'document' => $company?->cnpj,
            'phone' => $company?->phone,
            'email' => $company?->email,
            'addressLines' => $company
                ? $this->addressLines(
                    $company->street,
                    $company->number,
                    $company->complement,
                    $company->neighborhood,
                    $company->city,
                    $company->state,
                    $company->zip,
                )
                : [],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function addressLines(?string $street, ?string $number, ?string $complement, ?string $neighborhood, ?string $city, ?string $state, ?string $zip): array
    {
        $streetLine = trim(implode(', ', array_filter([$street, $number])));

        if (filled($complement)) {
            $streetLine = trim($streetLine.' - '.$complement);
        }

        $cityState = trim(implode('/', array_filter([$city, $state])));
        $zipLine = filled($zip) ? 'CEP '.$zip : null;

        return array_values(array_filter([
            $streetLine,
            $neighborhood,
            trim(implode(' — ', array_filter([$zipLine, $cityState]))),
        ]));
    }

    /**
     * Mesmo shape que o form de Create.vue usa — pra reabrir a tela já
     * preenchida com o que foi tentado da última vez (ver edit()).
     *
     * @return array<string, mixed>
     */
    private function presentEditItem(CorreiosPrePostagem $item): array
    {
        return [
            'id' => $item->id,
            'orderId' => $item->order_id,
            'origin' => $item->origin,
            'externalOrderId' => $item->external_order_id,
            'customer' => [
                'name' => $item->customer_name,
                'document' => $item->customer_document,
                'phone' => $item->customer_phone,
                'email' => $item->customer_email,
            ],
            'address' => [
                'zip' => $item->zip,
                'street' => $item->street,
                'number' => $item->number,
                'complement' => $item->complement,
                'neighborhood' => $item->neighborhood,
                'city' => $item->city,
                'state' => $item->state,
            ],
            'serviceCode' => $item->service_code,
            'weightGrams' => $item->weight_grams,
            'dimensions' => [
                'format' => $item->dimension_format,
                'height' => $item->dimension_height,
                'width' => $item->dimension_width,
                'length' => $item->dimension_length,
                'diameter' => $item->dimension_diameter,
            ],
            'invoice' => $this->presentInvoiceFiscal($item->order?->invoice),
            'contentItems' => $item->content_items,
            'errorMessage' => $item->error_message,
        ];
    }
}
