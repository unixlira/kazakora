<?php

namespace App\Services\Meta;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Support\PixelMeta;
use App\Support\Privacidade\Cookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * API de Conversões da Meta (pedido do Lira 2026-10-18). Manda do servidor
 * os mesmos AddToCart e Purchase do Pixel (Shared/pixelMeta.js), com o MESMO
 * event_id e event_name: a Meta junta os dois e conta uma vez só. O servidor
 * cobre o que o navegador perde (bloqueador de anúncio, Pix/boleto pago
 * depois, aba fechada).
 *
 * Consentimento (docs/privacidade-e-cookies.md): só manda evento de quem deu
 * OK no aviso de cookies na versão atual, igual ao Pixel. Sem OK, nada sai.
 *
 * Desligado (META_CAPI_ENABLED), sem token ou sem pixel: não manda nada e só
 * registra no log. Erro da Meta nunca derruba a loja (o envio roda na fila).
 */
class ConversoesMeta
{
    /** $site null = kazakora.com; 'ferrakora' = pixel próprio do site (2026-10-28). */
    public function ativo(?string $site = null): bool
    {
        $destino = self::destino($site);

        return $destino['enabled'] && filled($destino['access_token']) && filled($destino['pixel_id']);
    }

    /**
     * Pixel, chave, teste e liga/desliga de quem recebe o evento.
     *
     * @return array{enabled: bool, access_token: ?string, pixel_id: ?string, test_event_code: ?string}
     */
    public static function destino(?string $site = null): array
    {
        if ($site === null) {
            return [
                'enabled' => (bool) config('services.meta_capi.enabled'),
                'access_token' => config('services.meta_capi.access_token'),
                'pixel_id' => PixelMeta::id(),
                'test_event_code' => config('services.meta_capi.test_event_code'),
            ];
        }

        $config = (array) config("services.meta_capi.sites.{$site}", []);

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'access_token' => $config['access_token'] ?? null,
            'pixel_id' => self::pixelDoSite($site),
            'test_event_code' => $config['test_event_code'] ?? null,
        ];
    }

    /** ID do pixel de um outro site (FerraKora); null = o site não tem pixel. */
    public static function pixelDoSite(string $site): ?string
    {
        $id = preg_replace('/\D/', '', (string) config("services.meta_capi.sites.{$site}.pixel_id"));

        return $id !== '' ? $id : null;
    }

    /** O cliente deu OK nos cookies (na versão atual do aviso)? */
    public function consentiu(Request $request): bool
    {
        return Cookies::aceitou($request);
    }

    /**
     * Envia um evento já montado (ver evento()). Devolve true se a Meta
     * aceitou; false se pulou (desligado). Erro da Meta lança exceção, para
     * a fila tentar de novo.
     *
     * @param  array<string, mixed>  $evento
     */
    public function enviar(array $evento, ?string $site = null): bool
    {
        $destino = self::destino($site);

        if (! $this->ativo($site)) {
            Log::info('meta_capi.pulado', [
                'motivo' => ! $destino['enabled'] ? 'desligado' : (blank($destino['access_token']) ? 'sem_token' : 'sem_pixel'),
                'site' => $site,
                'event_name' => $evento['event_name'] ?? null,
                'event_id' => $evento['event_id'] ?? null,
            ]);

            return false;
        }

        $corpo = ['data' => [$evento]];
        if (filled($codigo = $destino['test_event_code'])) {
            $corpo['test_event_code'] = $codigo;
        }

        $url = rtrim((string) config('services.meta_ads.graph_url', 'https://graph.facebook.com/v23.0'), '/').'/'.$destino['pixel_id'].'/events';

        $resposta = Http::asJson()->acceptJson()->timeout(15)
            ->withQueryParameters(['access_token' => (string) $destino['access_token']])
            ->post($url, $corpo);

        if ($resposta->failed()) {
            Log::warning('meta_capi.erro', [
                'site' => $site,
                'event_name' => $evento['event_name'] ?? null,
                'event_id' => $evento['event_id'] ?? null,
                'status' => $resposta->status(),
                'erro' => $resposta->json('error.message') ?? Str::limit($resposta->body(), 300),
            ]);
            $resposta->throw();
        }

        Log::info('meta_capi.enviado', [
            'site' => $site,
            'event_name' => $evento['event_name'] ?? null,
            'event_id' => $evento['event_id'] ?? null,
            'recebidos' => $resposta->json('events_received'),
            'teste' => isset($corpo['test_event_code']),
            'fbtrace_id' => $resposta->json('fbtrace_id'),
        ]);

        return true;
    }

    /**
     * Contexto do navegador para guardar e mandar depois: IP, navegador,
     * página, _fbp e _fbc. Os cookies da Meta chegam em texto puro (o Laravel
     * os descarta ao tentar abrir), por isso vêm de Cookies::doVisitante().
     *
     * @return array{ip: ?string, ua: ?string, url: ?string, fbp: ?string, fbc: ?string}
     */
    public function contextoDaRequisicao(Request $request, ?string $url = null): array
    {
        $cookies = Cookies::doVisitante($request);

        return [
            'ip' => $request->ip(),
            'ua' => Str::limit((string) $request->userAgent(), 500, '') ?: null,
            'url' => $url ?? ($request->headers->get('referer') ?: $request->fullUrl()),
            'fbp' => $cookies['_fbp'] ?? null,
            // Sem _fbc no navegador (aceite no checkout antes de o Pixel carregar): o clique da sessão.
            'fbc' => $cookies['_fbc'] ?? Cookies::fbcDaSessao($request),
        ];
    }

    /**
     * Rastreio guardado no pedido do site para o Purchase da aprovação.
     * Sem OK de cookies = null (o Purchase do servidor não sai).
     *
     * @return array<string, mixed>|null
     */
    public function rastreioDoPedido(Request $request): ?array
    {
        if (! $this->consentiu($request)) {
            return null;
        }

        return [...$this->contextoDaRequisicao($request), 'consentiu' => Cookies::VERSAO];
    }

    /** AddToCart do servidor, com o event_id que o front mandou ao Pixel. */
    public function eventoAddToCart(Request $request, Product $product, int $quantidade, string $eventId): array
    {
        $preco = round((float) $product->final_price, 2);

        return $this->evento('AddToCart', $eventId, now()->getTimestamp(), $this->contextoDaRequisicao($request), $this->dadosDoCliente($request->user()), [
            'value' => round($preco * $quantidade, 2),
            'currency' => 'BRL',
            'content_type' => 'product',
            'content_ids' => [(string) $product->id],
            'contents' => [['id' => (string) $product->id, 'quantity' => $quantidade, 'item_price' => $preco]],
        ]);
    }

    /** event_id fixo do Purchase: o Pixel do front usa o mesmo (pixelMeta.js). */
    public static function eventIdDoPurchase(Order $order): string
    {
        return "purchase-{$order->id}";
    }

    /**
     * Dados do Purchase que o Pixel do front e o servidor mandam iguais.
     *
     * @return array<string, mixed>
     */
    public static function dadosDoPurchase(Order $order): array
    {
        $order->loadMissing('items');
        $itens = $order->items->filter(fn ($item) => $item->product_id);

        return [
            'value' => round((float) $order->total, 2),
            'currency' => 'BRL',
            'content_type' => 'product',
            'content_ids' => $itens->map(fn ($item) => (string) $item->product_id)->unique()->values()->all(),
            'contents' => $itens->map(fn ($item) => [
                'id' => (string) $item->product_id,
                'quantity' => (int) $item->quantity,
                'item_price' => round((float) $item->product_price, 2),
            ])->values()->all(),
            'num_items' => (int) $itens->sum('quantity'),
            'order_id' => (string) ($order->external_order_id ?: $order->id),
        ];
    }

    /** Pedido do kazakora.com (não marketplace, não FerraKora/Beauty Kora). */
    public static function pedidoDaLoja(Order $order): bool
    {
        return $order->origin === Order::ORIGIN_STORE && $order->site_origem === null;
    }

    /**
     * Para onde vai o Purchase do pedido: false = nenhum (marketplace, Beauty
     * Kora, site sem pixel); null = pixel da KazaKora; 'ferrakora' = o do site.
     */
    public static function siteDoPedido(Order $order): string|null|false
    {
        if (self::pedidoDaLoja($order)) {
            return null;
        }

        return $order->site_origem === Order::SITE_FERRAKORA && self::pixelDoSite(Order::SITE_FERRAKORA) !== null
            ? Order::SITE_FERRAKORA
            : false;
    }

    /**
     * Rastreio do pedido de outro site (FerraKora). O aceite dos cookies é a
     * frase do Finalizar pedido (Cookies::registrarAceite), então todo pedido
     * criado ali já tem o OK. _fbp/_fbc e a página vêm do próprio site no
     * corpo do pedido: os cookies são do domínio dele, não chegam aqui.
     *
     * @param  array{fbp?: ?string, fbc?: ?string, url?: ?string}  $doSite
     * @return array<string, mixed>|null
     */
    public function rastreioDoOutroSite(Request $request, string $site, array $doSite): ?array
    {
        if (self::pixelDoSite($site) === null) {
            return null;
        }

        $base = rtrim((string) config("services.meta_capi.sites.{$site}.site_url"), '/');
        $url = (string) ($doSite['url'] ?? '');

        return [
            'ip' => $request->ip(),
            'ua' => Str::limit((string) $request->userAgent(), 500, '') ?: null,
            'url' => $base !== '' && str_starts_with($url, $base.'/') ? Str::limit($url, 500, '') : ($base !== '' ? $base.'/' : null),
            'fbp' => self::cookieDaMeta($doSite['fbp'] ?? null),
            'fbc' => self::cookieDaMeta($doSite['fbc'] ?? null),
            'consentiu' => Cookies::VERSAO,
        ];
    }

    /** _fbp/_fbc no formato da Meta (fb.1.<tempo>.<valor>); outra coisa vira null. */
    public static function cookieDaMeta(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/^fb\.[0-9]\.[0-9]{10,13}\.[A-Za-z0-9._-]{1,500}$/', $valor) ? $valor : null;
    }

    public function eventoPurchase(Order $order): array
    {
        $order->loadMissing('user');
        $rastreio = (array) $order->meta_rastreio;

        $cliente = $this->dadosDoCliente($order->user, [
            'email' => $order->shipping_email,
            'telefone' => $order->shipping_phone,
            'nome' => $order->shipping_name,
            'cidade' => $order->shipping_city,
            'uf' => $order->shipping_state,
            'cep' => $order->shipping_zip,
        ]);

        return $this->evento('Purchase', self::eventIdDoPurchase($order), now()->getTimestamp(), $rastreio, $cliente, self::dadosDoPurchase($order));
    }

    /**
     * @param  array<string, mixed>  $contexto  ip, ua, url, fbp, fbc
     * @param  array<string, mixed>  $cliente  user_data já com hash
     * @param  array<string, mixed>  $custom
     * @return array<string, mixed>
     */
    public function evento(string $nome, string $eventId, int $quando, array $contexto, array $cliente, array $custom): array
    {
        $userData = array_filter([
            ...$cliente,
            'client_ip_address' => $contexto['ip'] ?? null,
            'client_user_agent' => $contexto['ua'] ?? null,
            'fbp' => $contexto['fbp'] ?? null,
            'fbc' => $contexto['fbc'] ?? null,
        ], fn ($valor) => filled($valor));

        return array_filter([
            'event_name' => $nome,
            'event_time' => $quando,
            'event_id' => $eventId,
            'event_source_url' => $contexto['url'] ?? null,
            'action_source' => 'website',
            'user_data' => $userData,
            'custom_data' => $custom,
        ], fn ($valor) => filled($valor));
    }

    /**
     * user_data com hash SHA-256 do texto normalizado, como a Meta pede.
     * Dados do pedido (entrega) completam os da conta.
     *
     * @param  array{email?: ?string, telefone?: ?string, nome?: ?string, cidade?: ?string, uf?: ?string, cep?: ?string}  $extra
     * @return array<string, mixed>
     */
    public function dadosDoCliente(?User $user, array $extra = []): array
    {
        $email = self::normalizarEmail($user?->email ?: ($extra['email'] ?? null));
        $telefone = self::normalizarTelefone($extra['telefone'] ?? null) ?? self::normalizarTelefone($user?->phone);
        [$primeiro, $ultimo] = self::partesDoNome(($extra['nome'] ?? null) ?: $user?->name);
        $cidade = self::normalizarCidade($extra['cidade'] ?? null);
        $uf = self::normalizarUf($extra['uf'] ?? null);
        $cep = self::normalizarCep($extra['cep'] ?? null);

        return array_filter([
            'em' => $email ? [self::hash($email)] : null,
            'ph' => $telefone ? [self::hash($telefone)] : null,
            'fn' => $primeiro ? self::hash($primeiro) : null,
            'ln' => $ultimo ? self::hash($ultimo) : null,
            'ct' => $cidade ? self::hash($cidade) : null,
            'st' => $uf ? self::hash($uf) : null,
            'zp' => $cep ? self::hash($cep) : null,
            'country' => ($email || $telefone || $cep) ? self::hash('br') : null,
            'external_id' => $user ? self::hash((string) $user->id) : null,
        ], fn ($valor) => $valor !== null);
    }

    public static function hash(string $valor): string
    {
        return hash('sha256', $valor);
    }

    public static function normalizarEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Só dígitos, com o 55 do Brasil na frente. */
    public static function normalizarTelefone(?string $telefone): ?string
    {
        $digitos = ltrim((string) preg_replace('/\D/', '', (string) $telefone), '0');

        if (strlen($digitos) === 10 || strlen($digitos) === 11) {
            return '55'.$digitos;
        }

        return (str_starts_with($digitos, '55') && in_array(strlen($digitos), [12, 13], true)) ? $digitos : null;
    }

    /** [primeiro nome, último sobrenome], minúsculos e sem pontuação. */
    public static function partesDoNome(?string $nome): array
    {
        $limpo = trim((string) preg_replace('/[^\p{L}\s]/u', '', mb_strtolower(trim((string) $nome))));
        $partes = preg_split('/\s+/u', $limpo, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [$partes[0] ?? null, count($partes) > 1 ? end($partes) : null];
    }

    /** Cidade: minúscula, sem acento, sem espaço ("São Paulo" → "saopaulo"). */
    public static function normalizarCidade(?string $cidade): ?string
    {
        $limpa = preg_replace('/[^a-z]/', '', mb_strtolower(Str::ascii((string) $cidade)));

        return $limpa !== '' ? $limpa : null;
    }

    public static function normalizarUf(?string $uf): ?string
    {
        $limpa = preg_replace('/[^a-z]/', '', mb_strtolower((string) $uf));

        return strlen($limpa) === 2 ? $limpa : null;
    }

    public static function normalizarCep(?string $cep): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $cep);

        return strlen($digitos) === 8 ? $digitos : null;
    }

    /** event_id que veio do front: só formato seguro (uuid e afins). */
    public static function eventIdValido(mixed $id): ?string
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $id) ? $id : null;
    }

    /** Erro ao montar não pode atrapalhar o carrinho nem o pagamento. */
    public static function semQuebrar(callable $acao): void
    {
        try {
            $acao();
        } catch (Throwable $e) {
            Log::warning('meta_capi.falha_ao_preparar', ['erro' => $e->getMessage()]);
        }
    }
}
