<?php

namespace App\Modules\Checkout\Support;

use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Quais caixas o KoraSync grava em vídeo (pedido do Lira 2026-10-13, tela
 * Vídeos de embalagem). Modo "nenhum" deixa a câmera desligada, "todos" grava
 * toda caixa e "selecionados" só a que tem pelo menos um item de uma
 * categoria ou produto marcado (variação conta pelo produto pai também).
 */
class RegraVideoEmbalagem
{
    public const MODO_NENHUM = 'nenhum';

    public const MODO_TODOS = 'todos';

    public const MODO_SELECIONADOS = 'selecionados';

    public const MODOS = [self::MODO_NENHUM, self::MODO_TODOS, self::MODO_SELECIONADOS];

    private const CHAVE_MODO = 'video_embalagem.modo';

    private const CHAVE_CATEGORIAS = 'video_embalagem.categorias';

    private const CHAVE_PRODUTOS = 'video_embalagem.produtos';

    /** @return array{modo: string, categorias: list<int>, produtos: list<int>} */
    public function atual(): array
    {
        $modo = Setting::get(self::CHAVE_MODO, self::MODO_NENHUM);

        return [
            'modo' => in_array($modo, self::MODOS, true) ? $modo : self::MODO_NENHUM,
            'categorias' => $this->ids(Setting::get(self::CHAVE_CATEGORIAS)),
            'produtos' => $this->ids(Setting::get(self::CHAVE_PRODUTOS)),
        ];
    }

    /** @param  list<int>  $categorias  @param  list<int>  $produtos */
    public function salvar(string $modo, array $categorias, array $produtos): void
    {
        Setting::set(self::CHAVE_MODO, $modo);
        Setting::set(self::CHAVE_CATEGORIAS, json_encode(array_values(array_unique(array_map('intval', $categorias)))));
        Setting::set(self::CHAVE_PRODUTOS, json_encode(array_values(array_unique(array_map('intval', $produtos)))));
    }

    /**
     * Itens da caixa com `product` carregado (category_id e parent_product_id).
     * Uma caixa com vários pedidos (carrinho do ML) grava se qualquer item bater.
     */
    public function deveGravar(Collection $itens, ?array $regra = null): bool
    {
        $regra ??= $this->atual();

        return match ($regra['modo']) {
            self::MODO_TODOS => true,
            self::MODO_SELECIONADOS => $itens->contains(fn ($item) => in_array($item->product_id, $regra['produtos'], true)
                || in_array($item->product?->parent_product_id, $regra['produtos'], true)
                || in_array($item->product?->category_id, $regra['categorias'], true)),
            default => false,
        };
    }

    /** @return list<int> */
    private function ids(?string $json): array
    {
        $lista = json_decode((string) $json, true);

        return is_array($lista) ? array_values(array_map('intval', $lista)) : [];
    }
}
