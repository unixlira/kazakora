<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Jobs\GerarRoteiroCriativos;
use App\Modules\Admin\Models\KzCriativo;
use App\Modules\Admin\Models\KzOportunidade;
use App\Modules\Admin\Models\KzOportunidadeAnuncio;
use App\Modules\Admin\Services\FabricaCriativosKazakora;
use App\Modules\Admin\Support\TermometroOportunidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Oportunidades › KazaKora (Lira, 2026-11-18): produtos físicos escalando na
 * Biblioteca de Anúncios, com termômetro, criativos veiculados e a fábrica de
 * criativos (clonar um anúncio ou criar do zero).
 */
class OportunidadesKazakoraController extends Controller
{
    public function index(): Response
    {
        $oportunidades = KzOportunidade::query()
            ->withCount('criativos')
            ->where('ativa', true)
            ->orderByDesc('pontuacao')
            ->limit(150)
            ->get()
            ->map(fn (KzOportunidade $o): array => $this->resumo($o));

        return Inertia::render('Admin/OportunidadesKazakora/Index', [
            'oportunidades' => $oportunidades,
            'fases' => TermometroOportunidade::FASES,
            'coletadoEm' => KzOportunidade::query()->where('ativa', true)->max('coletado_em'),
        ]);
    }

    public function show(KzOportunidade $oportunidade): Response
    {
        $oportunidade->load([
            'anuncios' => fn ($q) => $q->orderByDesc('variacoes')->orderBy('iniciado_em'),
            'criativos' => fn ($q) => $q->latest('id'),
        ]);

        return Inertia::render('Admin/OportunidadesKazakora/Show', [
            'oportunidade' => $this->resumo($oportunidade) + [
                'urlDestino' => $oportunidade->url_destino,
                'observacao' => $oportunidade->observacao,
                'paginaBiblioteca' => $oportunidade->pagina_id ? 'https://www.facebook.com/ads/library/?active_status=active&ad_type=all&country=ALL&view_all_page_id='.$oportunidade->pagina_id : null,
            ],
            'fases' => TermometroOportunidade::FASES,
            'maximoPorLote' => FabricaCriativosKazakora::MAXIMO_POR_LOTE,
            'anuncios' => $oportunidade->anuncios->map(fn (KzOportunidadeAnuncio $a): array => [
                'id' => $a->id,
                'adId' => $a->ad_archive_id,
                'link' => $a->linkBiblioteca(),
                'texto' => $a->texto,
                'titulo' => $a->titulo,
                'cta' => $a->cta,
                'urlDestino' => $a->url_destino,
                'iniciadoEm' => $a->iniciado_em?->format('d/m/Y'),
                'diasRodando' => $a->iniciado_em ? (int) $a->iniciado_em->diffInDays(now()) : null,
                'variacoes' => $a->variacoes,
                'alcanceUe' => $a->alcance_ue,
                'plataformas' => $a->plataformas ?? [],
                'midias' => collect($a->midias ?? [])->map(fn (array $m): array => [
                    'tipo' => $m['tipo'] ?? 'imagem',
                    'url' => $this->url($m['caminho'] ?? null),
                    'poster' => $this->url($m['poster'] ?? null),
                ])->filter(fn (array $m) => $m['url'])->values(),
                'clones' => $oportunidade->criativos->where('kz_oportunidade_anuncio_id', $a->id)->pluck('codigo')->values(),
            ]),
            'criativos' => $oportunidade->criativos->map(fn (KzCriativo $c): array => [
                'id' => $c->id,
                'codigo' => $c->codigo,
                'origem' => $c->origem,
                'referenciaAdId' => $c->referencia_ad_id,
                'status' => $c->status,
                'statusRotulo' => KzCriativo::STATUS[$c->status] ?? $c->status,
                'lote' => "{$c->numero}/{$c->total_lote}",
                'pasta' => $c->pasta ? 'storage/app/private/'.$c->pasta : null,
                'briefing' => $c->briefing,
                'erro' => $c->erro,
                'criadoEm' => $c->created_at?->format('d/m/Y H:i'),
            ]),
        ]);
    }

    public function clonar(Request $request, KzOportunidadeAnuncio $anuncio, FabricaCriativosKazakora $fabrica): RedirectResponse
    {
        $criativo = $fabrica->pedir($anuncio->oportunidade, $anuncio, 1, $request->user())->first();

        return redirect()->back()->with('success', "Criativo {$criativo->codigo} criado a partir do anúncio {$anuncio->ad_archive_id}. O roteiro completo fica pronto em instantes.");
    }

    public function criar(Request $request, KzOportunidade $oportunidade, FabricaCriativosKazakora $fabrica): RedirectResponse
    {
        $dados = $request->validate(['quantidade' => ['required', 'integer', 'min:1', 'max:'.FabricaCriativosKazakora::MAXIMO_POR_LOTE]]);
        $criativos = $fabrica->pedir($oportunidade, null, (int) $dados['quantidade'], $request->user());

        return redirect()->back()->with('success', $criativos->count().' criativo(s) do zero na fila ('.$criativos->first()->codigo.($criativos->count() > 1 ? ' a '.$criativos->last()->codigo : '').'). Os roteiros ficam prontos em instantes.');
    }

    /** Criativo que deu erro no roteiro: tenta de novo. */
    public function refazer(KzCriativo $criativo): RedirectResponse
    {
        abort_unless($criativo->status === 'erro', 422, 'Só criativo com erro pode ser refeito.');
        KzCriativo::query()->where('lote', $criativo->lote)->where('status', 'erro')->update(['status' => 'roteirizando', 'erro' => null]);
        GerarRoteiroCriativos::dispatch($criativo->lote);

        return redirect()->back()->with('success', "Roteiro de {$criativo->codigo} na fila de novo.");
    }

    private function resumo(KzOportunidade $o): array
    {
        return [
            'id' => $o->id,
            'nome' => $o->nome,
            'nicho' => $o->nicho,
            'idioma' => $o->idioma,
            'anunciante' => $o->anunciante,
            'dominio' => $o->dominio,
            'preco' => $o->preco !== null ? (float) $o->preco : null,
            'moeda' => $o->moeda,
            'paises' => $o->paises ?? [],
            'anunciosAtivos' => $o->anuncios_ativos,
            'variacoes' => $o->variacoes,
            'anunciantesDistintos' => $o->anunciantes_distintos,
            'alcanceUe' => $o->alcance_ue,
            'criativosNovos7d' => $o->criativos_novos_7d,
            'diasRodando' => $o->dias_rodando,
            'inicioMaisAntigo' => $o->inicio_mais_antigo?->format('d/m/Y'),
            'pontuacao' => (float) $o->pontuacao,
            'fase' => $o->fase,
            'miniatura' => $this->url($o->miniatura),
            'totalCriativos' => $o->criativos_count ?? $o->criativos?->count() ?? 0,
        ];
    }

    private function url(?string $caminho): ?string
    {
        return $caminho ? Storage::disk('public')->url($caminho) : null;
    }
}
