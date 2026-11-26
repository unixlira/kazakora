<?php

namespace App\Modules\Infoprodutos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Infoprodutos\Models\InfoprodutoCarrinho;
use App\Modules\Infoprodutos\Models\InfoprodutoVenda;
use App\Modules\Infoprodutos\Support\ProdutoKiwify;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /api-infoprodutos/kiwify/webhook (2026-11-19): vendas dos
 * infoprodutos avisadas pela Kiwify (compra aprovada, recusada, pix/boleto
 * gerado, carrinho abandonado, reembolso, chargeback).
 *
 * - Autenticação: ?signature= = HMAC-SHA1 do corpo com o token do webhook
 *   (KIWIFY_WEBHOOK_TOKEN). Sem assinatura válida = 401.
 * - Idempotente: o mesmo aviso (id da venda + evento) chega mais de uma vez
 *   e só conta uma (infoproduto_kiwify_avisos).
 * - Sem dado pessoal: nome, e-mail, telefone, documento e IP do comprador
 *   NÃO são gravados; fica o id da venda, produto, valor, moeda, país e UTMs.
 */
class KiwifyWebhookController extends Controller
{
    /** Evento da Kiwify => status nosso. */
    private const EVENTOS = [
        'order_approved' => InfoprodutoVenda::APROVADA,
        'compra_aprovada' => InfoprodutoVenda::APROVADA,
        'order_rejected' => InfoprodutoVenda::RECUSADA,
        'compra_recusada' => InfoprodutoVenda::RECUSADA,
        'order_refunded' => InfoprodutoVenda::REEMBOLSADA,
        'compra_reembolsada' => InfoprodutoVenda::REEMBOLSADA,
        'chargeback' => InfoprodutoVenda::CHARGEBACK,
        'billet_created' => InfoprodutoVenda::PENDENTE,
        'boleto_gerado' => InfoprodutoVenda::PENDENTE,
        'pix_created' => InfoprodutoVenda::PENDENTE,
        'pix_gerado' => InfoprodutoVenda::PENDENTE,
    ];

    /** order_status da Kiwify => status nosso (quando o evento não vem). */
    private const STATUS = [
        'paid' => InfoprodutoVenda::APROVADA,
        'approved' => InfoprodutoVenda::APROVADA,
        'refused' => InfoprodutoVenda::RECUSADA,
        'rejected' => InfoprodutoVenda::RECUSADA,
        'refunded' => InfoprodutoVenda::REEMBOLSADA,
        'chargedback' => InfoprodutoVenda::CHARGEBACK,
        'waiting_payment' => InfoprodutoVenda::PENDENTE,
    ];

    /** Um status só "anda para frente": aviso atrasado de pix não desfaz a aprovação. */
    private const ORDEM = [
        InfoprodutoVenda::PENDENTE => 1,
        InfoprodutoVenda::RECUSADA => 2,
        InfoprodutoVenda::APROVADA => 3,
        InfoprodutoVenda::REEMBOLSADA => 4,
        InfoprodutoVenda::CHARGEBACK => 4,
    ];

    public function receber(Request $request): JsonResponse
    {
        $corpo = (string) $request->getContent();
        if (! self::assinaturaValida($corpo, (string) $request->query('signature', ''))) {
            Log::warning('kiwify.webhook.assinatura_invalida', ['tem_assinatura' => $request->query('signature', '') !== '', 'ip' => $request->ip()]);

            return response()->json(['ok' => false], 401);
        }

        $dados = json_decode($corpo, true);
        if (! is_array($dados)) {
            return response()->json(['ok' => false], 422);
        }
        // Alguns envios chegam embrulhados em {"order": {...}}.
        if (isset($dados['order']) && is_array($dados['order']) && ! isset($dados['order_id'])) {
            $dados = $dados['order'];
        }

        if (self::eCarrinhoAbandonado($dados)) {
            return $this->carrinho($dados);
        }

        $orderId = self::texto($dados['order_id'] ?? null, 64);
        $evento = self::texto($dados['webhook_event_type'] ?? null, 40) ?? ('status:'.self::texto($dados['order_status'] ?? null, 30));
        $status = self::EVENTOS[$evento] ?? self::STATUS[(string) ($dados['order_status'] ?? '')] ?? null;

        if ($orderId === null || $status === null) {
            // Evento que não medimos (assinatura etc.): aceita para a Kiwify não repetir.
            return response()->json(['ok' => true, 'ignorado' => true]);
        }

        // Marca do aviso e venda na mesma transação: se a gravação falhar, a
        // Kiwify reenvia e o aviso não fica marcado como já recebido.
        $novo = DB::transaction(function () use ($dados, $orderId, $evento, $status) {
            if (! self::primeiraVez($orderId, $evento)) {
                return false;
            }
            $venda = InfoprodutoVenda::query()->lockForUpdate()->firstOrNew(['kiwify_order_id' => $orderId]);
            $atual = $venda->exists ? (self::ORDEM[$venda->status] ?? 0) : 0;

            $venda->fill(self::camposDaVenda($dados, $venda));
            if (self::ORDEM[$status] >= $atual) {
                $venda->status = $status;
            }
            if ($status === InfoprodutoVenda::APROVADA && $venda->aprovada_em === null) {
                $venda->aprovada_em = self::data($dados['approved_date'] ?? null) ?? now();
            }
            if (in_array($status, [InfoprodutoVenda::REEMBOLSADA, InfoprodutoVenda::CHARGEBACK], true) && $venda->reembolsada_em === null) {
                $venda->reembolsada_em = self::data($dados['refunded_at'] ?? null) ?? now();
            }
            $venda->save();

            return true;
        });

        return response()->json($novo ? ['ok' => true] : ['ok' => true, 'repetido' => true]);
    }

    public static function assinaturaValida(string $corpo, string $assinatura): bool
    {
        if ($assinatura === '') {
            return false;
        }
        foreach ((array) config('infoprodutos.kiwify.tokens') as $token) {
            if ($token !== '' && hash_equals(hash_hmac('sha1', $corpo, (string) $token), strtolower($assinatura))) {
                return true;
            }
        }

        return false;
    }

    /** Grava a marca do aviso; false se ele já tinha chegado. */
    private static function primeiraVez(string $chave, string $evento): bool
    {
        try {
            DB::table('infoproduto_kiwify_avisos')->insert(['chave' => $chave, 'evento' => $evento, 'created_at' => now()]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** @param  array<string, mixed>  $d */
    private static function eCarrinhoAbandonado(array $d): bool
    {
        $evento = (string) ($d['webhook_event_type'] ?? '');

        return in_array($evento, ['abandoned_cart', 'carrinho_abandonado', 'cart_abandoned'], true)
            || (! isset($d['order_id']) && isset($d['checkout_link']))
            || (($d['status'] ?? null) === 'abandoned' && ! isset($d['order_id']));
    }

    /** @param  array<string, mixed>  $d */
    private function carrinho(array $d): JsonResponse
    {
        $id = self::texto($d['id'] ?? $d['cart_id'] ?? $d['checkout_id'] ?? null, 64);
        if ($id === null) {
            return response()->json(['ok' => true, 'ignorado' => true]);
        }
        if (! self::primeiraVez($id, 'carrinho_abandonado')) {
            return response()->json(['ok' => true, 'repetido' => true]);
        }

        $produtoId = self::texto($d['product_id'] ?? ($d['Product']['product_id'] ?? null), 64);
        $nome = self::texto($d['product_name'] ?? ($d['Product']['product_name'] ?? null), 200);
        [$produto] = ProdutoKiwify::classificar($produtoId, $nome);

        InfoprodutoCarrinho::query()->updateOrCreate(['kiwify_id' => $id], [
            'kiwify_produto_id' => $produtoId,
            'produto' => $produto,
            'pais' => self::pais($d['country'] ?? null),
            'criado_em' => self::data($d['created_at'] ?? null) ?? now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Só o necessário para o funil; nada do bloco Customer além do país.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private static function camposDaVenda(array $d, InfoprodutoVenda $venda): array
    {
        $produtoId = self::texto($d['Product']['product_id'] ?? $d['product_id'] ?? null, 64);
        $nome = self::texto($d['Product']['product_name'] ?? $d['product_name'] ?? null, 200);
        [$produto, $tipo] = ProdutoKiwify::classificar($produtoId, $nome);
        // Produto que não está no mapa, mas veio pendurado em outro pedido
        // (parent_order_id): é complemento daquele pedido.
        $pai = self::texto($d['parent_order_id'] ?? null, 64);
        if ($pai !== null && $tipo === 'outro') {
            $tipo = 'order_bump';
            $produto ??= InfoprodutoVenda::query()->where('kiwify_order_id', $pai)->value('produto');
        }
        $c = is_array($d['Commissions'] ?? null) ? $d['Commissions'] : [];
        $t = is_array($d['TrackingParameters'] ?? null) ? $d['TrackingParameters'] : [];

        $campos = [
            'kiwify_produto_id' => $produtoId,
            'kiwify_produto_nome' => $nome,
            'produto' => $produto,
            'tipo' => $tipo,
            'metodo' => self::texto($d['payment_method'] ?? null, 20),
            'valor' => self::centavos($c['charge_amount'] ?? $c['product_base_price'] ?? null),
            'moeda' => self::moeda($c['currency'] ?? $c['product_base_price_currency'] ?? null),
            'valor_liquido' => self::centavos($c['my_commission'] ?? $c['settlement_amount'] ?? null),
            'moeda_liquido' => self::moeda($c['settlement_amount_currency'] ?? $c['currency'] ?? null),
            'pais' => self::pais($d['Customer']['country'] ?? $d['country'] ?? null),
            'order_bumps' => self::contarBumps($d),
            'criada_em' => self::data($d['created_at'] ?? null),
        ];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'src', 'sck'] as $campo) {
            $campos[$campo] = self::texto($t[$campo] ?? null, 150);
        }

        // Aviso posterior sem algum campo não apaga o que o anterior trouxe.
        return array_filter($campos, fn ($v, $k) => $v !== null || ! $venda->exists || $venda->{$k} === null, ARRAY_FILTER_USE_BOTH);
    }

    /** @param  array<string, mixed>  $d */
    private static function contarBumps(array $d): int
    {
        foreach (['order_bumps', 'OrderBumps', 'bumps'] as $chave) {
            if (isset($d[$chave]) && is_array($d[$chave])) {
                return min(count($d[$chave]), 50);
            }
        }

        return 0;
    }

    private static function centavos(mixed $valor): ?float
    {
        if (is_int($valor) || (is_string($valor) && ctype_digit($valor))) {
            return round(((int) $valor) / 100, 2);
        }
        if (is_float($valor) || is_numeric($valor)) {
            return round((float) $valor, 2);
        }

        return null;
    }

    private static function moeda(mixed $valor): ?string
    {
        $m = strtoupper((string) $valor);

        return preg_match('/^[A-Z]{3}$/', $m) === 1 ? $m : null;
    }

    private static function pais(mixed $valor): ?string
    {
        $p = strtoupper(trim((string) $valor));

        return preg_match('/^[A-Z]{2}$/', $p) === 1 ? $p : null;
    }

    private static function texto(mixed $valor, int $limite): ?string
    {
        if (! is_string($valor) && ! is_int($valor)) {
            return null;
        }
        $t = trim((string) $valor);

        return $t === '' ? null : mb_substr($t, 0, $limite);
    }

    private static function data(mixed $valor): ?Carbon
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }
        try {
            return Carbon::parse($valor)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            Log::debug('Kiwify: data ilegível no webhook.');

            return null;
        }
    }
}
