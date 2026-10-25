<?php

namespace App\Modules\Checkout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Categoria de tamanho do pacote (pedido 2026-10-24): Pequena, Média, Grande
 * e Extra Grande, pelo peso tarifável e pelo maior lado. A regra de frete da
 * cidade pode ser diferente para cada uma. A última (sem limite) fica com o
 * que não cabe nas outras, como a escada de 7 m.
 */
class FreteCategoriaPacote extends Model
{
    public const PEQUENA = 'pequena';

    public const MEDIA = 'media';

    public const GRANDE = 'grande';

    public const EXTRA_GRANDE = 'extra_grande';

    private const CACHE = 'frete-categorias-pacote';

    protected $table = 'frete_categorias_pacote';

    protected $fillable = ['peso_max_g', 'lado_max_cm'];

    protected function casts(): array
    {
        return ['ordem' => 'integer', 'peso_max_g' => 'integer', 'lado_max_cm' => 'integer'];
    }

    public static function esquecer(): void
    {
        Cache::forget(self::CACHE);
    }

    /**
     * Categorias em ordem, da menor para a maior.
     *
     * @return list<array{chave: string, nome: string, peso_max_g: ?int, lado_max_cm: ?int}>
     */
    public static function todas(): array
    {
        return rescue(fn () => Cache::rememberForever(self::CACHE, fn () => self::query()
            ->orderBy('ordem')
            ->get()
            ->map(fn (self $c) => $c->only('chave', 'nome', 'peso_max_g', 'lado_max_cm'))
            ->all()), [], false);
    }

    /** Chave da menor categoria que comporta o peso (g) e o maior lado (cm). */
    public static function classificar(int $pesoG, float $ladoCm): ?string
    {
        $todas = self::todas();
        foreach ($todas as $c) {
            if (($c['peso_max_g'] === null || $pesoG <= $c['peso_max_g']) && ($c['lado_max_cm'] === null || $ladoCm <= $c['lado_max_cm'])) {
                return $c['chave'];
            }
        }

        return $todas === [] ? null : end($todas)['chave'];
    }

    /** Posição da categoria (0 = menor); categoria desconhecida = -1. */
    public static function posicao(?string $chave): int
    {
        $i = array_search($chave, array_column(self::todas(), 'chave'), true);

        return $i === false ? -1 : $i;
    }
}
