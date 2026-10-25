<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
            'generation_notes' => 'Na fila do ChatGPT da Cora Box. As artes aparecem aqui conforme ficam prontas.',
            'last_error' => null,
        ])->save();
    }

    /**
     * Pega o pacote mais antigo da fila para o ChatGPT da Cora Box (2026-10-25).
     * Quem gera é o worker do Homer, que chama os comandos fotos-anuncio:* pelo
     * SSH. Pacote que ficou "gerando" sem andar por 3 horas volta para a fila.
     */
    public static function reivindicarProximo(): ?self
    {
        return DB::transaction(function (): ?self {
            self::query()
                ->where('status', self::STATUS_GENERATING)
                ->where('updated_at', '<', now()->subHours(3))
                ->update(['status' => self::STATUS_QUEUED]);

            $brief = self::query()
                ->where('status', self::STATUS_QUEUED)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $brief?->forceFill([
                'status' => self::STATUS_GENERATING,
                'generation_notes' => 'Gerando no ChatGPT da Cora Box.',
                'last_error' => null,
            ])->save();

            return $brief;
        });
    }

    /** Guarda a arte pronta de um item da matriz; com todas prontas, conclui o pacote. */
    public function anexarImagem(string $chave, string $caminho, string $url): void
    {
        $encontrou = false;
        $matrix = collect($this->matrix ?? [])->map(function (array $item) use ($chave, $caminho, $url, &$encontrou): array {
            if (($item['key'] ?? null) !== $chave) {
                return $item;
            }

            $encontrou = true;

            return [...$item, 'status' => 'generated', 'image_path' => $caminho, 'image_url' => $url, 'generated_at' => now()->toIso8601String()];
        })->values()->all();

        if (! $encontrou) {
            throw new \InvalidArgumentException("O pacote {$this->uuid} não tem o item {$chave}.");
        }

        $this->matrix = $matrix;
        $concluido = collect($matrix)->every(fn (array $item): bool => filled($item['image_url'] ?? null));

        $this->forceFill($concluido ? [
            'matrix' => $matrix,
            'status' => self::STATUS_COMPLETED,
            'generation_finished_at' => now(),
            'generation_notes' => 'Todas as artes foram geradas no ChatGPT da Cora Box. Revise antes de publicar.',
        ] : [
            'matrix' => $matrix,
            'generation_notes' => 'Gerando no ChatGPT da Cora Box: '.collect($matrix)->filter(fn (array $item): bool => filled($item['image_url'] ?? null))->count().' de '.count($matrix).' artes prontas.',
        ])->save();
    }

    public function marcarFalha(string $mensagem): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'last_error' => $mensagem,
            'generation_notes' => 'A geração no ChatGPT da Cora Box parou. As artes já prontas ficaram guardadas; gere de novo para completar as que faltam.',
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
