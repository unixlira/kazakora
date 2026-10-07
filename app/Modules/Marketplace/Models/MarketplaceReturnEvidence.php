<?php

namespace App\Modules\Marketplace\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto ou vídeo anexado a uma devolução. O arquivo fica no disco privado
 * (tem rosto, endereço e produto do cliente) e só é servido pela rota
 * autenticada de DevolucoesController::evidencia().
 */
class MarketplaceReturnEvidence extends Model
{
    public const FOTO = 'foto';

    public const VIDEO = 'video';

    /** Limites pedidos pelo usuário em 2026-10-07. */
    public const MAX_MB = 30;

    public const MAX_SEGUNDOS = 60;

    protected $table = 'marketplace_return_evidences';

    protected $fillable = ['marketplace_return_id', 'tipo', 'path', 'nome_original', 'mime', 'tamanho', 'duracao_segundos', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
