<?php

namespace App\Modules\Newsletter\Models;

use Illuminate\Database\Eloquent\Model;

/** Um disparo do resumo do blog. A `referencia` única impede envio repetido. */
class NewsletterEnvio extends Model
{
    protected $table = 'newsletter_envios';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['post_ids' => 'array'];
    }
}
