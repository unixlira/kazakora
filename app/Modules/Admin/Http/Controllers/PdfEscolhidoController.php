<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\PdfEscolhido;
use App\Modules\Admin\Models\PdfOferta;
use App\Modules\Admin\Services\PdfClonagemService;
use App\Modules\Admin\Support\TermometroOportunidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PDFs Escolhidos (Lira, 2026-11-18): as ofertas do radar de PDF clonadas
 * pelo botão "Clonar", com os dados, plano de ação, criativos e o prompt do
 * entregável.
 */
class PdfEscolhidoController extends Controller
{
    public function clonar(Request $request, PdfOferta $oferta, PdfClonagemService $clonagem): RedirectResponse
    {
        $jaExistia = $oferta->escolhido()->exists();
        $escolhido = $clonagem->clonar($oferta, $request->user());

        return redirect()->back()->with('success', $jaExistia
            ? "\"{$escolhido->nome}\" já estava nos PDFs Escolhidos."
            : "\"{$escolhido->nome}\" clonada: plano de ação e prompt do entregável prontos em PDFs Escolhidos.");
    }

    public function index(): Response
    {
        return Inertia::render('Admin/PdfOpportunities/Escolhidos', [
            'escolhidos' => PdfEscolhido::query()->latest()->get()->map(fn (PdfEscolhido $e): array => $this->resumo($e)),
            'fases' => TermometroOportunidade::FASES,
        ]);
    }

    public function show(PdfEscolhido $escolhido): Response
    {
        $dados = $escolhido->dados ?? [];

        return Inertia::render('Admin/PdfOpportunities/Escolhido', [
            'escolhido' => $this->resumo($escolhido) + [
                'dados' => $dados,
                'paginaBiblioteca' => ($dados['paginaId'] ?? null) ? 'https://www.facebook.com/ads/library/?active_status=active&ad_type=all&country=ALL&view_all_page_id='.$dados['paginaId'] : null,
                'planoAcao' => $escolhido->plano_acao ?? [],
                'prompt' => $escolhido->prompt_entregavel,
            ],
            'fases' => TermometroOportunidade::FASES,
            'anuncios' => collect($escolhido->anuncios ?? [])->map(function (array $a): array {
                $inicio = ($a['iniciadoEm'] ?? null) ? Carbon::parse($a['iniciadoEm']) : null;
                $a['iniciadoEm'] = $inicio?->format('d/m/Y');
                $a['diasRodando'] = $inicio ? (int) $inicio->diffInDays(now()) : null;
                $a['midias'] = collect($a['midias'] ?? [])->map(fn (array $m): array => [
                    'tipo' => $m['tipo'] ?? 'imagem',
                    'url' => $this->url($m['caminho'] ?? null),
                    'poster' => $this->url($m['poster'] ?? null),
                ])->filter(fn (array $m) => $m['url'])->values()->all();

                return $a;
            })->values(),
        ]);
    }

    public function destroy(PdfEscolhido $escolhido, PdfClonagemService $clonagem): RedirectResponse
    {
        $nome = $escolhido->nome;
        $clonagem->remover($escolhido);

        return redirect('/admin/pdf-oportunidades/escolhidos')->with('success', "\"{$nome}\" saiu dos PDFs Escolhidos.");
    }

    private function resumo(PdfEscolhido $e): array
    {
        $anuncios = $e->anuncios ?? [];
        $dados = $e->dados ?? [];

        return [
            'id' => $e->id,
            'ofertaId' => $e->pdf_oferta_id,
            'nome' => $e->nome,
            'nicho' => $e->nicho,
            'idioma' => $e->idioma,
            'preco' => $e->preco !== null ? (float) $e->preco : null,
            'moeda' => $e->moeda,
            'miniatura' => $this->url($e->miniatura),
            'paises' => $e->dados['paises'] ?? [],
            'anunciosAtivos' => $e->dados['anunciosAtivos'] ?? null,
            'diasRodando' => $e->dados['diasRodando'] ?? null,
            // Termômetro com a foto da oferta guardada no clone (dados e anúncios do dia em que foi clonada).
            'termometro' => PdfOferta::medirTermometro(
                (int) ($dados['diasRodando'] ?? 0),
                (int) ($dados['variacoes'] ?? 0),
                PdfOferta::contarNovos7d(array_column($anuncios, 'iniciadoEm'), $dados['coletadoEm'] ?? null),
            ),
            'totalCriativos' => count($anuncios),
            'totalMidias' => array_sum(array_map(fn (array $a) => count($a['midias'] ?? []), $anuncios)),
            'clonadoEm' => $e->created_at?->format('d/m/Y H:i'),
        ];
    }

    private function url(?string $caminho): ?string
    {
        return $caminho ? Storage::disk('public')->url($caminho) : null;
    }
}
