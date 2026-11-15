<?php

namespace App\Modules\Analytics\Support;

use App\Modules\Analytics\Models\CheckoutEvento;

/** Internal analytics only: no marketing dispatch, arbitrary strings or form values. */
final class JornadaSnapshot
{
    public const EVENTOS = ['pagina_vista', 'produto_visto', 'variante_escolhida', 'carrinho_adicionar', 'carrinho_remover', 'carrinho_quantidade', 'carrinho_visto', 'checkout_inicio', 'frete_cotado', 'cupom_aceito', 'cupom_recusado', 'preco_exibido', 'pagamento_intencao'];

    public static function valido(array $e): bool
    {
        if (array_diff(array_keys($e), ['evento', 'jornada']) !== [] || ! in_array($e['evento'] ?? null, self::EVENTOS, true)) {
            return false;
        }
        $j = $e['jornada'] ?? null;
        if (! is_array($j) || array_diff(array_keys($j), ['id', 'sessao', 'estado', 'motivo', 'antes', 'depois']) !== []) {
            return false;
        }
        foreach (['id', 'sessao'] as $key) {
            if (! is_string($j[$key] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $j[$key]) !== 1) {
                return false;
            }
        }
        return ($e['evento'] !== 'pagamento_intencao' || (($j['estado'] ?? null) === 'intent' && in_array($j['motivo'] ?? null, ['pix', 'cartao', 'boleto'], true)))
            && in_array($j['estado'] ?? null, ['intent', 'observado'], true)
            && in_array($j['motivo'] ?? null, ['quantidade', 'adicao', 'remocao', 'variante', 'frete', 'cupom', 'navegacao', 'desconhecido', 'pix', 'cartao', 'boleto'], true)
            && self::preco($j['antes'] ?? null) && self::preco($j['depois'] ?? null);
    }

    private static function preco(mixed $p): bool
    {
        if ($p === null) {
            return true;
        }
        $keys = ['currency', 'subtotal', 'discount', 'shipping', 'interest', 'total', 'items'];
        if (! is_array($p) || array_diff(array_keys($p), $keys) !== [] || array_diff($keys, array_keys($p)) !== [] || $p['currency'] !== 'BRL') {
            return false;
        }
        foreach (['subtotal', 'discount', 'shipping', 'interest', 'total'] as $key) {
            // Unknown is not free. Browser snapshots never certify server totals.
            if ($p[$key] === null) {
                continue;
            }
            if (! is_int($p[$key]) || $p[$key] < 0 || $p[$key] > 100000000) {
                return false;
            }
        }
        if (! is_array($p['items']) || ! array_is_list($p['items']) || count($p['items']) > 20) {
            return false;
        }
        foreach ($p['items'] as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['product_id', 'quantity', 'unit_price']) !== []) {
                return false;
            }
            foreach (['product_id' => [1, 2147483647], 'quantity' => [0, 999], 'unit_price' => [0, 100000000]] as $key => [$min, $max]) {
                if (! is_int($item[$key] ?? null) || $item[$key] < $min || $item[$key] > $max) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Append-only on retries, including conflicting payloads. Unique index handles concurrency. */
    public static function gravar(array $base, array $evento): void
    {
        if (! self::habilitada()) {
            return;
        }
        rescue(function () use ($base, $evento) {
            $j = $evento['jornada'];
            $ambiente = (string) config('app.env');
            $key = hash('sha256', json_encode([$base['site'] ?? 'kazakora', $ambiente, $j['sessao'], $j['id']]));
            CheckoutEvento::query()->createOrFirst(['jornada_chave' => $key], [
                ...$base, 'evento' => $evento['evento'], 'jornada' => [...$j, 'ambiente' => $ambiente],
            ]);
        }, null, false);
    }

    public static function habilitada(): bool
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        return app()->environment('testing', 'local') || str_ends_with($host, '.devlira.com.br') || $host === 'devlira.com.br';
    }
}