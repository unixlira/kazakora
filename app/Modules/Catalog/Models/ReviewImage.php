<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\ProductImageThumbnailer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewImage extends Model
{
    protected $fillable = [
        'review_id',
        'image_url',
        'thumb_url',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** Foto nova de avaliação já nasce com a miniatura (tarefa 3878, 06/11). */
    protected static function booted(): void
    {
        static::creating(function (self $image) {
            if ($image->thumb_url === null) {
                $image->thumb_url = self::gerarMiniatura($image->image_url);
            }
        });
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /**
     * Miniatura de 320px em `<pasta>/minis/` ao lado da foto guardada na loja
     * (`.../storage/avaliacoes/...`). A original fica intacta: é ela que abre
     * ampliada. Vídeo, foto de fora da loja ou falha devolvem null, e a tela
     * segue com a original.
     */
    public static function gerarMiniatura(?string $url): ?string
    {
        if (! $url || preg_match('/\.(mp4|mov|webm|m4v)(\?|$)/i', $url)) {
            return null;
        }

        $posicao = strpos($url, '/storage/');
        if ($posicao === false) {
            return null;
        }

        $caminho = rawurldecode(strtok(substr($url, $posicao + strlen('/storage/')), '?'));
        if ($caminho === '' || str_contains($caminho, '..')) {
            return null;
        }

        $miniatura = ProductImageThumbnailer::generateMini($caminho);

        return $miniatura ? substr($url, 0, $posicao).'/storage/'.$miniatura : null;
    }
}
