<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\PdfOferta;
use App\Modules\Admin\Models\PdfOfertaAnuncio;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Oportunidades em PDF (2026-11-17): as ofertas de infoproduto em PDF mais
 * quentes do mundo, com dados reais da Biblioteca de Anúncios da Meta
 * (coletor do Homer + `pdf-oportunidades:importar`). Antes a tela era um
 * ranking por "sinais públicos e proxy" (PdfOpportunityResearchService, que
 * continua alimentando o Mkt Digital).
 */
class PdfOpportunityController extends Controller
{
    public function index(): Response
    {
        $ofertas = PdfOferta::query()
            ->where('ativa', true)
            ->orderByRaw('posicao is null')->orderBy('posicao')->orderByDesc('pontuacao')
            ->limit(100)
            ->get()
            ->map(fn (PdfOferta $o): array => $this->resumo($o));

        return Inertia::render('Admin/PdfOpportunities/Index', [
            'ofertas' => $ofertas,
            'coletadoEm' => PdfOferta::query()->where('ativa', true)->max('coletado_em'),
        ]);
    }

    public function show(PdfOferta $oferta): Response
    {
        $oferta->load(['anuncios' => fn ($q) => $q->orderByDesc('variacoes')->orderBy('iniciado_em')]);

        return Inertia::render('Admin/PdfOpportunities/Show', [
            'oferta' => $this->resumo($oferta) + [
                'urlDestino' => $oferta->url_destino,
                'observacao' => $oferta->observacao,
                'paginaBiblioteca' => $oferta->pagina_id ? 'https://www.facebook.com/ads/library/?active_status=active&ad_type=all&country=ALL&view_all_page_id='.$oferta->pagina_id : null,
            ],
            'anuncios' => $oferta->anuncios->map(fn (PdfOfertaAnuncio $a): array => [
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
                'plataformas' => $a->plataformas ?? [],
                'midias' => collect($a->midias ?? [])->map(fn (array $m): array => [
                    'tipo' => $m['tipo'] ?? 'imagem',
                    'url' => $this->url($m['caminho'] ?? null),
                    'poster' => $this->url($m['poster'] ?? null),
                ])->filter(fn (array $m) => $m['url'])->values(),
            ]),
        ]);
    }

    private function resumo(PdfOferta $o): array
    {
        return [
            'id' => $o->id,
            'posicao' => $o->posicao,
            'nome' => $o->nome,
            'nicho' => $o->nicho,
            'idioma' => $o->idioma,
            'anunciante' => $o->anunciante,
            'dominio' => $o->dominio,
            'plataforma' => $o->plataforma,
            'preco' => $o->preco !== null ? (float) $o->preco : null,
            'moeda' => $o->moeda,
            'paises' => $o->paises ?? [],
            'anunciosAtivos' => $o->anuncios_ativos,
            'variacoes' => $o->variacoes,
            'diasRodando' => $o->dias_rodando,
            'inicioMaisAntigo' => $o->inicio_mais_antigo?->format('d/m/Y'),
            'pontuacao' => (float) $o->pontuacao,
            'miniatura' => $this->url($o->miniatura),
        ];
    }

    private function url(?string $caminho): ?string
    {
        return $caminho ? Storage::disk('public')->url($caminho) : null;
    }
}
