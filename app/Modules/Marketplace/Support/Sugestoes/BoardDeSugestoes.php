<?php

namespace App\Modules\Marketplace\Support\Sugestoes;

use App\Modules\Marketplace\Models\Sugestao;
use App\Modules\Marketplace\Models\SugestaoRodada;
use Illuminate\Support\Carbon;

/**
 * Props da aba Indicadores › Sugestões do dia: as sugestões do último dia
 * gerado, em colunas por área, mais a situação da última rodada. Quem não
 * vê o financeiro não vê custo nem piso de margem.
 */
class BoardDeSugestoes
{
    private const ESCONDIDO_SEM_FINANCEIRO = ['Custo', 'Piso (15% de margem)', 'ROAS para empatar'];

    /** @return array<string, mixed> */
    public function montar(bool $comFinanceiro): array
    {
        $rodada = SugestaoRodada::query()->latest('id')->first();
        $dia = Sugestao::query()->max('dia');
        $dia = $dia ? Carbon::parse($dia) : null;

        $sugestoes = $dia === null ? collect() : Sugestao::query()
            ->with(['product:id,name,slug', 'resolvidaPor:id,name'])
            ->whereDate('dia', $dia)
            ->orderBy('prioridade')->orderBy('id')
            ->get()
            ->map(fn (Sugestao $s) => $this->linha($s, $comFinanceiro));

        return [
            'dia' => $dia?->toDateString(),
            'rodada' => $rodada ? [
                'iniciada_em' => $rodada->iniciada_em?->toIso8601String(),
                'terminada_em' => $rodada->terminada_em?->toIso8601String(),
                'total' => $rodada->total,
                'erro' => $rodada->erro,
                'fontes' => collect($rodada->fontes ?? [])->map(fn ($f, $nome) => [
                    'nome' => ['meta' => 'Meta Ads', 'mercado_livre' => 'Mercado Livre (catálogo)', 'zoom' => 'Zoom (Magalu, Amazon e outras)'][$nome] ?? $nome,
                ] + $f)->values()->all(),
            ] : null,
            'areas' => collect(Sugestao::AREAS)->map(fn ($rotulo, $chave) => [
                'chave' => $chave,
                'rotulo' => $rotulo,
                'sugestoes' => $sugestoes->where('area', $chave)->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function linha(Sugestao $s, bool $comFinanceiro): array
    {
        $dados = $s->dados ?? [];
        if (! $comFinanceiro) {
            $dados['metricas'] = array_diff_key($dados['metricas'] ?? [], array_flip(self::ESCONDIDO_SEM_FINANCEIRO));
        }

        // Rotas conferidas em rotas-kazakora.json.
        $links = [];
        if ($s->product) {
            $links[] = ['rotulo' => 'Editar produto', 'href' => '/admin/produtos/'.$s->product->id.'/editar'];
            $links[] = ['rotulo' => 'Ver na loja', 'href' => '/produtos/'.$s->product->slug, 'externo' => true];
        }
        if ($s->meta_ad_id || in_array($s->area, [Sugestao::AREA_CRIATIVO, Sugestao::AREA_ORCAMENTO], true)) {
            $links[] = ['rotulo' => 'Abrir Meta Ads', 'href' => '/admin/publicidade/meta-ads'];
        }

        return [
            'id' => $s->id,
            'area' => $s->area,
            'tipo' => $s->tipo,
            'prioridade' => $s->prioridade,
            'titulo' => $s->titulo,
            'acao' => $s->acao,
            'motivo' => $s->motivo,
            'metricas' => $dados['metricas'] ?? [],
            'concorrentes' => $dados['concorrentes'] ?? [],
            'miniatura' => $dados['miniatura'] ?? null,
            'status' => $s->status,
            'resolvida_em' => $s->resolvida_em?->toIso8601String(),
            'resolvida_por' => $s->resolvidaPor?->name,
            'links' => $links,
        ];
    }
}
