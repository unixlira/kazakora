<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelWebhookLog extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'channel',
        'event_type',
        'payload',
        'headers',
        'signature_valid',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'signature_valid' => 'boolean',
        ];
    }
}
