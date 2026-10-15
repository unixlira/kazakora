<?php

namespace App\Support;

use Closure;

/**
 * Qual site está vendendo nesta requisição (pedido 2026-10-14, Ofertas
 * Relâmpago por empresa): 'kazakora' nas telas da loja (mesmo lugar onde o
 * FreteEmbutido liga, middleware AtivarFreteEmbutido), 'ferrakora' e
 * 'beautykora' nas rotas /api/{site}/* (middleware DefinirSiteAtual, e o
 * catálogo e o checkout de cada site definem de novo por garantia). Admin,
 * marketplaces, filas e comandos ficam sem site: nada de relâmpago, igual
 * ao frete embutido que é 0 fora da loja.
 *
 * Singleton por requisição (scoped, AppServiceProvider).
 */
class SiteAtual
{
    public const KAZAKORA = 'kazakora';

    public const FERRAKORA = 'ferrakora';

    public const BEAUTYKORA = 'beautykora';

    /** site => nome mostrado no admin */
    public const SITES = [
        self::KAZAKORA => 'KazaKora',
        self::FERRAKORA => 'FerraKora',
        self::BEAUTYKORA => 'Beauty Kora',
    ];

    private ?string $site = null;

    public function definir(?string $site): void
    {
        $this->site = isset(self::SITES[$site ?? '']) ? $site : null;
    }

    public function atual(): ?string
    {
        return $this->site;
    }

    /**
     * Roda $callback com outro site e volta ao anterior (catálogo de um site
     * montado fora da rota dele).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function durante(?string $site, Closure $callback): mixed
    {
        $anterior = $this->site;
        $this->definir($site);

        try {
            return $callback();
        } finally {
            $this->site = $anterior;
        }
    }
}
