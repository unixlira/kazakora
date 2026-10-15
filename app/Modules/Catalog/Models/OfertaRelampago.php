<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Support\SiteAtual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Oferta Relâmpago (pedido 2026-10-14): X% de desconto num produto pai, de
 * inicio_em até fim_em (America/Sao_Paulo), só no site escolhido. Vale para
 * todas as variações ativas do grupo, sobre o preço do anúncio sem frete
 * (precoSemOferta()); com Oferta do Dia junto vale o menor. Não acumula com
 * desconto por quantidade nem com cupom. Marketplaces (ProductChannelListing)
 * continuam no preço sem oferta.
 *
 * @property int $id
 * @property string $site
 * @property int $product_id
 * @property float $desconto_pct
 * @property Carbon $inicio_em
 * @property Carbon $fim_em
 * @property bool $ativo
 */
class OfertaRelampago extends Model
{
    /** Duração quando o Lira só escolhe o início (pedido 2026-10-15). */
    public const DURACAO_PADRAO_HORAS = 24;

    public const DURACAO_MAXIMA_HORAS = 168;

    protected $table = 'ofertas_relampago';

    protected $fillable = ['site', 'product_id', 'desconto_pct', 'inicio_em', 'fim_em', 'ativo', 'user_id'];

    protected function casts(): array
    {
        return [
            'desconto_pct' => 'float',
            'inicio_em' => 'datetime',
            'fim_em' => 'datetime',
            'ativo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Agendou, pausou ou apagou: a próxima leitura já vê (sem cache longo).
        static::saved(fn () => static::esquecer());
        static::deleted(fn () => static::esquecer());
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ofertas com horário cruzando [inicio, fim) do mesmo produto e site. */
    public function scopeSobrepostas(Builder $query, string $site, int $productId, Carbon $inicio, Carbon $fim, ?int $ignorar = null): Builder
    {
        return $query->where('site', $site)
            ->where('product_id', $productId)
            ->where('inicio_em', '<', $fim)
            ->where('fim_em', '>', $inicio)
            ->when($ignorar, fn (Builder $q) => $q->whereKeyNot($ignorar));
    }

    public function noAr(?Carbon $agora = null): bool
    {
        $agora ??= now();

        return $this->ativo && $this->inicio_em->lte($agora) && $this->fim_em->gt($agora);
    }

    /** agendada | no_ar | encerrada | pausada */
    public function situacao(?Carbon $agora = null): string
    {
        $agora ??= now();

        return match (true) {
            $this->fim_em->lte($agora) => 'encerrada',
            ! $this->ativo => 'pausada',
            $this->inicio_em->gt($agora) => 'agendada',
            default => 'no_ar',
        };
    }

    /** Preço do anúncio (sem frete) com o desconto desta oferta. */
    public function precoPara(Product $produto): float
    {
        return max(0, round($produto->precoSemOferta() * (1 - $this->desconto_pct / 100), 2));
    }

    /** Formato da API dos sites (contrato 2026-10-14). */
    public function paraApi(): array
    {
        $pct = $this->desconto_pct;

        return [
            'id' => $this->id,
            'inicio' => $this->inicio_em->toIso8601String(),
            'fim' => $this->fim_em->toIso8601String(),
            'desconto_pct' => floor($pct) == $pct ? (int) $pct : $pct,
        ];
    }

    /**
     * product_id (pai) => oferta no ar AGORA no site (null = site da
     * requisição, SiteAtual). Sem site, nenhuma.
     *
     * As ofertas que ainda não acabaram ficam guardadas no container (uma
     * consulta por requisição/job); o "agora" é conferido a cada chamada,
     * então agendada vira no ar na hora.
     *
     * @return array<int, self>
     */
    public static function ativasAgora(?string $site = null): array
    {
        $site ??= app(SiteAtual::class)->atual();

        if ($site === null) {
            return [];
        }

        $agora = now();

        return self::vigentes($site)
            ->filter(fn (self $oferta) => $oferta->inicio_em->lte($agora) && $oferta->fim_em->gt($agora))
            ->keyBy('product_id')
            ->all();
    }

    /** @return Collection<int, self> ativas do site que ainda não acabaram, por início */
    private static function vigentes(string $site): Collection
    {
        $chave = 'ofertas-relampago.'.$site;

        if (! app()->bound($chave)) {
            // rescue: no deploy o código chega antes da migration; sem a tabela, sem oferta.
            app()->instance($chave, rescue(fn () => static::query()
                ->where('site', $site)
                ->where('ativo', true)
                ->where('fim_em', '>', now())
                ->orderBy('inicio_em')
                ->get(), collect(), false));
        }

        return app($chave);
    }

    public static function esquecer(): void
    {
        foreach (array_keys(SiteAtual::SITES) as $site) {
            app()->forgetInstance('ofertas-relampago.'.$site);
        }
    }
}
