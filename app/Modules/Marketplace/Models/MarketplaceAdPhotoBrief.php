<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MarketplaceAdPhotoBrief extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready_for_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    protected $fillable = [
        'uuid',
        'marketplace',
        'product_name',
        'category_hint',
        'description',
        'immutable_notes',
        'reference_links',
        'image_path',
        'image_url',
        'image_original_name',
        'competitive_research',
        'hero_decision',
        'matrix',
        'copy_pack',
        'generated_product',
        'warnings',
        'status',
        'approval_status',
        'approved_at',
        'generation_started_at',
        'generation_finished_at',
        'due_at',
        'generation_notes',
        'last_error',
    ];

    protected $appends = [
        'matrix_total',
        'generated_count',
        'generation_progress_label',
    ];

    protected function casts(): array
    {
        return [
            'competitive_research' => 'array',
            'hero_decision' => 'array',
            'matrix' => 'array',
            'generated_product' => 'array',
            'warnings' => 'array',
            'approved_at' => 'datetime',
            'generation_started_at' => 'datetime',
            'generation_finished_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $brief): void {
            if (! $brief->uuid) {
                $brief->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getMatrixTotalAttribute(): int
    {
        return count($this->matrix ?? []);
    }

    public function getGeneratedCountAttribute(): int
    {
        return collect($this->matrix ?? [])
            ->filter(fn (array $item): bool => ($item['status'] ?? null) === 'generated' || filled($item['image_url'] ?? null))
            ->count();
    }

    public function getGenerationProgressLabelAttribute(): string
    {
        return $this->generated_count.'/'.$this->matrix_total;
    }

    public function approve(): void
    {
        $this->forceFill([
            'approval_status' => self::APPROVAL_APPROVED,
            'approved_at' => now(),
            'status' => $this->status === self::STATUS_DRAFT || $this->status === self::STATUS_READY
                ? self::STATUS_APPROVED
                : $this->status,
        ])->save();
    }

    public function reject(?string $reason = null): void
    {
        $this->forceFill([
            'approval_status' => self::APPROVAL_REJECTED,
            'status' => self::STATUS_READY,
            'last_error' => $reason,
        ])->save();
    }

    public function startGeneration(): void
    {
        $matrix = collect($this->matrix ?? [])
            ->map(function (array $item): array {
                return [
                    ...$item,
                    'status' => $item['status'] ?? 'pending',
                    'image_url' => $item['image_url'] ?? null,
                    'generated_at' => $item['generated_at'] ?? null,
                ];
            })
            ->values()
            ->all();

        $this->forceFill([
            'approval_status' => self::APPROVAL_APPROVED,
            'approved_at' => $this->approved_at ?? now(),
            'status' => self::STATUS_QUEUED,
            'matrix' => $matrix,
            'generation_started_at' => $this->generation_started_at ?? now(),
            'generation_finished_at' => null,
            'due_at' => $this->due_at ?? now()->addMinutes($this->estimatedGenerationMinutes()),
            'generation_notes' => 'Fila interna criada. Próxima etapa: conectar o worker real de geração ChatGPT/Giovanna e anexar as imagens geradas em cada arte.',
            'last_error' => null,
        ])->save();
    }

    public function estimatedGenerationMinutes(): int
    {
        return max(12, $this->matrix_total * 3);
    }

    public function toAdminSummary(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'marketplace' => $this->marketplace,
            'marketplaceLabel' => $this->marketplace === 'mercado_livre' ? 'Mercado Livre' : 'Shopee',
            'expectedImages' => $this->matrix_total,
            'productName' => $this->product_name,
            'categoryHint' => $this->category_hint,
            'status' => $this->status,
            'statusLabel' => self::statusLabels()[$this->status] ?? $this->status,
            'approvalStatus' => $this->approval_status,
            'approvalLabel' => self::approvalLabels()[$this->approval_status] ?? $this->approval_status,
            'generatedCount' => $this->generated_count,
            'generationProgressLabel' => $this->generation_progress_label,
            'createdAt' => optional($this->created_at)->format('d/m/Y H:i'),
            'dueAt' => optional($this->due_at)->toIso8601String(),
            'generationStartedAt' => optional($this->generation_started_at)->toIso8601String(),
            'url' => '/admin/marketplaces/fotos-anuncio/'.$this->uuid,
        ];
    }

    public function toAdminDetail(): array
    {
        return [
            ...$this->toAdminSummary(),
            'description' => $this->description,
            'immutableNotes' => $this->immutable_notes,
            'referenceLinks' => $this->reference_links,
            'image' => $this->image_url ? [
                'path' => $this->image_path,
                'url' => $this->image_url,
                'originalName' => $this->image_original_name,
            ] : null,
            'competitiveResearch' => $this->competitive_research ?? [],
            'heroDecision' => $this->hero_decision ?? [],
            'matrix' => $this->matrix ?? [],
            'copyPack' => $this->copy_pack,
            'generatedProduct' => $this->generated_product ?? [],
            'warnings' => $this->warnings ?? [],
            'approvedAt' => optional($this->approved_at)->format('d/m/Y H:i'),
            'generationFinishedAt' => optional($this->generation_finished_at)->format('d/m/Y H:i'),
            'generationNotes' => $this->generation_notes,
            'lastError' => $this->last_error,
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Rascunho',
            self::STATUS_READY => 'Matriz pronta',
            self::STATUS_APPROVED => 'Matriz aprovada',
            self::STATUS_QUEUED => 'Na fila',
            self::STATUS_GENERATING => 'Gerando',
            self::STATUS_COMPLETED => 'Concluído',
            self::STATUS_FAILED => 'Falhou',
        ];
    }

    public static function approvalLabels(): array
    {
        return [
            self::APPROVAL_PENDING => 'Aguardando aprovação',
            self::APPROVAL_APPROVED => 'Aprovado',
            self::APPROVAL_REJECTED => 'Reprovado',
        ];
    }
}
