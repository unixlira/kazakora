<?php

namespace App\Modules\Admin\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Marketing › Validação de criativos (2026-10-21): vídeo de anúncio enviado
 * pela equipe para o Lira validar antes de subir na Meta, TikTok, Google etc.
 * Desde 2026-10-21 aceita também imagem (JPG/PNG), e o aprovado pode virar
 * anúncio do Meta ADS direto daqui ("Dentro da plataforma"), sem subir de novo.
 * O arquivo fica no disco local (privado) em criativos/AAAA-MM/. Mover para a
 * lixeira não apaga o arquivo (SoftDeletes) e dá para restaurar.
 */
class CriativoValidacao extends Model
{
    use SoftDeletes;

    protected $table = 'criativos_validacao';

    public const AGUARDANDO = 'aguardando';

    public const APROVADO = 'aprovado';

    public const AJUSTAR = 'ajustar';

    public const REPROVADO = 'reprovado';

    public const STATUS = [self::AGUARDANDO, self::APROVADO, self::AJUSTAR, self::REPROVADO];

    public const PLATAFORMAS = [
        'meta' => 'Meta (Facebook e Instagram)',
        'tiktok' => 'TikTok',
        'google' => 'Google e YouTube',
        'shopee' => 'Shopee',
        'mercado_livre' => 'Mercado Livre',
        'outra' => 'Outra',
    ];

    public const EXTENSOES = ['mp4', 'mov', 'm4v', 'webm'];

    public const EXTENSOES_IMAGEM = ['jpg', 'jpeg', 'png'];

    public const MAX_MB = 1024;

    /** A Meta recusa imagem acima de 30 MB (adimages). */
    public const MAX_MB_IMAGEM = 30;

    protected $fillable = [
        'titulo',
        'identificacao',
        'plataforma',
        'produto_id',
        'observacao',
        'nome_original',
        'path',
        'tamanho',
        'duracao_segundos',
        'largura',
        'altura',
        'status',
        'comentario_avaliacao',
        'enviado_por',
        'avaliado_por',
        'avaliado_em',
    ];

    protected $attributes = [
        'status' => self::AGUARDANDO,
    ];

    protected function casts(): array
    {
        return [
            'tamanho' => 'integer',
            'duracao_segundos' => 'decimal:2',
            'largura' => 'integer',
            'altura' => 'integer',
            'avaliado_em' => 'datetime',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produto_id');
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function avaliadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avaliado_por');
    }

    public static function extensaoEhImagem(string $extensao): bool
    {
        return in_array(strtolower($extensao), self::EXTENSOES_IMAGEM, true);
    }

    public function ehImagem(): bool
    {
        return self::extensaoEhImagem(pathinfo((string) $this->path, PATHINFO_EXTENSION));
    }

    /** Formato para a tela: 9:16, 1:1, 16:9... ou null sem medida. */
    public function proporcao(): ?string
    {
        if (! $this->largura || ! $this->altura) {
            return null;
        }

        $razao = $this->largura / $this->altura;
        foreach (['9:16' => 9 / 16, '4:5' => 4 / 5, '1:1' => 1.0, '16:9' => 16 / 9] as $nome => $valor) {
            if (abs($razao - $valor) < 0.03) {
                return $nome;
            }
        }

        return "{$this->largura}x{$this->altura}";
    }

    public function paraTela(): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'identificacao' => $this->identificacao,
            'plataforma' => $this->plataforma,
            'produto' => $this->produto ? ['id' => $this->produto->id, 'nome' => $this->produto->name] : null,
            'observacao' => $this->observacao,
            'tipo' => $this->ehImagem() ? 'imagem' : 'video',
            'nome_original' => $this->nome_original,
            'tamanho_mb' => round($this->tamanho / 1048576, 1),
            'duracao' => $this->duracao_segundos !== null ? (int) round((float) $this->duracao_segundos) : null,
            'proporcao' => $this->proporcao(),
            'status' => $this->status,
            'comentario_avaliacao' => $this->comentario_avaliacao,
            'enviado_por' => $this->enviadoPor?->name,
            'enviado_em' => $this->created_at?->toIso8601String(),
            'avaliado_por' => $this->avaliadoPor?->name,
            'avaliado_em' => $this->avaliado_em?->toIso8601String(),
            'na_lixeira' => $this->trashed(),
            'url' => "/admin/validacao-criativos/{$this->id}/arquivo",
            'download' => "/admin/validacao-criativos/{$this->id}/arquivo?baixar=1",
        ];
    }
}
