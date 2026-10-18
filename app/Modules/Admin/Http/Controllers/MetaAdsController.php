<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Jobs\EnviarCampanhaMetaAds;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use App\Modules\Marketplace\Support\DuracaoDeVideo;
use App\Services\Meta\MetaAdsException;
use App\Services\Meta\MetaAdsService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Meta ADS (2026-10-13): campanhas montadas no admin e enviadas pela
 * Marketing API da Meta (MetaAdsService). Lira sobe vídeos e imagens aqui,
 * cada um vira um anúncio com produto e texto próprios (2026-10-18), todos
 * no mesmo conjunto (como no Gerenciador) ou um conjunto por anúncio com o
 * orçamento dividido igual. Envio cria tudo PAUSADO; ativar é outro botão,
 * com confirmação explícita do gasto.
 */
class MetaAdsController extends Controller
{
    /** 60 MB: o servidor aceita até 64 MB por requisição (nginx e PHP). */
    private const MAX_KILOBYTES = 61440;

    private const TIPOS_VIDEO = ['video/mp4', 'video/quicktime'];

    private const TIPOS_IMAGEM = ['image/jpeg', 'image/png'];

    public function index(MetaAdsService $meta): Response
    {
        $campanhas = MetaAdsCampanha::query()
            ->with('user:id,name')
            ->withCount('videos')
            ->latest()
            ->get()
            ->map(fn (MetaAdsCampanha $c) => [
                'id' => $c->id,
                'nome' => $c->nome,
                'objetivo_rotulo' => $c->objetivo_rotulo,
                'status' => $c->status,
                'status_rotulo' => $c->status_rotulo,
                'orcamento_diario' => (float) $c->orcamento_diario,
                'inicio' => $c->inicio?->toDateString(),
                'fim' => $c->fim?->toDateString(),
                'videos' => $c->videos_count,
                'ultimo_erro' => $c->ultimo_erro,
                'criada_por' => $c->user?->name,
            ]);

        return Inertia::render('Admin/MetaAds/Index', [
            'campanhas' => $campanhas,
            'config' => $this->estadoConfig($meta),
        ]);
    }

    public function create(MetaAdsService $meta): Response
    {
        return Inertia::render('Admin/MetaAds/Form', $this->dadosDoFormulario($meta, null));
    }

    /**
     * A tela salva o rascunho por fetch (JSON) e em seguida sobe os vídeos
     * um a um em videoStore(), com barra de progresso.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $campanha = MetaAdsCampanha::create($this->comErrosEmJson($request, fn () => $this->validar($request)) + [
            'status' => MetaAdsCampanha::STATUS_RASCUNHO,
            'user_id' => $request->user()->id,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['id' => $campanha->id], 201);
        }

        return redirect()->route('admin.publicidade.meta-ads.campanhas.ver', $campanha)->with('success', 'Rascunho salvo.');
    }

    public function show(MetaAdsCampanha $campanha, MetaAdsService $meta): Response
    {
        $campanha->load(['produto:id,name,slug', 'user:id,name', 'videos']);

        $desempenho = [];
        $erroDesempenho = null;
        if (filled($campanha->meta_campaign_id) && $meta->configurado()) {
            try {
                $desempenho = $meta->desempenhoPorAnuncio($campanha);
            } catch (MetaAdsException $erro) {
                $erroDesempenho = $erro->getMessage();
            }
        }

        $partes = $campanha->orcamentoPorAnuncio();

        return Inertia::render('Admin/MetaAds/Show', [
            'campanha' => $campanha->makeHidden('videos'),
            'videos' => $campanha->videos->values()->map(fn (MetaAdsVideo $v, int $i) => $this->dadosDoVideo($campanha, $v) + [
                // Antes do envio, a parte prevista; depois, a gravada.
                'orcamento_diario' => $v->orcamento_diario !== null ? (float) $v->orcamento_diario : ($partes[$i] ?? 0) / 100,
                'desempenho' => $v->meta_ad_id ? ($desempenho[$v->meta_ad_id] ?? null) : null,
            ]),
            'erroDesempenho' => $erroDesempenho,
            'config' => $this->estadoConfig($meta),
            'problemas' => $campanha->podeEnviar() ? $meta->problemasParaEnviar($campanha) : [],
            'pode' => [
                'editar' => $campanha->podeEditar(),
                'enviar' => $campanha->podeEnviar(),
                'ativar' => $campanha->podeAtivar(),
                'pausar' => $campanha->podePausar(),
                'excluir' => $campanha->podeExcluir(),
                'mexerNosVideos' => $campanha->podeMexerNosVideos(),
            ],
            'linkGerenciador' => $campanha->meta_campaign_id ? $meta->linkGerenciador($campanha) : null,
            'chamadas' => MetaAdsCampanha::CHAMADAS,
            'generos' => MetaAdsCampanha::GENEROS,
            'estruturas' => MetaAdsCampanha::ESTRUTURAS,
        ]);
    }

    public function edit(MetaAdsCampanha $campanha, MetaAdsService $meta): Response|RedirectResponse
    {
        if (! $campanha->podeEditar()) {
            return redirect()->route('admin.publicidade.meta-ads.campanhas.ver', $campanha)
                ->with('error', 'Campanha já enviada à Meta. Edite pelo Gerenciador de Anúncios.');
        }

        $campanha->load('videos');

        return Inertia::render('Admin/MetaAds/Form', $this->dadosDoFormulario($meta, $campanha));
    }

    public function update(Request $request, MetaAdsCampanha $campanha): JsonResponse|RedirectResponse
    {
        if (! $campanha->podeEditar()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Campanha já enviada à Meta. Edite pelo Gerenciador de Anúncios.'], 409);
            }

            return back()->with('error', 'Campanha já enviada à Meta. Edite pelo Gerenciador de Anúncios.');
        }

        $campanha->update($this->comErrosEmJson($request, fn () => $this->validar($request)));

        if ($request->wantsJson()) {
            return response()->json(['id' => $campanha->id]);
        }

        return redirect()->route('admin.publicidade.meta-ads.campanhas.ver', $campanha)->with('success', 'Rascunho atualizado.');
    }

    public function destroy(MetaAdsCampanha $campanha): RedirectResponse
    {
        if (! $campanha->podeExcluir()) {
            return back()->with('error', 'Só dá para excluir rascunho que nunca foi enviado à Meta.');
        }

        Storage::disk('local')->deleteDirectory(self::pastaDosVideos($campanha));
        $campanha->delete();

        return redirect()->route('admin.publicidade.meta-ads.campanhas.listar')->with('success', 'Rascunho excluído.');
    }

    public function enviar(MetaAdsCampanha $campanha, MetaAdsService $meta): RedirectResponse
    {
        if (! $campanha->podeEnviar()) {
            return back()->with('error', 'Esta campanha já foi enviada.');
        }

        $campanha->load('videos');

        if ($problemas = $meta->problemasParaEnviar($campanha)) {
            return back()->with('error', implode(' ', $problemas));
        }

        $campanha->forceFill(['status' => MetaAdsCampanha::STATUS_ENVIANDO, 'ultimo_erro' => null])->save();
        $campanha->videos()->update(['ultimo_erro' => null]);
        EnviarCampanhaMetaAds::dispatch($campanha->id);

        return back()->with('success', 'Enviando para a Meta. Os vídeos e imagens sobem um por um e a Meta leva alguns minutos para processar os vídeos. Tudo fica PAUSADO até você ativar.');
    }

    public function ativar(Request $request, MetaAdsCampanha $campanha, MetaAdsService $meta): RedirectResponse
    {
        // Confirmação explícita do gasto: a tela manda o orçamento diário que
        // a pessoa viu no aviso, e ele tem que bater com o da campanha.
        $request->validate([
            'confirmo_gasto' => ['accepted'],
            'orcamento_diario' => ['required', 'numeric'],
        ], [
            'confirmo_gasto.accepted' => 'Confirme que a campanha vai começar a gastar.',
        ]);

        $campanha->load('videos');

        if (round((float) $request->input('orcamento_diario'), 2) !== round((float) $campanha->orcamento_diario, 2)) {
            throw ValidationException::withMessages(['orcamento_diario' => 'O orçamento mudou. Recarregue a tela e confirme de novo.']);
        }

        if (! $campanha->podeAtivar()) {
            return back()->with('error', 'Só dá para ativar campanha enviada e pausada.');
        }

        if (! $meta->configurado()) {
            return back()->with('error', 'Meta não configurado.');
        }

        try {
            $meta->alterarStatus($campanha, 'ACTIVE');
        } catch (MetaAdsException) {
            return back()->with('error', 'A Meta não ativou a campanha. Veja o erro na tela.');
        }

        return back()->with('success', 'Campanha ativa na Meta. Já está gastando.');
    }

    public function pausar(MetaAdsCampanha $campanha, MetaAdsService $meta): RedirectResponse
    {
        $campanha->load('videos');

        if (! $campanha->podePausar()) {
            return back()->with('error', 'Só dá para pausar campanha ativa.');
        }

        if (! $meta->configurado()) {
            return back()->with('error', 'Meta não configurado.');
        }

        try {
            $meta->alterarStatus($campanha, 'PAUSED');
        } catch (MetaAdsException) {
            return back()->with('error', 'A Meta não pausou a campanha. Veja o erro na tela.');
        }

        return back()->with('success', 'Campanha pausada na Meta.');
    }

    /**
     * Recebe UM anúncio por requisição: o arquivo (vídeo ou imagem, até
     * 64 MB por envio no servidor) e, opcional, o produto e o texto dele.
     */
    public function videoStore(Request $request, MetaAdsCampanha $campanha, DuracaoDeVideo $duracao): JsonResponse
    {
        if (! $campanha->podeMexerNosVideos()) {
            return response()->json(['message' => 'Campanha já enviada à Meta: os anúncios não mudam mais por aqui.'], 409);
        }

        $textos = $this->comErrosEmJson($request, function () use ($request) {
            $request->validate([
                'video' => ['required', 'file', 'mimetypes:'.implode(',', [...self::TIPOS_VIDEO, ...self::TIPOS_IMAGEM]), 'max:'.self::MAX_KILOBYTES],
            ], [
                'video.mimetypes' => 'Use vídeo MP4 ou MOV, ou imagem JPG ou PNG.',
                'video.max' => 'O arquivo passa de 60 MB. Comprima antes de enviar.',
                'video.uploaded' => 'O arquivo não chegou inteiro (limite de 60 MB). Comprima antes de enviar.',
            ]);

            return $this->validarAnuncio($request);
        });

        if ($campanha->videos()->count() >= MetaAdsCampanha::MAX_VIDEOS) {
            return response()->json(['message' => 'Máximo de '.MetaAdsCampanha::MAX_VIDEOS.' anúncios por campanha.'], 422);
        }

        $arquivo = $request->file('video');
        $imagem = in_array($arquivo->getMimeType(), self::TIPOS_IMAGEM, true);
        $path = $arquivo->store(self::pastaDosVideos($campanha), 'local');

        if ($path === false) {
            return response()->json(['message' => 'Não consegui guardar o arquivo no servidor.'], 500);
        }

        $video = $campanha->videos()->create($textos + [
            'ordem' => (int) $campanha->videos()->max('ordem') + ($campanha->videos()->exists() ? 1 : 0),
            'tipo' => $imagem ? MetaAdsVideo::TIPO_IMAGEM : MetaAdsVideo::TIPO_VIDEO,
            'nome_original' => mb_substr($arquivo->getClientOriginalName() ?: ($imagem ? 'imagem.jpg' : 'video.mp4'), 0, 250),
            'path' => $path,
            'tamanho' => (int) $arquivo->getSize(),
            'duracao_segundos' => $imagem ? null : $duracao->segundos(Storage::disk('local')->path($path)),
        ]);

        return response()->json(['video' => $this->dadosDoVideo($campanha, $video)], 201);
    }

    /** Produto e texto próprios de um anúncio já guardado (vazio = padrão da campanha). */
    public function videoUpdate(Request $request, MetaAdsCampanha $campanha, MetaAdsVideo $video): JsonResponse
    {
        abort_unless($video->campanha_id === $campanha->id, 404);

        if (! $campanha->podeMexerNosVideos() || $video->temObjetoNoMeta()) {
            return response()->json(['message' => 'Anúncio já enviado à Meta: edite pelo Gerenciador de Anúncios.'], 409);
        }

        $video->update($this->comErrosEmJson($request, fn () => $this->validarAnuncio($request)));

        return response()->json(['video' => $this->dadosDoVideo($campanha, $video)]);
    }

    public function videoDestroy(Request $request, MetaAdsCampanha $campanha, MetaAdsVideo $video): JsonResponse|RedirectResponse
    {
        abort_unless($video->campanha_id === $campanha->id, 404);

        if (! $campanha->podeMexerNosVideos() || $video->temObjetoNoMeta()) {
            $mensagem = 'Anúncio já enviado à Meta: tire pelo Gerenciador de Anúncios.';

            return $request->wantsJson() ? response()->json(['message' => $mensagem], 409) : back()->with('error', $mensagem);
        }

        Storage::disk('local')->delete($video->path);
        $video->delete();

        return $request->wantsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Anúncio removido.');
    }

    /** Toca o vídeo guardado no servidor (prévia na tela). */
    public function videoArquivo(MetaAdsCampanha $campanha, MetaAdsVideo $video): BinaryFileResponse
    {
        abort_unless($video->campanha_id === $campanha->id, 404);

        $disco = Storage::disk('local');
        abort_unless($disco->exists($video->path), 404);

        return response()->file($disco->path($video->path), ['Cache-Control' => 'private, max-age=3600']);
    }

    public function localizacoes(Request $request, MetaAdsService $meta): JsonResponse
    {
        $termo = trim((string) $request->query('q', ''));

        if (mb_strlen($termo) < 2 || ! $meta->configurado()) {
            return response()->json(['data' => []]);
        }

        try {
            return response()->json(['data' => $meta->buscarLocalizacoes($termo)]);
        } catch (MetaAdsException $erro) {
            return response()->json(['data' => [], 'erro' => $erro->getMessage()], 422);
        }
    }

    // ------------------------------------------------------------------------

    /**
     * No admin, erro de validação vira redirecionamento até em pedido JSON
     * (bootstrap/app.php só responde JSON em api/*). A tela de campanha
     * salva por fetch, então aqui o erro volta como 422 com os campos.
     *
     * @template T
     *
     * @param  callable(): T  $validar
     * @return T
     */
    private function comErrosEmJson(Request $request, callable $validar): mixed
    {
        try {
            return $validar();
        } catch (ValidationException $erro) {
            if ($request->wantsJson()) {
                throw new HttpResponseException(response()->json(['message' => $erro->getMessage(), 'errors' => $erro->errors()], 422));
            }

            throw $erro;
        }
    }

    /** @return array<string, mixed> */
    private function validar(Request $request): array
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:200'],
            'objetivo' => ['required', Rule::in(array_keys(MetaAdsCampanha::OBJETIVOS))],
            'estrutura' => ['sometimes', Rule::in(array_keys(MetaAdsCampanha::ESTRUTURAS))],
            'orcamento_diario' => ['required', 'numeric', 'min:1', 'max:10000'],
            'inicio' => ['required', 'date'],
            'fim' => ['nullable', 'date', 'after_or_equal:inicio'],
            'idade_min' => ['required', 'integer', 'min:18', 'max:65'],
            'idade_max' => ['required', 'integer', 'min:18', 'max:65', 'gte:idade_min'],
            'genero' => ['required', Rule::in(array_keys(MetaAdsCampanha::GENEROS))],
            'localizacoes' => ['nullable', 'array'],
            'localizacoes.regioes' => ['nullable', 'array', 'max:30'],
            'localizacoes.regioes.*.key' => ['required', 'string', 'max:40'],
            'localizacoes.regioes.*.nome' => ['required', 'string', 'max:120'],
            'localizacoes.cidades' => ['nullable', 'array', 'max:50'],
            'localizacoes.cidades.*.key' => ['required', 'string', 'max:40'],
            'localizacoes.cidades.*.nome' => ['required', 'string', 'max:160'],
            'localizacoes.cidades.*.raio_km' => ['nullable', 'integer', 'min:10', 'max:80'],
            'produto_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'link_destino' => ['nullable', 'url:https', 'max:500'],
            // Padrão dos anúncios: cada anúncio pode ter o seu (o envio
            // confere que todo anúncio termina com texto e título).
            'texto_principal' => ['nullable', 'string', 'max:2000'],
            'titulo' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'chamada' => ['required', Rule::in(array_keys(MetaAdsCampanha::CHAMADAS))],
        ], [
            'idade_max.gte' => 'A idade máxima tem que ser maior ou igual à mínima.',
            'link_destino.url' => 'Use um link https da loja.',
        ]);

        $produto = filled($dados['produto_id'] ?? null) ? Product::query()->find($dados['produto_id']) : null;

        if (blank($dados['link_destino'] ?? null)) {
            $dados['link_destino'] = $produto ? self::linkDoProduto($produto) : self::siteUrl().'/';
        }

        self::exigirLinkDaLoja($dados['link_destino']);

        $dados['localizacoes'] = [
            'regioes' => array_values($dados['localizacoes']['regioes'] ?? []),
            'cidades' => array_values($dados['localizacoes']['cidades'] ?? []),
        ];

        return $dados;
    }

    /**
     * Produto e texto de um anúncio. Tudo opcional: vazio usa o padrão da
     * campanha. Com produto e sem link, o link vira a página do produto.
     *
     * @return array<string, mixed>
     */
    private function validarAnuncio(Request $request): array
    {
        $dados = $request->validate([
            'produto_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'link_destino' => ['nullable', 'url:https', 'max:500'],
            'texto_principal' => ['nullable', 'string', 'max:2000'],
            'titulo' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'chamada' => ['nullable', Rule::in(array_keys(MetaAdsCampanha::CHAMADAS))],
        ], [
            'link_destino.url' => 'Use um link https da loja.',
        ]);

        $dados = array_map(fn ($valor) => $valor === '' ? null : $valor, $dados + array_fill_keys(
            ['produto_id', 'link_destino', 'texto_principal', 'titulo', 'descricao', 'chamada'], null,
        ));

        if (blank($dados['link_destino']) && filled($dados['produto_id'])) {
            $dados['link_destino'] = self::linkDoProduto(Product::query()->findOrFail($dados['produto_id']));
        }

        if (filled($dados['link_destino'])) {
            self::exigirLinkDaLoja($dados['link_destino']);
        }

        return $dados;
    }

    /** Anúncio aponta só para a loja de verdade (nunca homolog/.com.br). */
    private static function exigirLinkDaLoja(string $link): void
    {
        $host = parse_url($link, PHP_URL_HOST);
        if (! in_array($host, ['kazakora.com', 'www.kazakora.com'], true)) {
            throw ValidationException::withMessages(['link_destino' => 'O link tem que ser da loja: https://kazakora.com/...']);
        }
    }

    /** @return array<string, mixed> */
    private function dadosDoFormulario(MetaAdsService $meta, ?MetaAdsCampanha $campanha): array
    {
        $produtos = Product::query()
            ->where('is_active', true)
            ->whereNull('parent_product_id')
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'nome' => $p->name,
                'link' => self::linkDoProduto($p),
            ]);

        return [
            'campanha' => $campanha?->makeHidden('videos'),
            'videos' => $campanha ? $campanha->videos->map(fn (MetaAdsVideo $v) => $this->dadosDoVideo($campanha, $v))->values() : [],
            'limites' => [
                'max_videos' => MetaAdsCampanha::MAX_VIDEOS,
                'max_mb' => intdiv(self::MAX_KILOBYTES, 1024),
                'minimo_por_video' => MetaAdsCampanha::MINIMO_POR_VIDEO,
            ],
            'produtos' => $produtos,
            'config' => $this->estadoConfig($meta),
            'objetivos' => MetaAdsCampanha::OBJETIVOS,
            'generos' => MetaAdsCampanha::GENEROS,
            'chamadas' => MetaAdsCampanha::CHAMADAS,
            'estruturas' => MetaAdsCampanha::ESTRUTURAS,
            'siteUrl' => self::siteUrl(),
        ];
    }

    /** @return array{configurado: bool, faltando: list<string>, pixel: bool} */
    private function estadoConfig(MetaAdsService $meta): array
    {
        return [
            'configurado' => $meta->configurado(),
            'faltando' => $meta->faltando(),
            'pixel' => $meta->pixelConfigurado(),
        ];
    }

    private static function siteUrl(): string
    {
        return rtrim((string) config('services.meta_ads.site_url', 'https://kazakora.com'), '/');
    }

    public static function linkDoProduto(Product $produto): string
    {
        return self::siteUrl().'/produtos/'.$produto->slug;
    }

    private static function pastaDosVideos(MetaAdsCampanha $campanha): string
    {
        return 'meta-ads/campanha-'.$campanha->id;
    }

    /** @return array<string, mixed> */
    private function dadosDoVideo(MetaAdsCampanha $campanha, MetaAdsVideo $video): array
    {
        return [
            'id' => $video->id,
            'ordem' => $video->ordem,
            'tipo' => $video->tipo,
            'nome' => $video->nome_original,
            'tamanho_mb' => round($video->tamanho / 1048576, 1),
            'duracao_segundos' => $video->duracao_segundos !== null ? (float) $video->duracao_segundos : null,
            'url' => route('admin.publicidade.meta-ads.campanhas.videos.arquivo', [$campanha, $video]),
            'capa' => $video->meta_thumbnail_url,
            'etapa' => $video->etapa(),
            'enviado' => $video->envioCompleto(),
            'ultimo_erro' => $video->ultimo_erro,
            'produto_id' => $video->produto_id,
            'link_destino' => $video->link_destino,
            'texto_principal' => $video->texto_principal,
            'titulo' => $video->titulo,
            'descricao' => $video->descricao,
            'chamada' => $video->chamada,
            // O que de fato vai no criativo (próprio ou padrão da campanha).
            'criativo' => $video->criativo($campanha),
        ];
    }
}
