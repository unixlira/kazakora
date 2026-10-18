<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Jobs\EnviarCampanhaMetaAds;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsConjunto;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use App\Modules\Marketplace\Support\DuracaoDeVideo;
use App\Services\Meta\MetaAdsException;
use App\Services\Meta\MetaAdsService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Meta ADS (2026-10-13): campanhas montadas no admin e enviadas pela
 * Marketing API da Meta (MetaAdsService). Desde 2026-10-18 a tela monta a
 * campanha inteira como no Gerenciador: vários conjuntos com nome (e
 * público próprio, opcional) e vários anúncios em cada, cada anúncio com
 * vídeo ou imagem, produto e texto próprios. Salvar grava campanha,
 * conjuntos e texto de todos os anúncios num pedido só (transação); os
 * arquivos novos sobem um por um depois, com barra de progresso. Envio cria
 * tudo PAUSADO; ativar é outro botão, com confirmação explícita do gasto.
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
            ->withCount(['videos', 'conjuntos'])
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
                'conjuntos' => $c->conjuntos_count,
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
     * A tela salva o rascunho por fetch (JSON), com os conjuntos e o texto
     * dos anúncios já guardados, e em seguida sobe os arquivos novos um a um
     * em videoStore(), com barra de progresso.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $dados = $this->comErrosEmJson($request, fn () => $this->validar($request, null));

        [$campanha, $conjuntos] = DB::transaction(function () use ($dados, $request) {
            $campanha = MetaAdsCampanha::create($dados['campanha'] + [
                'status' => MetaAdsCampanha::STATUS_RASCUNHO,
                'user_id' => $request->user()->id,
            ]);

            return [$campanha, $this->gravarEstrutura($campanha, $dados)];
        });

        if ($request->wantsJson()) {
            return response()->json(['id' => $campanha->id, 'conjuntos' => $conjuntos], 201);
        }

        return redirect()->route('admin.publicidade.meta-ads.campanhas.ver', $campanha)->with('success', 'Rascunho salvo.');
    }

    public function show(MetaAdsCampanha $campanha, MetaAdsService $meta): Response
    {
        $campanha->load(['produto:id,name,slug', 'user:id,name', 'conjuntos', 'videos']);

        $desempenho = [];
        $erroDesempenho = null;
        if (filled($campanha->meta_campaign_id) && $meta->configurado()) {
            try {
                $desempenho = $meta->desempenhoPorAnuncio($campanha);
            } catch (MetaAdsException $erro) {
                $erroDesempenho = $erro->getMessage();
            }
        }

        return Inertia::render('Admin/MetaAds/Show', [
            'campanha' => $campanha->makeHidden(['videos', 'conjuntos']),
            'conjuntos' => $campanha->conjuntos->map(fn (MetaAdsConjunto $c) => $this->dadosDoConjunto($campanha, $c))->values(),
            'videos' => $campanha->videos->values()->map(fn (MetaAdsVideo $v) => $this->dadosDoVideo($campanha, $v) + [
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
            'orcamentoTipos' => MetaAdsCampanha::ORCAMENTO_TIPOS,
        ]);
    }

    public function edit(MetaAdsCampanha $campanha, MetaAdsService $meta): Response|RedirectResponse
    {
        if (! $campanha->podeEditar()) {
            return redirect()->route('admin.publicidade.meta-ads.campanhas.ver', $campanha)
                ->with('error', 'Campanha já enviada à Meta. Edite pelo Gerenciador de Anúncios.');
        }

        $campanha->load(['conjuntos', 'videos']);

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

        $campanha->load(['conjuntos', 'videos']);
        $dados = $this->comErrosEmJson($request, fn () => $this->validar($request, $campanha));

        $conjuntos = DB::transaction(function () use ($campanha, $dados) {
            $campanha->update($dados['campanha']);

            return $this->gravarEstrutura($campanha, $dados);
        });

        if ($request->wantsJson()) {
            return response()->json(['id' => $campanha->id, 'conjuntos' => $conjuntos]);
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

        $campanha->load(['conjuntos', 'videos']);

        if ($problemas = $meta->problemasParaEnviar($campanha)) {
            return back()->with('error', implode(' ', $problemas));
        }

        $campanha->forceFill(['status' => MetaAdsCampanha::STATUS_ENVIANDO, 'ultimo_erro' => null])->save();
        $campanha->videos()->update(['ultimo_erro' => null]);
        EnviarCampanhaMetaAds::dispatch($campanha->id);

        return back()->with('success', 'Enviando para a Meta. A campanha, os conjuntos e os anúncios são criados um por um e a Meta leva alguns minutos para processar os vídeos. Tudo fica PAUSADO até você ativar.');
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
            return response()->json(['message' => 'Limite de '.MetaAdsCampanha::MAX_VIDEOS.' anúncios por campanha.'], 422);
        }

        $conjunto = $this->conjuntoDoAnuncio($request, $campanha);

        $arquivo = $request->file('video');
        $imagem = in_array($arquivo->getMimeType(), self::TIPOS_IMAGEM, true);
        $path = $arquivo->store(self::pastaDosVideos($campanha), 'local');

        if ($path === false) {
            return response()->json(['message' => 'Não consegui guardar o arquivo no servidor.'], 500);
        }

        $video = $campanha->videos()->create($textos + [
            'conjunto_id' => $conjunto->id,
            'ordem' => $request->filled('ordem') ? (int) $request->input('ordem') : $this->proximaOrdem($campanha),
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

        $dados = $this->comErrosEmJson($request, fn () => $this->validarAnuncio($request));
        if ($request->filled('conjunto_id')) {
            $dados['conjunto_id'] = $this->conjuntoDoAnuncio($request, $campanha)->id;
        }
        $video->update($dados);

        return response()->json(['video' => $this->dadosDoVideo($campanha, $video)]);
    }

    /**
     * Duplicar anúncio já guardado (ou o conjunto inteiro, um por um): copia
     * o arquivo no servidor, sem subir de novo, com o texto que a tela mandar.
     */
    public function videoDuplicar(Request $request, MetaAdsCampanha $campanha, MetaAdsVideo $video): JsonResponse
    {
        abort_unless($video->campanha_id === $campanha->id, 404);

        if (! $campanha->podeMexerNosVideos()) {
            return response()->json(['message' => 'Campanha já enviada à Meta: os anúncios não mudam mais por aqui.'], 409);
        }

        if ($campanha->videos()->count() >= MetaAdsCampanha::MAX_VIDEOS) {
            return response()->json(['message' => 'Limite de '.MetaAdsCampanha::MAX_VIDEOS.' anúncios por campanha.'], 422);
        }

        $textos = $this->comErrosEmJson($request, fn () => $this->validarAnuncio($request));
        $conjunto = $this->conjuntoDoAnuncio($request, $campanha);

        $disco = Storage::disk('local');
        if (! $disco->exists($video->path)) {
            return response()->json(['message' => 'O arquivo original sumiu do servidor. Envie de novo.'], 422);
        }

        $path = self::pastaDosVideos($campanha).'/'.Str::random(40).'.'.pathinfo($video->path, PATHINFO_EXTENSION);
        $disco->copy($video->path, $path);

        $copia = $campanha->videos()->create($textos + [
            'conjunto_id' => $conjunto->id,
            'ordem' => $request->filled('ordem') ? (int) $request->input('ordem') : $this->proximaOrdem($campanha),
            'tipo' => $video->tipo,
            'nome_original' => $video->nome_original,
            'path' => $path,
            'tamanho' => $video->tamanho,
            'duracao_segundos' => $video->duracao_segundos,
        ]);

        return response()->json(['video' => $this->dadosDoVideo($campanha, $copia)], 201);
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

    /**
     * Campanha, conjuntos e texto dos anúncios já guardados, tudo validado
     * antes de gravar qualquer coisa.
     *
     * @return array{campanha: array<string, mixed>, conjuntos: ?list<array<string, mixed>>, anuncios: ?list<array<string, mixed>>}
     */
    private function validar(Request $request, ?MetaAdsCampanha $campanha): array
    {
        $regrasPublico = fn (string $p) => [
            "{$p}idade_min" => ['integer', 'min:18', 'max:65'],
            "{$p}idade_max" => ['integer', 'min:18', 'max:65'],
            "{$p}genero" => [Rule::in(array_keys(MetaAdsCampanha::GENEROS))],
            "{$p}localizacoes" => ['nullable', 'array'],
            "{$p}localizacoes.regioes" => ['nullable', 'array', 'max:30'],
            "{$p}localizacoes.regioes.*.key" => ['required', 'string', 'max:40'],
            "{$p}localizacoes.regioes.*.nome" => ['required', 'string', 'max:120'],
            "{$p}localizacoes.cidades" => ['nullable', 'array', 'max:50'],
            "{$p}localizacoes.cidades.*.key" => ['required', 'string', 'max:40'],
            "{$p}localizacoes.cidades.*.nome" => ['required', 'string', 'max:160'],
            "{$p}localizacoes.cidades.*.raio_km" => ['nullable', 'integer', 'min:10', 'max:80'],
        ];
        $porConjunto = $request->input('orcamento_tipo') === MetaAdsCampanha::ORCAMENTO_CONJUNTO;

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:200'],
            'objetivo' => ['required', Rule::in(array_keys(MetaAdsCampanha::OBJETIVOS))],
            'orcamento_tipo' => ['sometimes', Rule::in(array_keys(MetaAdsCampanha::ORCAMENTO_TIPOS))],
            'orcamento_diario' => [$porConjunto ? 'nullable' : 'required', 'numeric', 'min:1', 'max:10000'],
            'inicio' => ['required', 'date'],
            'fim' => ['nullable', 'date', 'after_or_equal:inicio'],
            'idade_min' => ['required', 'integer', 'min:18', 'max:65'],
            'idade_max' => ['required', 'integer', 'min:18', 'max:65', 'gte:idade_min'],
            'genero' => ['required', Rule::in(array_keys(MetaAdsCampanha::GENEROS))],
            ...$regrasPublico(''),
            'produto_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'link_destino' => ['nullable', 'url:https', 'max:500'],
            // Padrão dos anúncios: cada anúncio pode ter o seu (o envio
            // confere que todo anúncio termina com texto e título).
            'texto_principal' => ['nullable', 'string', 'max:2000'],
            'titulo' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'chamada' => ['required', Rule::in(array_keys(MetaAdsCampanha::CHAMADAS))],

            // Conjuntos: a tela manda todos; o que não vier é removido.
            'conjuntos' => ['sometimes', 'array', 'min:1', 'max:'.MetaAdsCampanha::MAX_CONJUNTOS],
            'conjuntos.*.id' => ['nullable', 'integer'],
            'conjuntos.*.chave' => ['required', 'string', 'max:60', 'distinct'],
            'conjuntos.*.nome' => ['required', 'string', 'max:120'],
            'conjuntos.*.orcamento_diario' => [$porConjunto ? 'required' : 'nullable', 'numeric', 'min:'.MetaAdsCampanha::MINIMO_POR_CONJUNTO, 'max:10000'],
            'conjuntos.*.publico_proprio' => ['boolean'],
            ...$regrasPublico('conjuntos.*.'),

            // Anúncios já guardados: conjunto, ordem e texto. O que não vier é removido.
            'anuncios' => ['sometimes', 'array', 'max:'.MetaAdsCampanha::MAX_VIDEOS],
            'anuncios.*.id' => ['required', 'integer', 'distinct'],
            'anuncios.*.conjunto' => ['required', 'string', 'max:60'],
            'anuncios.*.ordem' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'anuncios.*.produto_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'anuncios.*.link_destino' => ['nullable', 'url:https', 'max:500'],
            'anuncios.*.texto_principal' => ['nullable', 'string', 'max:2000'],
            'anuncios.*.titulo' => ['nullable', 'string', 'max:255'],
            'anuncios.*.descricao' => ['nullable', 'string', 'max:255'],
            'anuncios.*.chamada' => ['nullable', Rule::in(array_keys(MetaAdsCampanha::CHAMADAS))],
        ], [
            'nome.required' => 'Dê um nome para a campanha.',
            'idade_max.gte' => 'A idade máxima tem que ser maior ou igual à mínima.',
            'fim.after_or_equal' => 'A data de fim tem que ser depois do início.',
            'link_destino.url' => 'Use um link https da loja.',
            'conjuntos.max' => 'Limite de '.MetaAdsCampanha::MAX_CONJUNTOS.' conjuntos por campanha.',
            'conjuntos.min' => 'A campanha precisa de pelo menos um conjunto.',
            'conjuntos.*.nome.required' => 'Dê um nome para o conjunto.',
            'conjuntos.*.orcamento_diario.required' => 'Informe o orçamento por dia deste conjunto.',
            'conjuntos.*.orcamento_diario.min' => 'Mínimo de R$ '.number_format(MetaAdsCampanha::MINIMO_POR_CONJUNTO, 2, ',', '.').' por dia neste conjunto.',
            'anuncios.max' => 'Limite de '.MetaAdsCampanha::MAX_VIDEOS.' anúncios por campanha.',
            'anuncios.*.link_destino.url' => 'Use um link https da loja.',
        ]);

        $produto = filled($dados['produto_id'] ?? null) ? Product::query()->find($dados['produto_id']) : null;

        if (blank($dados['link_destino'] ?? null)) {
            $dados['link_destino'] = $produto ? self::linkDoProduto($produto) : self::siteUrl().'/';
        }

        self::exigirLinkDaLoja($dados['link_destino']);

        $dados['localizacoes'] = self::normalizarLocalizacoes($dados['localizacoes'] ?? []);
        $dados['orcamento_tipo'] ??= $campanha?->orcamento_tipo ?? MetaAdsCampanha::ORCAMENTO_CAMPANHA;

        $conjuntos = isset($dados['conjuntos']) ? $this->normalizarConjuntos($dados['conjuntos'], $campanha) : null;
        $anuncios = isset($dados['anuncios']) ? $this->normalizarAnuncios($dados['anuncios'], $campanha, $conjuntos) : null;

        // Por conjunto, o total da campanha é a soma (é o que a tela de ativar confirma).
        if ($dados['orcamento_tipo'] === MetaAdsCampanha::ORCAMENTO_CONJUNTO) {
            $soma = $conjuntos !== null
                ? array_sum(array_map(fn ($c) => (float) $c['orcamento_diario'], $conjuntos))
                : (float) ($campanha?->conjuntos->sum('orcamento_diario') ?? 0);
            $dados['orcamento_diario'] = round($soma, 2) ?: (float) ($dados['orcamento_diario'] ?? 0);
        }

        if ((float) ($dados['orcamento_diario'] ?? 0) < 1) {
            throw ValidationException::withMessages(['orcamento_diario' => 'Informe o orçamento por dia.']);
        }

        unset($dados['conjuntos'], $dados['anuncios']);

        return ['campanha' => $dados, 'conjuntos' => $conjuntos, 'anuncios' => $anuncios];
    }

    /** @return array{regioes: list<array>, cidades: list<array>} */
    private static function normalizarLocalizacoes(?array $local): array
    {
        return [
            'regioes' => array_values($local['regioes'] ?? []),
            'cidades' => array_values($local['cidades'] ?? []),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $conjuntos
     * @return list<array<string, mixed>>
     */
    private function normalizarConjuntos(array $conjuntos, ?MetaAdsCampanha $campanha): array
    {
        return array_map(function (array $c, int $i) use ($campanha) {
            if (filled($c['id'] ?? null) && ! $campanha?->conjuntos->contains('id', (int) $c['id'])) {
                throw ValidationException::withMessages(["conjuntos.{$i}.id" => 'Conjunto de outra campanha. Recarregue a tela.']);
            }

            $proprio = (bool) ($c['publico_proprio'] ?? false);
            if ($proprio && (int) ($c['idade_max'] ?? 65) < (int) ($c['idade_min'] ?? 18)) {
                throw ValidationException::withMessages(["conjuntos.{$i}.idade_max" => 'A idade máxima tem que ser maior ou igual à mínima.']);
            }

            return [
                'id' => filled($c['id'] ?? null) ? (int) $c['id'] : null,
                'chave' => (string) $c['chave'],
                'ordem' => $i,
                'nome' => trim((string) $c['nome']),
                'orcamento_diario' => isset($c['orcamento_diario']) ? round((float) $c['orcamento_diario'], 2) : null,
                'publico_proprio' => $proprio,
                'idade_min' => $proprio ? (int) ($c['idade_min'] ?? 18) : null,
                'idade_max' => $proprio ? (int) ($c['idade_max'] ?? 65) : null,
                'genero' => $proprio ? ($c['genero'] ?? 'todos') : null,
                'localizacoes' => $proprio ? self::normalizarLocalizacoes($c['localizacoes'] ?? []) : null,
            ];
        }, array_values($conjuntos), array_keys(array_values($conjuntos)));
    }

    /**
     * @param  list<array<string, mixed>>  $anuncios
     * @param  ?list<array<string, mixed>>  $conjuntos
     * @return list<array<string, mixed>>
     */
    private function normalizarAnuncios(array $anuncios, ?MetaAdsCampanha $campanha, ?array $conjuntos): array
    {
        $chaves = $conjuntos !== null
            ? array_column($conjuntos, 'chave')
            : ($campanha?->conjuntos->map(fn (MetaAdsConjunto $c) => (string) $c->id)->all() ?? []);

        return array_map(function (array $a, int $i) use ($campanha, $chaves) {
            $video = $campanha?->videos->firstWhere('id', (int) $a['id']);
            if (! $video) {
                throw ValidationException::withMessages(["anuncios.{$i}.id" => 'Anúncio de outra campanha. Recarregue a tela.']);
            }
            if ($video->temObjetoNoMeta()) {
                throw ValidationException::withMessages(["anuncios.{$i}.id" => 'Anúncio já enviado à Meta: edite pelo Gerenciador de Anúncios.']);
            }
            if (! in_array((string) $a['conjunto'], $chaves, true)) {
                throw ValidationException::withMessages(["anuncios.{$i}.conjunto" => 'Conjunto do anúncio não existe mais. Recarregue a tela.']);
            }

            return ['id' => $video->id, 'conjunto' => (string) $a['conjunto'], 'ordem' => (int) ($a['ordem'] ?? $i)]
                + $this->normalizarAnuncio($a, "anuncios.{$i}.");
        }, array_values($anuncios), array_keys(array_values($anuncios)));
    }

    /**
     * Grava conjuntos (cria, atualiza, remove os que saíram com os seus
     * anúncios) e o texto dos anúncios já guardados. Campanha sem conjunto
     * ganha o "Conjunto 1". Devolve chave da tela => id do conjunto.
     *
     * @param  array{conjuntos: ?list<array<string, mixed>>, anuncios: ?list<array<string, mixed>>}  $dados
     * @return array<string, int>
     */
    private function gravarEstrutura(MetaAdsCampanha $campanha, array $dados): array
    {
        $mapa = [];

        if ($dados['conjuntos'] !== null) {
            $manter = [];
            foreach ($dados['conjuntos'] as $c) {
                $campos = collect($c)->except(['id', 'chave'])->all();
                $conjunto = $c['id']
                    ? tap($campanha->conjuntos()->findOrFail($c['id']))->update($campos)
                    : $campanha->conjuntos()->create($campos);
                $mapa[$c['chave']] = $conjunto->id;
                $manter[] = $conjunto->id;
            }

            $saindo = $campanha->conjuntos()->whereNotIn('id', $manter)->pluck('id');
            if ($saindo->isNotEmpty()) {
                $this->apagarAnuncios($campanha->videos()->whereIn('conjunto_id', $saindo)->get());
                $campanha->conjuntos()->whereIn('id', $saindo)->delete();
            }
        } elseif (! $campanha->conjuntos()->exists()) {
            $mapa['1'] = $campanha->conjuntos()->create(['nome' => 'Conjunto 1', 'ordem' => 0])->id;
        } else {
            $mapa = $campanha->conjuntos()->pluck('id')->mapWithKeys(fn ($id) => [(string) $id => $id])->all();
        }

        if ($dados['anuncios'] !== null) {
            foreach ($dados['anuncios'] as $a) {
                MetaAdsVideo::query()->whereKey($a['id'])->update(
                    collect($a)->except(['id', 'conjunto'])->all() + ['conjunto_id' => $mapa[$a['conjunto']]],
                );
            }

            $this->apagarAnuncios($campanha->videos()->whereNotIn('id', array_column($dados['anuncios'], 'id'))->get());
        }

        return $mapa;
    }

    /** @param  iterable<MetaAdsVideo>  $videos */
    private function apagarAnuncios(iterable $videos): void
    {
        foreach ($videos as $video) {
            if ($video->temObjetoNoMeta()) {
                throw ValidationException::withMessages(['anuncios' => 'Anúncio já enviado à Meta não sai por aqui: use o Gerenciador de Anúncios.']);
            }
            Storage::disk('local')->delete($video->path);
            $video->delete();
        }
    }

    /** Conjunto pedido (tem que ser da campanha) ou o primeiro; sem nenhum, cria o "Conjunto 1". */
    private function conjuntoDoAnuncio(Request $request, MetaAdsCampanha $campanha): MetaAdsConjunto
    {
        if ($request->filled('conjunto_id')) {
            $conjunto = $campanha->conjuntos()->find((int) $request->input('conjunto_id'));
            if (! $conjunto) {
                throw new HttpResponseException(response()->json(['message' => 'Conjunto não encontrado. Recarregue a tela.', 'errors' => ['conjunto_id' => ['Conjunto não encontrado. Recarregue a tela.']]], 422));
            }

            return $conjunto;
        }

        return $campanha->conjuntos()->first() ?? $campanha->conjuntos()->create(['nome' => 'Conjunto 1', 'ordem' => 0]);
    }

    private function proximaOrdem(MetaAdsCampanha $campanha): int
    {
        return $campanha->videos()->exists() ? (int) $campanha->videos()->max('ordem') + 1 : 0;
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

        return $this->normalizarAnuncio($dados);
    }

    /**
     * Vazio vira null (= padrão da campanha); com produto e sem link, o link
     * vira a página do produto; link só da loja.
     *
     * @param  array<string, mixed>  $dados
     * @return array{produto_id: mixed, link_destino: ?string, texto_principal: ?string, titulo: ?string, descricao: ?string, chamada: ?string}
     */
    private function normalizarAnuncio(array $dados, string $prefixo = ''): array
    {
        $campos = ['produto_id', 'link_destino', 'texto_principal', 'titulo', 'descricao', 'chamada'];
        $dados = array_map(fn ($valor) => $valor === '' ? null : $valor, array_intersect_key($dados, array_flip($campos)) + array_fill_keys($campos, null));

        if (blank($dados['link_destino']) && filled($dados['produto_id'])) {
            $dados['link_destino'] = self::linkDoProduto(Product::query()->findOrFail($dados['produto_id']));
        }

        if (filled($dados['link_destino'])) {
            self::exigirLinkDaLoja($dados['link_destino'], $prefixo.'link_destino');
        }

        return $dados;
    }

    /** Anúncio aponta só para a loja de verdade (nunca homolog/.com.br). */
    private static function exigirLinkDaLoja(string $link, string $campo = 'link_destino'): void
    {
        $host = parse_url($link, PHP_URL_HOST);
        if (! in_array($host, ['kazakora.com', 'www.kazakora.com'], true)) {
            throw ValidationException::withMessages([$campo => 'Use um link de https://kazakora.com']);
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
            'campanha' => $campanha?->makeHidden(['videos', 'conjuntos']),
            'conjuntos' => $campanha ? $campanha->conjuntos->map(fn (MetaAdsConjunto $c) => $this->dadosDoConjunto($campanha, $c))->values() : [],
            'videos' => $campanha ? $campanha->videos->map(fn (MetaAdsVideo $v) => $this->dadosDoVideo($campanha, $v))->values() : [],
            'limites' => [
                'max_videos' => MetaAdsCampanha::MAX_VIDEOS,
                'max_conjuntos' => MetaAdsCampanha::MAX_CONJUNTOS,
                'recomendado_por_conjunto' => MetaAdsCampanha::RECOMENDADO_POR_CONJUNTO,
                'max_mb' => intdiv(self::MAX_KILOBYTES, 1024),
                'minimo_por_conjunto' => MetaAdsCampanha::MINIMO_POR_CONJUNTO,
            ],
            'produtos' => $produtos,
            'config' => $this->estadoConfig($meta),
            'objetivos' => MetaAdsCampanha::OBJETIVOS,
            'generos' => MetaAdsCampanha::GENEROS,
            'chamadas' => MetaAdsCampanha::CHAMADAS,
            'orcamentoTipos' => MetaAdsCampanha::ORCAMENTO_TIPOS,
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
    private function dadosDoConjunto(MetaAdsCampanha $campanha, MetaAdsConjunto $conjunto): array
    {
        return [
            'id' => $conjunto->id,
            'nome' => $conjunto->nome,
            'ordem' => $conjunto->ordem,
            'orcamento_diario' => $conjunto->orcamento_diario !== null ? (float) $conjunto->orcamento_diario : null,
            'publico_proprio' => $conjunto->publico_proprio,
            'idade_min' => $conjunto->idade_min,
            'idade_max' => $conjunto->idade_max,
            'genero' => $conjunto->genero,
            'localizacoes' => $conjunto->localizacoes,
            // O que de fato vai para a Meta (próprio ou da campanha).
            'publico' => $conjunto->publico($campanha),
            'meta_adset_id' => $conjunto->meta_adset_id,
            'ultimo_erro' => $conjunto->ultimo_erro,
        ];
    }

    /** @return array<string, mixed> */
    private function dadosDoVideo(MetaAdsCampanha $campanha, MetaAdsVideo $video): array
    {
        return [
            'id' => $video->id,
            'conjunto_id' => $video->conjunto_id,
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
