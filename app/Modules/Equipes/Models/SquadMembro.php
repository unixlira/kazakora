<?php

namespace App\Modules\Equipes\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Membro de uma squad: um agente do Claude Code no PC do Lira. O slug é o
 * nome do arquivo .claude/agents/<slug>.md e é por ele que o agente busca as
 * instruções extras (GET /api/equipe/{slug}/instrucoes).
 *
 * "Precisa de OK do Lira para" é só cadastro e exibição: não dá nem tira
 * permissão de verdade. As regras de OK do Lira valem sempre, acima de
 * qualquer instrução extra.
 */
class SquadMembro extends Model
{
    protected $table = 'squad_membros';

    public const STATUS_ATIVO = 'ativo';

    public const STATUS_PAUSADO = 'pausado';

    public const STATUS_DESATIVADO = 'desativado';

    public const STATUS = [self::STATUS_ATIVO, self::STATUS_PAUSADO, self::STATUS_DESATIVADO];

    public const AREAS = [
        'shopee' => ['rotulo' => 'Shopee', 'cor' => '#ee4d2d'],
        'mercado_livre' => ['rotulo' => 'Mercado Livre', 'cor' => '#2d3277'],
        'mercado' => ['rotulo' => 'Mercado', 'cor' => '#7c3aed'],
        'copy' => ['rotulo' => 'Copy e UX', 'cor' => '#db2777'],
        'dev' => ['rotulo' => 'Dev', 'cor' => '#0d6efd'],
        'operacao' => ['rotulo' => 'Operação', 'cor' => '#059669'],
        'outra' => ['rotulo' => 'Outra', 'cor' => '#64748b'],
    ];

    public const PRECISA_OK = [
        'preco' => 'Mexer em preço',
        'campanha' => 'Mexer em campanha ou ADS',
        'cliente' => 'Falar com cliente',
        'publicar' => 'Publicar em canal oficial',
        'apagar' => 'Apagar qualquer coisa',
    ];

    protected $fillable = [
        'squad_id', 'nome', 'slug', 'funcao', 'area', 'o_que_faz', 'skills',
        'responde_para_id', 'precisa_ok', 'status', 'cor', 'foto_path', 'ordem',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'precisa_ok' => 'array',
        ];
    }

    public function squad(): BelongsTo
    {
        return $this->belongsTo(Squad::class, 'squad_id');
    }

    public function respondePara(): BelongsTo
    {
        return $this->belongsTo(self::class, 'responde_para_id');
    }

    public function instrucoes(): HasMany
    {
        return $this->hasMany(SquadInstrucao::class, 'membro_id')->latest('id');
    }

    public function instrucoesAtivas(): HasMany
    {
        return $this->hasMany(SquadInstrucao::class, 'membro_id')->where('ativa', true)->orderBy('id');
    }

    public function corDaArea(): string
    {
        return $this->cor ?: (self::AREAS[$this->area]['cor'] ?? self::AREAS['outra']['cor']);
    }

    public function fotoUrl(): ?string
    {
        return $this->foto_path ? Storage::disk('public')->url($this->foto_path) : null;
    }
}
