<?php

namespace App\Modules\WhatsApp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppCampaign extends Model
{
    use HasFactory;

    // Prefixo deliberadamente "whatsapp_*" para bater com migrations/produção;
    // o pluralizador padrão tentaria "whats_app_campaigns".
    protected $table = 'whatsapp_campaigns';

    protected $fillable = [
        'name',
        'mode',
        'status',
        'template_name',
        'template_language',
        'message_body',
        'media_type',
        'media_path',
        'media_original_name',
        'media_mime',
        'whatsapp_media_id',
        'total_recipients',
        'sent_count',
        'failed_count',
        'dry_run',
        'created_by',
        'started_at',
        'finished_at',
        'metadata',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(WhatsAppCampaignRecipient::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
