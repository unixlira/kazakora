<?php

namespace App\Modules\Admin\Support\Publicidade;

use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Services\Meta\MetaAdsException;
use App\Services\Meta\MetaAdsService;
use Illuminate\Support\Carbon;

/**
 * Meta ADS na Central de campanhas: lê ao vivo pelo espelho que já existe
 * (MetaAdsService::espelhoCompleto, guardado 2 minutos) e converte para a
 * linha comum. Alertas = "O que fazer agora" (MetaAdsDiagnostico) dos
 * últimos 7 dias, o mesmo da tela da Meta. Só leitura.
 */
class CampanhasMeta implements FonteDeCampanhas
{
    /** Período da central => date_preset da Meta. */
    private const PRESETS = ['hoje' => 'today', '7d' => 'last_7d', '30d' => 'last_30d', 'mes' => 'this_month'];

    /** effective_status da campanha => status comum. */
    private const STATUS = [
        'ACTIVE' => CentralDeCampanhas::ATIVA,
        'PAUSED' => CentralDeCampanhas::PAUSADA,
        'CAMPAIGN_PAUSED' => CentralDeCampanhas::PAUSADA,
        'ADSET_PAUSED' => CentralDeCampanhas::PAUSADA,
        'IN_PROCESS' => CentralDeCampanhas::EM_REVISAO,
        'PENDING_REVIEW' => CentralDeCampanhas::EM_REVISAO,
        'PREAPPROVED' => CentralDeCampanhas::EM_REVISAO,
        'WITH_ISSUES' => CentralDeCampanhas::COM_PROBLEMA,
        'DISAPPROVED' => CentralDeCampanhas::COM_PROBLEMA,
        'PENDING_BILLING_INFO' => CentralDeCampanhas::COM_PROBLEMA,
        'ARCHIVED' => CentralDeCampanhas::ENCERRADA,
        'DELETED' => CentralDeCampanhas::ENCERRADA,
    ];

    /** Rótulo do status da Meta (o mesmo do espelho, metaAds.js). */
    private const ROTULOS = [
        'ACTIVE' => 'Ativo', 'PAUSED' => 'Pausado', 'CAMPAIGN_PAUSED' => 'Campanha pausada', 'ADSET_PAUSED' => 'Conjunto pausado',
        'IN_PROCESS' => 'Em processamento', 'PENDING_REVIEW' => 'Em análise', 'PREAPPROVED' => 'Pré-aprovado',
        'WITH_ISSUES' => 'Com problema', 'DISAPPROVED' => 'Reprovado', 'PENDING_BILLING_INFO' => 'Falta pagamento',
        'ARCHIVED' => 'Arquivado', 'DELETED' => 'Excluído',
    ];

    private const BASE = '/admin/publicidade/meta-ads/campanhas';

    public function __construct(private readonly MetaAdsService $meta) {}

    public function canal(): string
    {
        return PlataformaDeAnuncio::META;
    }

    public function conectada(): bool
    {
        return $this->meta->configurado();
    }

    public static function preset(string $periodo): string
    {
        return self::PRESETS[$periodo] ?? 'last_7d';
    }

    public function campanhas(string $periodo): array
    {
        $preset = self::preset($periodo);

        try {
            // Com arquivados: campanha arquivada que gastou no período entra no total.
            $espelho = $this->meta->espelhoCompleto($preset, null, true);
        } catch (MetaAdsException $erro) {
            throw new FonteIndisponivel($erro->getMessage(), 0, $erro);
        }

        $ids = array_map(fn ($c) => (string) $c['id'], $espelho['campanhas']);
        $locais = MetaAdsCampanha::query()->whereIn('meta_campaign_id', $ids ?: ['-'])->pluck('id', 'meta_campaign_id');
        $hoje = Carbon::now('America/Sao_Paulo')->toDateString();

        return array_values(array_map(function (array $c) use ($locais, $preset, $hoje) {
            $original = (string) $c['status'];
            $status = self::STATUS[$original] ?? CentralDeCampanhas::PAUSADA;
            // Campanha com data de fim que já passou: a Meta segue dizendo ACTIVE.
            if ($status === CentralDeCampanhas::ATIVA && filled($c['fim'] ?? null) && $c['fim'] < $hoje) {
                $status = CentralDeCampanhas::ENCERRADA;
            }
            $m = $c['metricas'];
            $local = $locais->get((string) $c['id']);

            return CentralDeCampanhas::linha($this->canal(), [
                'id' => (string) $c['id'],
                'nome' => (string) $c['nome'],
                'status' => $status,
                'status_plataforma' => self::ROTULOS[$original] ?? $original,
                'objetivo' => $c['objetivo'] ?: null,
                'busca_venda' => ($c['objetivo_codigo'] ?? '') === 'OUTCOME_SALES',
                'orcamento_diario' => $c['orcamento_diario'] ?? null,
                'gasto' => $m['gasto'],
                'impressoes' => $m['impressoes'],
                'cliques' => $m['cliques'],
                'resultados' => $m['compras'],
                'vendas' => $m['vendas'],
                'gerenciador' => $c['gerenciador'] ?? null,
                // Campanha feita no KazaKora abre a tela dela; a do Gerenciador, o espelho.
                'detalhe' => $local
                    ? self::BASE.'/'.$local.'?periodo='.$preset
                    : self::BASE.'?periodo='.$preset.'&status=todos',
            ]);
        }, $espelho['campanhas']));
    }

    public function alertas(): array
    {
        try {
            $diagnostico = $this->meta->diagnosticar($this->meta->espelhoCompleto('last_7d'), 'last_7d');
        } catch (MetaAdsException $erro) {
            throw new FonteIndisponivel($erro->getMessage(), 0, $erro);
        }

        $cartoes = array_filter($diagnostico['cartoes'] ?? [], fn ($c) => in_array($c['prioridade'], ['urgente', 'atencao'], true));

        return array_values(array_map(fn (array $c) => [
            'prioridade' => (string) $c['prioridade'],
            'titulo' => (string) $c['titulo'],
            'alvo' => (string) $c['alvo']['nome'].($c['alvo']['campanha'] !== $c['alvo']['nome'] ? ' · '.$c['alvo']['campanha'] : ''),
            'acao' => (string) $c['acao'],
            'prova' => implode(' · ', $c['prova']),
            'link' => $c['alvo']['gerenciador'] ?? null,
        ], $cartoes));
    }
}
