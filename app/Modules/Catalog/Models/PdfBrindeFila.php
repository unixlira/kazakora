<?php

namespace App\Modules\Catalog\Models;

use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um PDF de brinde a criar para um produto (fila de 20/11/2026). O Homer pega
 * a próxima `fila`, marca `produzindo`, entrega o PDF (vira um LivroDigital
 * desativado ligado ao produto) e a linha fica em `revisao`. O Lira aprova no
 * admin (o livro passa a aparecer na página) ou devolve para a fila com uma
 * observação do que mudar.
 *
 * @property int $id
 * @property int $product_id
 * @property string $tipo
 * @property string $titulo
 * @property ?string $briefing
 * @property int $paginas
 * @property string $status
 * @property int $prioridade
 * @property ?int $livro_digital_id
 * @property ?string $observacao
 */
class PdfBrindeFila extends Model
{
    use Auditable;

    protected $table = 'pdf_brinde_fila';

    public const TIPOS = [
        'historia' => 'História infantil',
        'colorir' => 'Livro de colorir',
        'guia' => 'Guia prático',
        'receitas' => 'Livro de receitas',
    ];

    public const STATUS = [
        'fila' => 'Na fila',
        'produzindo' => 'Produzindo',
        'revisao' => 'Para revisar',
        'aprovado' => 'Aprovado',
        'erro' => 'Deu erro',
    ];

    /** Quem ficou "produzindo" mais que isto volta a ser entregue (o Homer caiu no meio). */
    public const MINUTOS_PRESO = 180;

    protected $fillable = ['product_id', 'tipo', 'titulo', 'briefing', 'paginas', 'status', 'prioridade', 'livro_digital_id', 'observacao', 'pego_em'];

    protected function casts(): array
    {
        return [
            'paginas' => 'integer',
            'prioridade' => 'integer',
            'pego_em' => 'datetime',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function livro(): BelongsTo
    {
        return $this->belongsTo(LivroDigital::class, 'livro_digital_id');
    }

    /** @return array<string, mixed> */
    public function resumo(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'tipo' => $this->tipo,
            'tipo_nome' => self::TIPOS[$this->tipo] ?? $this->tipo,
            'titulo' => $this->titulo,
            'briefing' => $this->briefing,
            'paginas' => $this->paginas,
            'status' => $this->status,
            'status_nome' => self::STATUS[$this->status] ?? $this->status,
            'prioridade' => $this->prioridade,
            'observacao' => $this->observacao,
            'livro' => $this->livro ? [
                'id' => $this->livro->id,
                'titulo' => $this->livro->titulo,
                'capa_url' => $this->livro->capa_url,
                'ativo' => $this->livro->ativo,
            ] : null,
            'atualizado' => $this->updated_at?->format('d/m H:i'),
        ];
    }
}
