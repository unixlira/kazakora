<?php

namespace App\Modules\Materiais\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Lead de material gratuito (pedido do Lira 26/11/2026): nome, e-mail e
 * celular de quem baixou um PDF (Guia de Viagem de Bolso, na FerraKora).
 * Dado pessoal: fica fora de log e de JSON público; só o admin lê.
 *
 * Prazo de guarda: `materiais.meses_guarda` (padrão 12 meses) sem nova
 * interação, pelo model:prune das 03:58.
 */
class LeadMaterial extends Model
{
    use Prunable;

    protected $table = 'leads_materiais';

    protected $fillable = [
        'material', 'site', 'nome', 'email', 'telefone', 'ddd', 'uf_ip', 'cidade_ip', 'ddd_sugerido',
        'aceita_contato', 'consentimento_versao', 'consentimento_texto', 'consentimento_em', 'descadastrado_em',
        'origem', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referrer',
        'ip', 'user_agent', 'cadastros', 'downloads', 'ultimo_download_em', 'email_enviado_em',
    ];

    protected $hidden = ['ip', 'user_agent', 'consentimento_texto'];

    protected function casts(): array
    {
        return [
            'aceita_contato' => 'boolean',
            'consentimento_em' => 'datetime',
            'descadastrado_em' => 'datetime',
            'ultimo_download_em' => 'datetime',
            'email_enviado_em' => 'datetime',
            'cadastros' => 'integer',
            'downloads' => 'integer',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where('updated_at', '<', now()->subMonths(max(1, (int) config('materiais.meses_guarda', 12))));
    }

    /** Celular para mostrar: +55 (11) 91234-5678. */
    public function telefoneFormatado(): string
    {
        $n = (string) $this->telefone;
        if (strlen($n) !== 13) {
            return $n;
        }

        return sprintf('+55 (%s) %s-%s', substr($n, 2, 2), substr($n, 4, 5), substr($n, 9));
    }
}
