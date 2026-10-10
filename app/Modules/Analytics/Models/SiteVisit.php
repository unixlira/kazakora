<?php

namespace App\Modules\Analytics\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteVisit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'visitor_id',
        'user_id',
        'path',
        'referer',
        'user_agent',
        'ip',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'dispositivo',
        'consentiu',
    ];

    protected function casts(): array
    {
        return ['consentiu' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
