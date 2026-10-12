<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de uma chamada real à API do Kalodata (ver KalodataService).
 */
class KalodataChamada extends Model
{
    protected $table = 'kalodata_chamadas';

    protected $fillable = [
        'endpoint',
        'corpo',
        'sucesso',
        'http_status',
        'codigo',
        'mensagem',
        'duracao_ms',
    ];

    protected function casts(): array
    {
        return [
            'sucesso' => 'boolean',
            'http_status' => 'integer',
            'duracao_ms' => 'integer',
        ];
    }

    /**
     * Chamadas reais feitas hoje (fuso da aplicação, America/Sao_Paulo).
     */
    public static function deHoje(): int
    {
        return static::query()->where('created_at', '>=', now()->startOfDay())->count();
    }
}
