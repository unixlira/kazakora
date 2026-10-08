<?php

namespace App\Modules\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class GeminiUsageLog extends Model
{
    public const PURPOSE_REPLY = 'resposta';

    public const PURPOSE_TRANSCRIPTION = 'transcricao';

    protected $fillable = ['model', 'purpose', 'prompt_tokens', 'output_tokens', 'total_tokens'];

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
        ];
    }
}
