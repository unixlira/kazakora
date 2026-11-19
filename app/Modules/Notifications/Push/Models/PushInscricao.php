<?php

namespace App\Modules\Notifications\Push\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um aparelho (navegador ou app da tela inicial) inscrito para receber o
 * aviso de venda de um dos apps (kazakora/ferrakora). O endpoint é a URL do
 * serviço de push (Apple, Google, Mozilla) e é único: o mesmo aparelho que
 * se inscreve de novo só atualiza a linha.
 */
class PushInscricao extends Model
{
    protected $table = 'push_inscricoes';

    protected $fillable = [
        'user_id',
        'app',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
        'user_agent',
        'ultima_vez_em',
        'ultimo_envio_em',
        'falhas',
    ];

    protected $hidden = ['p256dh', 'auth'];

    protected function casts(): array
    {
        return [
            'ultima_vez_em' => 'datetime',
            'ultimo_envio_em' => 'datetime',
            'falhas' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashDoEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** @return array{endpoint: string, p256dh: string, auth: string} */
    public function paraEnvio(): array
    {
        return ['endpoint' => $this->endpoint, 'p256dh' => $this->p256dh, 'auth' => $this->auth];
    }
}
