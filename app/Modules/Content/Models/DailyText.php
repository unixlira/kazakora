<?php

namespace App\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;

class DailyText extends Model
{
    protected $fillable = [
        'date',
        'weekday_label',
        'scripture_quote',
        'scripture_reference',
        'commentary',
        'source_doc_id',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'fetched_at' => 'datetime',
        ];
    }
}
