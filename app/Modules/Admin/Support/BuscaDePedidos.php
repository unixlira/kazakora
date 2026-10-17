<?php

namespace App\Modules\Admin\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Busca da tela Pedidos do admin (2026-10-16, pedido do Lira: "consertar o
 * campo de busca").
 *
 * O que o operador digita e onde cada coisa é procurada:
 *  - "#274": só o pedido 274 (o # deixa claro que é o número interno).
 *  - "274" (até 5 dígitos): só pedido 274 e NF-e número 274, exatos. De 6
 *    a 9 dígitos, também pedido/NF exatos, mais pedaço de número de
 *    marketplace, telefone, CPF/CNPJ, rastreio e chave da NF-e (sem
 *    pontuação dos dois lados).
 *  - número de marketplace (Shopee 261016PWVAA8JX, ML 2000019008909826 ou o
 *    pack do ML, TikTok, Amazon 701-1167157-6796210);
 *  - nome do cliente/destinatário/apelido do ML (o banco é utf8mb4_unicode_ci:
 *    ignora acento e maiúscula), e-mail;
 *  - CPF/CNPJ e telefone com ou sem pontuação (no banco há os dois jeitos:
 *    "(11) 98765-4321", "11987654321", "123.456.789-09");
 *  - código de rastreio (canal ou pré-postagem dos Correios);
 *  - SKU ou nome do produto;
 *  - data (13/08/2026, 13/08, 2026-08-13).
 *
 * Antes, qualquer número dentro do texto (ex.: "joao123@gmail.com",
 * "261016PWVAA8JX") virava busca por pedido 123 / telefone com "123" / todo
 * pedido da Shopee do dia 16/10, trazendo dezenas de pedidos errados.
 */
class BuscaDePedidos
{
    /** Pontuação comum em CPF, CNPJ e telefone. */
    private const PONTUACAO = ['.', '-', '/', '(', ')', ' ', '+'];

    public static function normalizar(?string $termo): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $termo) ?? '');
    }

    public static function aplicar(Builder $query, ?string $termo): Builder
    {
        $termo = self::normalizar($termo);
        if ($termo === '') {
            return $query;
        }

        // "#274" ou "# 274": número interno do pedido, exato.
        if (preg_match('/^#\s*(\d{1,9})$/', $termo, $m)) {
            return $query->where($query->qualifyColumn('id'), (int) $m[1]);
        }

        $termo = ltrim($termo, '#');
        $like = '%'.$termo.'%';

        // Só número com pontuação (CPF, telefone, número de pedido): sem
        // letra e sem @. Texto com letra não vira busca por dígitos.
        // Data ("13/08") também não vira busca por dígitos (seria o pedido 1308).
        $data = self::data($termo);
        $soNumero = ! $data && preg_match('/^[\d\s.\-\/()+]+$/', $termo);
        $digitos = $soNumero ? preg_replace('/\D+/', '', $termo) : '';

        if ($digitos !== '' && strlen($digitos) <= 9) {
            $query->orderByRaw('CASE WHEN '.$query->qualifyColumn('id').' = ? THEN 0 ELSE 1 END', [(int) $digitos]);
        }

        // Número curto ("274", "12-3"): só pedido e NF-e exatos. Pedaço de
        // número curto casava com dezenas de pedidos de marketplace/telefone.
        if ($digitos !== '' && strlen($digitos) < 6) {
            return $query->where(fn (Builder $q) => $q
                ->where('id', (int) $digitos)
                ->orWhere('external_order_id', $termo)
                ->orWhereHas('invoice', fn (Builder $i) => $i->where('numero', (int) $digitos)));
        }

        return $query->where(function (Builder $q) use ($termo, $like, $digitos, $data) {
            $q->where('external_order_id', 'like', $like)
                ->orWhere('channel_pack_id', 'like', $like)
                ->orWhere('shipping_name', 'like', $like)
                ->orWhere('shipping_recipient_name', 'like', $like)
                ->orWhere('channel_buyer_nickname', 'like', $like)
                ->orWhere('shipping_email', 'like', $like)
                ->orWhere('shipping_phone', 'like', $like)
                ->orWhere('buyer_document', 'like', $like)
                ->orWhereHas('user', fn (Builder $u) => $u->withTrashed()->where(fn (Builder $w) => $w
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)))
                ->orWhereHas('invoice', fn (Builder $i) => $i->where('chave_acesso', 'like', $like))
                ->orWhereHas('channelShipment', fn (Builder $s) => $s->where('tracking_code', 'like', $like))
                ->orWhereHas('correiosPrePostagens', fn (Builder $c) => $c->where('codigo_objeto', 'like', $like));

            if (mb_strlen($termo) >= 3) {
                $q->orWhereHas('items', fn (Builder $item) => $item->where(fn (Builder $w) => $w
                    ->where('product_name', 'like', $like)
                    ->orWhereHas('product', fn (Builder $p) => $p->withTrashed()->where('sku', 'like', $like))));
            }

            if ($digitos !== '') {
                if (strlen($digitos) <= 9) {
                    $q->orWhere('id', (int) $digitos)
                        ->orWhereHas('invoice', fn (Builder $i) => $i->where('numero', (int) $digitos));
                }

                // Daqui para baixo são 6 dígitos ou mais (o caso curto já saiu).
                if (strlen($digitos) >= 6) {
                    $digLike = '%'.$digitos.'%';
                    $q->orWhereRaw(self::semPontuacao('external_order_id').' like ?', [$digLike])
                        ->orWhere('channel_pack_id', 'like', $digLike)
                        ->orWhereRaw(self::semPontuacao('buyer_document').' like ?', [$digLike])
                        ->orWhereRaw(self::semPontuacao('shipping_phone').' like ?', [$digLike])
                        ->orWhereHas('invoice', fn (Builder $i) => $i->where('chave_acesso', 'like', $digLike))
                        ->orWhereHas('channelShipment', fn (Builder $s) => $s->where('tracking_code', 'like', $digLike));

                    // Telefone digitado com DDI 55 e gravado sem ele.
                    if (strlen($digitos) >= 12 && str_starts_with($digitos, '55')) {
                        $q->orWhereRaw(self::semPontuacao('shipping_phone').' like ?', ['%'.substr($digitos, 2).'%']);
                    }
                }
            }

            if ($data) {
                $q->orWhereDate('created_at', $data->toDateString());
            }
        });
    }

    /** REPLACE encadeado: funciona no MariaDB e no SQLite dos testes. */
    private static function semPontuacao(string $coluna): string
    {
        $sql = $coluna;
        foreach (self::PONTUACAO as $char) {
            $sql = "REPLACE({$sql}, '{$char}', '')";
        }

        return $sql;
    }

    public static function data(string $valor): ?Carbon
    {
        $valor = trim($valor);

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $formato) {
            try {
                $data = Carbon::createFromFormat($formato, $valor);
                if ($data && $data->format($formato) === $valor) {
                    return $data;
                }
            } catch (Throwable) {
                // tenta o próximo formato
            }
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $valor, $m) && checkdate((int) $m[2], (int) $m[1], now()->year)) {
            return Carbon::createFromDate(now()->year, (int) $m[2], (int) $m[1]);
        }

        return null;
    }
}
