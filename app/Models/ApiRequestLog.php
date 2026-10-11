<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha por request em /api/v1/* — ver a migração pro porquê disso
 * existir separado do AuditLog normal. Só de leitura pelo resto da
 * aplicação (gravado por App\Http\Middleware\LogApiRequest); sem
 * $fillable/mass-assignment de propósito, sempre criado via create()
 * explícito de dentro do middleware.
 */
class ApiRequestLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function apiPartner(): BelongsTo
    {
        return $this->belongsTo(ApiPartner::class);
    }
}
