<?php

namespace App\Modules\Admin\Models;

use App\Modules\Admin\Support\TermometroOportunidade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Oferta de infoproduto em PDF vista na Biblioteca de Anúncios da Meta
 * (Marketing > Oportunidades em PDF). Vem do importador
 * `pdf-oportunidades:importar`; não é editada pela tela.
 */
class PdfOferta extends Model
{
    protected $table = 'pdf_ofertas';

    protected $guarded = ['id'];

    protected $casts = [
        'ativa' => 'boolean',
        'paises' => 'array',
        'preco' => 'decimal:2',
        'pontuacao' => 'decimal:2',
        'inicio_mais_antigo' => 'date',
        'coletado_em' => 'datetime',
    ];

    public function anuncios(): HasMany
    {
        return $this->hasMany(PdfOfertaAnuncio::class, 'pdf_oferta_id');
    }

    /**
     * Termômetro (mesma métrica das Oportunidades › KazaKora), calculado na
     * hora a partir do que já está gravado: dias = dias_rodando, variações =
     * variacoes, criativos novos = anúncios com iniciado_em nos 7 dias
     * anteriores à coleta. A Biblioteca não dá alcance fora da UE nem quantos
     * anunciantes vendem o mesmo PDF: alcance = null e anunciantes = 1, então
     * o teto prático é 87,5 (os 10% de anunciantes ficam zerados).
     * Precisa de `anuncios` carregado (basta id, pdf_oferta_id, iniciado_em).
     *
     * @return array{pontuacao: float, fase: string, novos7d: int}
     */
    public function termometro(): array
    {
        return self::medirTermometro(
            (int) $this->dias_rodando,
            (int) $this->variacoes,
            self::contarNovos7d($this->anuncios->pluck('iniciado_em'), $this->coletado_em),
        );
    }

    /** @return array{pontuacao: float, fase: string, novos7d: int} */
    public static function medirTermometro(int $dias, int $variacoes, int $novos7d): array
    {
        return TermometroOportunidade::medir($dias, $variacoes, null, $novos7d, 1) + ['novos7d' => $novos7d];
    }

    /**
     * Anúncios que começaram nos 7 dias anteriores à coleta (mesma janela do
     * importador das Oportunidades › KazaKora).
     *
     * @param  iterable<\DateTimeInterface|string|null>  $inicios
     */
    public static function contarNovos7d(iterable $inicios, \DateTimeInterface|string|null $coletadoEm): int
    {
        $referencia = $coletadoEm ? Carbon::parse($coletadoEm) : now();
        $limite = $referencia->copy()->subDays(7)->startOfDay();
        $novos = 0;
        foreach ($inicios as $inicio) {
            if ($inicio && Carbon::parse($inicio)->betweenIncluded($limite, $referencia)) {
                $novos++;
            }
        }

        return $novos;
    }

    /** O clone desta oferta em PDFs Escolhidos, se já foi clonada. */
    public function escolhido(): HasOne
    {
        return $this->hasOne(PdfEscolhido::class, 'pdf_oferta_id');
    }
}
