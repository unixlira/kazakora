<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Jobs\EnviarCampanhaMetaAds;
use App\Modules\Marketplace\Jobs\TrocarCriativoMetaAds;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsConjunto;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use App\Modules\Marketplace\Support\DuracaoDeVideo;
use App\Modules\Marketplace\Support\FormatoDeVideo;
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
 *
 * Desde 2026-10-19 (pedido do Lira) dá para criar conjunto novo dentro de
 * campanha que já está na Meta: o conjunto é montado à parte e sobe
 * sozinho, pausado, sem reenviar nem pausar o resto da campanha. Do mesmo
 * jeito, dá para acrescentar anúncio novo em conjunto que já está na Meta.
 */
class MetaAdsController extends Controller
{
    /** 60 MB: o servidor aceita até 64 MB por requisição (nginx e PHP). */
    private const MAX_KILOBYTES = 61440;

    private const TIPOS_VIDEO = ['video/mp4', 'video/quicktime'];

    private const TIPOS_IMAGEM = ['image/jpeg', 'image/png'];

    public function index(Request $request, MetaAdsService $meta): Response
    {
        $periodo = $this->periodo($request);

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
                'novo_conjunto' => $c->podeAdicionarConjunto() && $c->conjuntos_count < MetaAdsCampanha::MAX_CONJUNTOS,
                'ultimo_erro' => $c->ultimo_erro,
                'criada_por' => $c->user?->name,
            ]);

        return Inertia::render('Admin/MetaAds/Index', [
            'campanhas' => $campanhas,
            'config' => $this->estadoConfig($meta),
            'periodo' => $periodo,
            'periodos' => MetaAdsService::PERIODOS,
            'espelho' => Inertia::defer(fn () => $this->espelho($meta, $periodo, null, $this->noKazaKora())),
            // Editar anúncio pelo espelho (2026-10-19).
            'produtos' => $this->listaDeProdutos(),
            'chamadas' => MetaAdsCampanha::CHAMADAS,
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

    public function show(Request $request, MetaAdsCampanha $campanha, MetaAdsService $meta): Response
    {
        $periodo = $this->periodo($request);

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
            'conjuntos' => $campanha->conjuntos->map(fn (MetaAdsConjunto $c) => $this->dadosDoConjunto($campanha, $c) + [
                'pode' => [
                    'acrescentarAnuncios' => $c->aceitaAnuncioNovo($campanha) && $campanha->videos->count() < MetaAdsCampanha::MAX_VIDEOS,
                    'editar' => $c->aceitaMudanca($campanha),
                    'enviar' => $c->podeEnviar($campanha),
                    'ativar' => $c->podeAtivar($campanha),
                ],
                'problemas' => $c->podeEnviar($campanha) ? $meta->problemasDoConjuntoNovo($campanha, $c) : [],
            ])->values(),
            'videos' => $campanha->videos->values()->map(fn (MetaAdsVideo $v) => $this->dadosDoVideo($campanha, $v) + [
                'desempenho' => $v->meta_ad_id ? ($desempenho[$v->meta_ad_id] ?? null) : null,
            ]),
            'erroDesempenho' => $erroDesempenho,
            'periodo' => $periodo,
            'periodos' => MetaAdsService::PERIODOS,
            'espelho' => Inertia::defer(fn () => filled($campanha->meta_campaign_id)
                ? $this->espelho($meta, $periodo, (string) $campanha->meta_campaign_id, $this->noKazaKora($campanha->id))
                : ['campanhas' => [], 'erro' => null]),
            'config' => $this->estadoConfig($meta),
            'problemas' => $campanha->podeEnviar() ? $meta->problemasParaEnviar($campanha) : [],
            'pode' => [
                'editar' => $campanha->podeEditar(),
                'enviar' => $campanha->podeEnviar(),
                'ativar' => $campanha->podeAtivar(),
                'pausar' => $campanha->podePausar(),
                'excluir' => $campanha->podeExcluir(),
                'mexerNosVideos' => $campanha->podeMexerNosVideos(),
                'adicionarConjunto' => $campanha->podeAdicionarConjunto() && $campanha->conjuntos->count() < MetaAdsCampanha::MAX_CONJUNTOS,
            ],
            'linkGerenciador' => $campanha->meta_campaign_id ? $meta->linkGerenciador($campanha) : null,
            'chamadas' => MetaAdsCampanha::CHAMADAS,
            'generos' => MetaAdsCampanha::GENEROS,
            'orcamentoTipos' => MetaAdsCampanha::ORCAMENTO_TIPOS,
            'produtos' => $this->listaDeProdutos(),
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
        $campanha->forceDelete();

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
    public function videoStore(Request $request, MetaAdsCampanha $campanha, DuracaoDeVideo $duracao, FormatoDeVideo $formato): JsonResponse
    {
        if (! $this->podeMexerNoConjunto($campanha, $request->input('conjunto_id'))) {
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
            'adicionado_depois' => ! $campanha->podeMexerNosVideos(),
            'ordem' => $request->filled('ordem') ? (int) $request->input('ordem') : $this->proximaOrdem($campanha),
            'tipo' => $imagem ? MetaAdsVideo::TIPO_IMAGEM : MetaAdsVideo::TIPO_VIDEO,
            'nome_original' => mb_substr($arquivo->getClientOriginalName() ?: ($imagem ? 'imagem.jpg' : 'video.mp4'), 0, 250),
            'path' => $path,
            'tamanho' => (int) $arquivo->getSize(),
            'duracao_segundos' => $imagem ? null : $duracao->segundos(Storage::disk('local')->path($path)),
        ] + ($imagem ? [] : ($formato->medir(Storage::disk('local')->path($path)) ?? [])));

        return response()->json(['video' => $this->dadosDoVideo($campanha, $video)], 201);
    }

    /**
     * Editar um anúncio (2026-10-19): nome, produto, link, texto, título,
     * descrição, botão e, opcional, o arquivo (multipart com _method=PUT).
     * O conjunto não muda depois que o anúncio foi enviado.
     *
     * - Só no KazaKora: grava direto (arquivo novo zera o que já tinha subido
     *   dele, para o envio subir o novo).
     * - Na Meta: só o nome = POST no ad com name; texto, link, botão ou
     *   arquivo = criativo novo e o ad passa a usar ele (volta para a análise
     *   da Meta). Os campos locais só mudam depois que a Meta aceita. Vídeo
     *   novo espera a Meta processar, em segundo plano (TrocarCriativoMetaAds).
     */
    public function videoUpdate(Request $request, MetaAdsCampanha $campanha, MetaAdsVideo $video, MetaAdsService $meta, DuracaoDeVideo $duracao, FormatoDeVideo $formato): JsonResponse
    {
        abort_unless($video->campanha_id === $campanha->id, 404);
        $campanha->loadMissing(['conjuntos', 'videos']);

        if ($bloqueio = $this->bloqueioDoAnuncio($campanha, $video, $meta)) {
            return response()->json(['message' => $bloqueio], 409);
        }

        $trocaConjunto = $request->filled('conjunto_id') && (int) $request->input('conjunto_id') !== (int) $video->conjunto_id;
        if ($trocaConjunto && ($video->temObjetoNoMeta() || ! $this->podeMexerNoConjunto($campanha, $request->input('conjunto_id')))) {
            return response()->json(['message' => 'O conjunto não muda depois que o anúncio foi enviado à Meta.'], 409);
        }

        $campos = $this->comErrosEmJson($request, function () use ($request) {
            $request->validate([
                'nome' => ['nullable', 'string', 'max:255'],
                'arquivo' => ['nullable', 'file', 'mimetypes:'.implode(',', [...self::TIPOS_VIDEO, ...self::TIPOS_IMAGEM]), 'max:'.self::MAX_KILOBYTES],
                'confirmo_analise' => ['nullable', 'boolean'],
            ], [
                'arquivo.mimetypes' => 'Use vídeo MP4 ou MOV, ou imagem JPG ou PNG.',
                'arquivo.max' => 'O arquivo passa de 60 MB. Comprima antes de enviar.',
                'arquivo.uploaded' => 'O arquivo não chegou inteiro (limite de 60 MB). Comprima antes de enviar.',
            ]);

            return $this->validarAnuncio($request);
        });

        $nomeNovo = $request->has('nome') ? (trim((string) $request->input('nome')) ?: null) : false;
        $arquivo = $request->file('arquivo');

        if (! $video->estaNaMeta()) {
            return $this->editarSoNoKazaKora($request, $campanha, $video, $campos, $nomeNovo, $arquivo, $trocaConjunto, $duracao, $formato);
        }

        $editado = $video->replicate();
        $editado->fill($campos);
        $mudaCriativo = $arquivo !== null || $editado->criativo($campanha) !== $video->criativo($campanha);
        $mudaNome = is_string($nomeNovo) && $nomeNovo !== $meta->nomeDoAnuncio($campanha, $video);

        if (! $mudaCriativo && ! $mudaNome) {
            // Nada muda na Meta (ex.: só o produto, com o mesmo link).
            $video->update($campos);

            return response()->json(['message' => 'Anúncio atualizado.', 'video' => $this->dadosDoVideo($campanha, $video->fresh())]);
        }

        if ($mudaCriativo && ! $request->boolean('confirmo_analise')) {
            $mensagem = 'Confirme que o anúncio volta para a análise da Meta.';

            return response()->json(['message' => $mensagem, 'errors' => ['confirmo_analise' => [$mensagem]]], 422);
        }

        $midia = null;
        if ($arquivo) {
            $midia = $this->guardarArquivo($campanha, $arquivo, $duracao, $formato);
            if ($midia === null) {
                return response()->json(['message' => 'Não consegui guardar o arquivo no servidor.'], 500);
            }
        }

        $video->forceFill(['edicao_pendente' => [
            'campos' => $campos,
            'nome' => $mudaNome ? $nomeNovo : null,
            'muda_criativo' => $mudaCriativo,
            'midia' => $midia,
        ]])->save();

        if ($midia && $midia['tipo'] === MetaAdsVideo::TIPO_VIDEO) {
            TrocarCriativoMetaAds::dispatch($video->id);

            return response()->json([
                'message' => 'Vídeo novo recebido. Quando a Meta terminar de processar, o criativo novo entra no anúncio e vai para a análise. Até lá, o anúncio segue como está.',
                'video' => $this->dadosDoVideo($campanha, $video->fresh()),
            ], 202);
        }

        try {
            $meta->avancarEdicao($campanha, $video);
        } catch (MetaAdsException $erro) {
            return response()->json(['message' => 'Não deu para salvar na Meta: '.rtrim($erro->getMessage(), '.').'. Nada foi alterado.'], 422);
        }

        return response()->json([
            'message' => $mudaCriativo ? 'Anúncio atualizado. A Meta está analisando o criativo novo.' : 'Anúncio atualizado.',
            'video' => $this->dadosDoVideo($campanha, $video->fresh()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $campos
     * @param  string|null|false  $nomeNovo  false = a tela não mandou o nome
     */
    private function editarSoNoKazaKora(Request $request, MetaAdsCampanha $campanha, MetaAdsVideo $video, array $campos, string|null|false $nomeNovo, mixed $arquivo, bool $trocaConjunto, DuracaoDeVideo $duracao, FormatoDeVideo $formato): JsonResponse
    {
        if ($trocaConjunto) {
            $campos['conjunto_id'] = $this->conjuntoDoAnuncio($request, $campanha)->id;
        }
        if ($nomeNovo !== false) {
            $campos['nome'] = $nomeNovo;
        }

        $criativoAntes = $video->criativo($campanha);
        $antigos = [];

        if ($arquivo) {
            $midia = $this->guardarArquivo($campanha, $arquivo, $duracao, $formato);
            if ($midia === null) {
                return response()->json(['message' => 'Não consegui guardar o arquivo no servidor.'], 500);
            }
            $antigos = array_filter([$video->path, $video->path_vertical]);
            // O que já tinha subido do arquivo antigo não serve mais.
            $campos += $midia + ['path_vertical' => null, 'meta_video_id' => null, 'meta_image_hash' => null, 'meta_thumbnail_url' => null, 'meta_creative_id' => null, 'ultimo_erro' => null];
        }

        $video->fill($campos);
        if (! $arquivo && filled($video->meta_creative_id) && $video->criativo($campanha) !== $criativoAntes) {
            $video->meta_creative_id = null;
        }
        $video->save();

        if ($antigos !== []) {
            Storage::disk('local')->delete(array_values($antigos));
        }

        return response()->json(['message' => 'Anúncio atualizado.', 'video' => $this->dadosDoVideo($campanha, $video->fresh())]);
    }

    /**
     * Arquivo novo de um anúncio no disco local, com tipo, tamanho e formato.
     *
     * @return array<string, mixed>|null
     */
    private function guardarArquivo(MetaAdsCampanha $campanha, mixed $arquivo, DuracaoDeVideo $duracao, FormatoDeVideo $formato): ?array
    {
        $imagem = in_array($arquivo->getMimeType(), self::TIPOS_IMAGEM, true);
        $path = $arquivo->store(self::pastaDosVideos($campanha), 'local');

        if ($path === false) {
            return null;
        }

        $caminho = Storage::disk('local')->path($path);

        return [
            'tipo' => $imagem ? MetaAdsVideo::TIPO_IMAGEM : MetaAdsVideo::TIPO_VIDEO,
            'nome_original' => mb_substr($arquivo->getClientOriginalName() ?: ($imagem ? 'imagem.jpg' : 'video.mp4'), 0, 250),
            'path' => $path,
            'tamanho' => (int) $arquivo->getSize(),
            'duracao_segundos' => $imagem ? null : $duracao->segundos($caminho),
        ] + ($imagem ? [] : ($formato->medir($caminho) ?? [])) + ['largura' => null, 'altura' => null];
    }

    /**
     * Por que o anúncio não pode ser editado nem excluído agora (null = pode).
     * Vai também para o title dos botões desabilitados.
     */
    private function bloqueioDoAnuncio(MetaAdsCampanha $campanha, MetaAdsVideo $video, MetaAdsService $meta): ?string
    {
        if ($campanha->status === MetaAdsCampanha::STATUS_ENVIANDO) {
            return 'A campanha está sendo enviada para a Meta. Espere terminar.';
        }

        $conjunto = $campanha->relationLoaded('conjuntos') ? $campanha->conjuntos->firstWhere('id', $video->conjunto_id) : $video->conjunto;
        if ($conjunto?->enviando) {
            return 'Este conjunto está sendo enviado para a Meta. Espere terminar.';
        }

        if ($video->temEdicaoPendente()) {
            return 'A Meta está processando o arquivo novo deste anúncio. Espere terminar.';
        }

        if ($video->estaNaMeta() && ! $meta->configurado()) {
            return 'Meta não configurada no servidor.';
        }

        return null;
    }

    /**
     * Duplicar anúncio já guardado (ou o conjunto inteiro, um por um): copia
     * o arquivo no servidor, sem subir de novo, com o texto que a tela mandar.
     */
    public function videoDuplicar(Request $request, MetaAdsCampanha $campanha, MetaAdsVideo $video): JsonResponse
    {
        abort_unless($video->campanha_id === $campanha->id, 404);

        if (! $this->podeMexerNoConjunto($campanha, $request->input('conjunto_id'))) {
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
            'adicionado_depois' => ! $campanha->podeMexerNosVideos(),
            'ordem' => $request->filled('ordem') ? (int) $request->input('ordem') : $this->proximaOrdem($campanha),
            'tipo' => $video->tipo,
            'nome_original' => $video->nome_original,
            'path' => $path,
            'tamanho' => $video->tamanho,
            'duracao_segundos' => $video->duracao_segundos,
            'largura' => $video->largura,
            'altura' => $video->altura,
        ]);

        return response()->json(['video' => $this->dadosDoVideo($campanha, $copia)], 201);
    }

    /**
     * Excluir anúncio (2026-10-19). Só no KazaKora: sai de vez, com o
     * arquivo. Na Meta: exige confirmar que é definitivo (e, se for o último
     * anúncio ativo do conjunto, confirmar que o conjunto fica sem nenhum);
     * vira DELETED na Meta e aqui fica marcado como excluído (soft delete),
     * para não perder o vínculo com o gasto.
     */
    public function videoDestroy(Request $request, MetaAdsCampanha $campanha, MetaAdsVideo $video, MetaAdsService $meta): JsonResponse|RedirectResponse
    {
        abort_unless($video->campanha_id === $campanha->id, 404);
        $campanha->loadMissing(['conjuntos', 'videos']);
        $responder = fn (string $mensagem, int $status, array $extra = []) => $request->wantsJson()
            ? response()->json(['message' => $mensagem] + $extra, $status)
            : back()->with($status < 300 ? 'success' : 'error', $mensagem);

        if ($bloqueio = $this->bloqueioDoAnuncio($campanha, $video, $meta)) {
            return $responder($bloqueio, 409);
        }

        if (! $video->estaNaMeta()) {
            Storage::disk('local')->delete(array_values(array_filter([$video->path, $video->path_vertical])));
            $video->forceDelete();

            return $responder('Anúncio excluído do KazaKora.', 200, ['ok' => true]);
        }

        if (! $request->boolean('confirmo_exclusao')) {
            $mensagem = 'Confirme que a exclusão na Meta é definitiva.';

            return $responder($mensagem, 422, ['errors' => ['confirmo_exclusao' => [$mensagem]]]);
        }

        try {
            if (! $request->boolean('sem_anuncio_ativo') && $meta->ultimoAtivoDoConjunto($video)) {
                $nome = $campanha->conjuntos->firstWhere('id', $video->conjunto_id)?->nome ?? '';
                $mensagem = "É o último anúncio ativo do conjunto \"{$nome}\". Sem ele o conjunto para de entregar.";

                return $responder($mensagem, 422, ['errors' => ['sem_anuncio_ativo' => [$mensagem]], 'ultimo_ativo' => true]);
            }

            $meta->excluirAnuncio($campanha, $video);
        } catch (MetaAdsException $erro) {
            return $responder('A Meta recusou a exclusão: '.rtrim($erro->getMessage(), '.').'. O anúncio continua como estava.', 422);
        }

        Storage::disk('local')->delete(array_values(array_filter([$video->path, $video->path_vertical])));
        $video->forceFill(['ativado_em' => null])->save();
        $video->delete();

        return $responder('Anúncio excluído na Meta.', 200, ['ok' => true]);
    }

    /** "Pausar em vez de excluir": para a entrega do anúncio e mantém tudo. */
    public function videoPausar(Request $request, MetaAdsCampanha $campanha, MetaAdsVideo $video, MetaAdsService $meta): JsonResponse
    {
        abort_unless($video->campanha_id === $campanha->id, 404);
        $campanha->loadMissing(['conjuntos', 'videos']);

        if (! $video->estaNaMeta()) {
            return response()->json(['message' => 'Este anúncio ainda não está na Meta.'], 409);
        }

        if ($bloqueio = $this->bloqueioDoAnuncio($campanha, $video, $meta)) {
            return response()->json(['message' => $bloqueio], 409);
        }

        try {
            $meta->pausarAnuncio($campanha, $video);
        } catch (MetaAdsException $erro) {
            return response()->json(['message' => 'A Meta não pausou o anúncio: '.rtrim($erro->getMessage(), '.').'. O anúncio continua como estava.'], 422);
        }

        return response()->json(['message' => 'Anúncio pausado na Meta.', 'ok' => true]);
    }

    /** Toca o vídeo guardado no servidor (prévia na tela). */
    public function videoArquivo(MetaAdsCampanha $campanha, MetaAdsVideo $video): BinaryFileResponse
    {
        abort_unless($video->campanha_id === $campanha->id, 404);

        $disco = Storage::disk('local');
        abort_unless($disco->exists($video->path), 404);

        return response()->file($disco->path($video->path), ['Cache-Control' => 'private, max-age=3600']);
    }

    // Conjunto novo em campanha que já está na Meta (2026-10-19) --------------

    public function conjuntoCreate(MetaAdsCampanha $campanha, MetaAdsService $meta): Response|RedirectResponse
    {
        $campanha->load(['conjuntos', 'videos']);

        if (! $campanha->podeAdicionarConjunto()) {
            return redirect()->route('admin.publicidade.meta-ads.campanhas.ver', $campanha)
                ->with('error', 'Conjunto novo só em campanha que já está na Meta (enviada, ativa ou assumida no Gerenciador).');
        }

        if ($campanha->conjuntos->count() >= MetaAdsCampanha::MAX_CONJUNTOS) {
            return redirect()->route('admin.publicidade.meta-ads.campanhas.ver', $campanha)
                ->with('error', 'Limite de '.MetaAdsCampanha::MAX_CONJUNTOS.' conjuntos por campanha.');
        }

        return Inertia::render('Admin/MetaAds/ConjuntoNovo', $this->dadosDoConjuntoNovo($meta, $campanha, null));
    }

    public function conjuntoEdit(MetaAdsCampanha $campanha, MetaAdsConjunto $conjunto, MetaAdsService $meta): Response|RedirectResponse
    {
        abort_unless($conjunto->campanha_id === $campanha->id, 404);
        $campanha->load(['conjuntos', 'videos']);

        if (! $conjunto->aceitaMudanca($campanha)) {
            return redirect()->route('admin.publicidade.meta-ads.campanhas.ver', $campanha)
                ->with('error', 'Este conjunto já foi para a Meta. Mude pelo Gerenciador de Anúncios.');
        }

        return Inertia::render('Admin/MetaAds/ConjuntoNovo', $this->dadosDoConjuntoNovo($meta, $campanha, $conjunto));
    }

    /** Acrescentar anúncios novos em conjunto que já existe (na Meta ou ainda não). */
    public function conjuntoAnuncios(MetaAdsCampanha $campanha, MetaAdsConjunto $conjunto, MetaAdsService $meta): Response|RedirectResponse
    {
        abort_unless($conjunto->campanha_id === $campanha->id, 404);
        $campanha->load(['conjuntos', 'videos']);
        $conjunto = $campanha->conjuntos->firstWhere('id', $conjunto->id);

        if (! $conjunto->aceitaAnuncioNovo($campanha)) {
            return redirect()->route('admin.publicidade.meta-ads.campanhas.ver', $campanha)
                ->with('error', 'Só dá para acrescentar anúncio em campanha que já está na Meta e com o conjunto fora de envio.');
        }

        return Inertia::render('Admin/MetaAds/ConjuntoNovo', $this->dadosDoConjuntoNovo($meta, $campanha, $conjunto, true));
    }

    public function conjuntoStore(Request $request, MetaAdsCampanha $campanha): JsonResponse
    {
        $campanha->load(['conjuntos', 'videos']);

        if (! $campanha->podeAdicionarConjunto()) {
            return response()->json(['message' => 'Conjunto novo só em campanha que já está na Meta (enviada, ativa ou assumida no Gerenciador).'], 409);
        }

        if ($campanha->conjuntos->count() >= MetaAdsCampanha::MAX_CONJUNTOS) {
            return response()->json(['message' => 'Limite de '.MetaAdsCampanha::MAX_CONJUNTOS.' conjuntos por campanha.'], 422);
        }

        $dados = $this->comErrosEmJson($request, fn () => $this->validarConjuntoNovo($request, $campanha));

        $conjunto = DB::transaction(function () use ($campanha, $dados) {
            $conjunto = $campanha->conjuntos()->create($dados + [
                'ordem' => (int) $campanha->conjuntos->max('ordem') + 1,
                'adicionado_depois' => true,
            ]);
            $this->somarOrcamentoDosConjuntos($campanha);

            return $conjunto;
        });

        return response()->json(['id' => $conjunto->id], 201);
    }

    public function conjuntoUpdate(Request $request, MetaAdsCampanha $campanha, MetaAdsConjunto $conjunto): JsonResponse
    {
        abort_unless($conjunto->campanha_id === $campanha->id, 404);
        $campanha->load(['conjuntos', 'videos']);

        if (! $conjunto->aceitaMudanca($campanha)) {
            return response()->json(['message' => 'Este conjunto já foi para a Meta. Mude pelo Gerenciador de Anúncios.'], 409);
        }

        $dados = $this->comErrosEmJson($request, fn () => $this->validarConjuntoNovo($request, $campanha));

        DB::transaction(function () use ($campanha, $conjunto, $dados) {
            $conjunto->update($dados);
            $this->somarOrcamentoDosConjuntos($campanha);
        });

        return response()->json(['id' => $conjunto->id]);
    }

    public function conjuntoDestroy(MetaAdsCampanha $campanha, MetaAdsConjunto $conjunto): RedirectResponse
    {
        abort_unless($conjunto->campanha_id === $campanha->id, 404);
        $campanha->load(['conjuntos', 'videos']);

        if (! $conjunto->aceitaMudanca($campanha)) {
            return back()->with('error', 'Este conjunto já foi para a Meta. Tire pelo Gerenciador de Anúncios.');
        }

        DB::transaction(function () use ($campanha, $conjunto) {
            foreach ($campanha->videos->where('conjunto_id', $conjunto->id) as $video) {
                Storage::disk('local')->delete(array_values(array_filter([$video->path, $video->path_vertical])));
                $video->forceDelete();
            }
            $conjunto->forceDelete();
            $this->somarOrcamentoDosConjuntos($campanha);
        });

        return redirect()->route('admin.publicidade.meta-ads.campanhas.ver', $campanha)->with('success', 'Conjunto novo excluído.');
    }

    public function conjuntoEnviar(MetaAdsCampanha $campanha, MetaAdsConjunto $conjunto, MetaAdsService $meta): RedirectResponse
    {
        abort_unless($conjunto->campanha_id === $campanha->id, 404);
        $campanha->load(['conjuntos', 'videos']);
        $conjunto = $campanha->conjuntos->firstWhere('id', $conjunto->id);

        if (! $conjunto->podeEnviar($campanha)) {
            return back()->with('error', 'Nada novo para enviar neste conjunto, ou o envio já está rodando.');
        }

        if ($problemas = $meta->problemasDoConjuntoNovo($campanha, $conjunto)) {
            return back()->with('error', implode(' ', $problemas));
        }

        $conjunto->forceFill(['enviando' => true, 'ultimo_erro' => null])->save();
        $campanha->videos()->where('conjunto_id', $conjunto->id)->update(['ultimo_erro' => null]);
        EnviarCampanhaMetaAds::dispatch($campanha->id, $conjunto->id);

        $oQue = blank($conjunto->meta_adset_id) ? "o conjunto {$conjunto->nome} e os anúncios dele" : "os anúncios novos do conjunto {$conjunto->nome}";

        return back()->with('success', "Enviando {$oQue} para a Meta. Nascem PAUSADOS; o resto da campanha não muda.");
    }

    public function conjuntoAtivar(Request $request, MetaAdsCampanha $campanha, MetaAdsConjunto $conjunto, MetaAdsService $meta): RedirectResponse
    {
        abort_unless($conjunto->campanha_id === $campanha->id, 404);

        $request->validate([
            'confirmo_gasto' => ['accepted'],
            'orcamento_diario' => ['required', 'numeric'],
        ], [
            'confirmo_gasto.accepted' => 'Confirme que o conjunto vai começar a gastar.',
        ]);

        $campanha->load(['conjuntos', 'videos']);
        $conjunto = $campanha->conjuntos->firstWhere('id', $conjunto->id);

        // Orçamento por conjunto: confirma o do conjunto; da campanha: o total, que não muda.
        $esperado = $campanha->orcamentoDaCampanha() ? (float) $campanha->orcamento_diario : (float) $conjunto->orcamento_diario;
        if (round((float) $request->input('orcamento_diario'), 2) !== round($esperado, 2)) {
            throw ValidationException::withMessages(['orcamento_diario' => 'O orçamento mudou. Recarregue a tela e confirme de novo.']);
        }

        if (! $conjunto->podeAtivar($campanha)) {
            return back()->with('error', 'Só dá para ativar conjunto ou anúncio novo já criado na Meta, em campanha ativa.');
        }

        if (! $meta->configurado()) {
            return back()->with('error', 'Meta não configurado.');
        }

        try {
            $meta->ativarConjunto($campanha, $conjunto);
        } catch (MetaAdsException) {
            return back()->with('error', 'A Meta não ativou. Veja o erro na tela.');
        }

        return back()->with('success', "Novidades do conjunto {$conjunto->nome} ativas na Meta.");
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
     * Anúncio só muda em rascunho ou dentro de conjunto novo que ainda não
     * subiu (campanha já na Meta).
     */
    private function podeMexerNoConjunto(MetaAdsCampanha $campanha, mixed $conjuntoId): bool
    {
        if ($campanha->podeMexerNosVideos()) {
            return true;
        }

        $campanha->loadMissing(['conjuntos', 'videos']);
        $conjunto = filled($conjuntoId) ? $campanha->conjuntos->firstWhere('id', (int) $conjuntoId) : null;

        return (bool) $conjunto?->aceitaAnuncioNovo($campanha);
    }

    /** @return array<string, mixed> */
    private function validarConjuntoNovo(Request $request, MetaAdsCampanha $campanha): array
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'orcamento_diario' => [$campanha->orcamentoDaCampanha() ? 'nullable' : 'required', 'numeric', 'min:'.MetaAdsCampanha::MINIMO_POR_CONJUNTO, 'max:10000'],
            'publico_proprio' => ['boolean'],
            'idade_min' => ['integer', 'min:18', 'max:65'],
            'idade_max' => ['integer', 'min:18', 'max:65'],
            'genero' => [Rule::in(array_keys(MetaAdsCampanha::GENEROS))],
            'localizacoes' => ['nullable', 'array'],
            'localizacoes.regioes' => ['nullable', 'array', 'max:30'],
            'localizacoes.regioes.*.key' => ['required', 'string', 'max:40'],
            'localizacoes.regioes.*.nome' => ['required', 'string', 'max:120'],
            'localizacoes.cidades' => ['nullable', 'array', 'max:50'],
            'localizacoes.cidades.*.key' => ['required', 'string', 'max:40'],
            'localizacoes.cidades.*.nome' => ['required', 'string', 'max:160'],
            'localizacoes.cidades.*.raio_km' => ['nullable', 'integer', 'min:10', 'max:80'],
        ], [
            'nome.required' => 'Dê um nome para o conjunto.',
            'orcamento_diario.required' => 'Informe o orçamento por dia deste conjunto.',
            'orcamento_diario.min' => 'Mínimo de R$ '.number_format(MetaAdsCampanha::MINIMO_POR_CONJUNTO, 2, ',', '.').' por dia neste conjunto.',
        ]);

        $conjunto = $this->normalizarConjuntos([$dados + ['chave' => 'novo']], $campanha)[0];

        return collect($conjunto)->except(['id', 'chave', 'ordem'])->all();
    }

    /** Orçamento por conjunto: o diário da campanha é a soma (é o que a tela de ativar confirma). */
    private function somarOrcamentoDosConjuntos(MetaAdsCampanha $campanha): void
    {
        if ($campanha->orcamentoDaCampanha()) {
            return;
        }

        $campanha->forceFill(['orcamento_diario' => round((float) $campanha->conjuntos()->sum('orcamento_diario'), 2)])->save();
    }

    /** @return array<string, mixed> */
    private function dadosDoConjuntoNovo(MetaAdsService $meta, MetaAdsCampanha $campanha, ?MetaAdsConjunto $conjunto, bool $soAnuncios = false): array
    {
        $formulario = $this->dadosDoFormulario($meta, $campanha);
        $doConjunto = $conjunto ? $campanha->videos->where('conjunto_id', $conjunto->id) : collect();

        return [
            'campanha' => $formulario['campanha'],
            'conjunto' => $conjunto ? $this->dadosDoConjunto($campanha, $conjunto) : null,
            // Acrescentar anúncios: os que já estão na Meta só aparecem como lista.
            'soAnuncios' => $soAnuncios,
            'videos' => $doConjunto->reject(fn (MetaAdsVideo $v) => $v->temObjetoNoMeta())
                ->map(fn (MetaAdsVideo $v) => $this->dadosDoVideo($campanha, $v))->values(),
            'jaNaMeta' => $doConjunto->filter(fn (MetaAdsVideo $v) => $v->temObjetoNoMeta())
                ->map(fn (MetaAdsVideo $v) => ['id' => $v->id, 'nome' => $v->nome_original])->values(),
            'outrosConjuntos' => $campanha->conjuntos->where('id', '!=', $conjunto?->id)->pluck('nome')->values(),
            'limites' => $formulario['limites'] + ['anuncios_na_campanha' => $campanha->videos->count()],
            'produtos' => $formulario['produtos'],
            'config' => $formulario['config'],
            'generos' => $formulario['generos'],
            'chamadas' => $formulario['chamadas'],
            'siteUrl' => $formulario['siteUrl'],
        ];
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
            // Outras opções de texto, título e descrição (a Meta testa as combinações).
            'textos_alternativos' => ['nullable', 'array'],
            'textos_alternativos.textos' => ['nullable', 'array', 'max:'.MetaAdsCampanha::MAX_TEXTOS_ALTERNATIVOS],
            'textos_alternativos.textos.*' => ['nullable', 'string', 'max:2000'],
            'textos_alternativos.titulos' => ['nullable', 'array', 'max:'.MetaAdsCampanha::MAX_TEXTOS_ALTERNATIVOS],
            'textos_alternativos.titulos.*' => ['nullable', 'string', 'max:255'],
            'textos_alternativos.descricoes' => ['nullable', 'array', 'max:'.MetaAdsCampanha::MAX_TEXTOS_ALTERNATIVOS],
            'textos_alternativos.descricoes.*' => ['nullable', 'string', 'max:255'],
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
            'textos_alternativos.*.max' => 'Até '.MetaAdsCampanha::MAX_TEXTOS_ALTERNATIVOS.' opções além da principal.',
        ]);

        if (array_key_exists('textos_alternativos', $dados)) {
            $dados['textos_alternativos'] = (new MetaAdsCampanha(['textos_alternativos' => $dados['textos_alternativos'] ?? []]))->textosAlternativos();
        }

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
                $campanha->conjuntos()->whereIn('id', $saindo)->forceDelete();
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
            Storage::disk('local')->delete(array_values(array_filter([$video->path, $video->path_vertical])));
            $video->forceDelete();
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
    /** Produtos da loja para o seletor do anúncio (o link sai da página do produto). */
    private function listaDeProdutos(): \Illuminate\Support\Collection
    {
        return Product::query()
            ->where('is_active', true)
            ->whereNull('parent_product_id')
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'nome' => $p->name,
                'link' => self::linkDoProduto($p),
            ]);
    }

    private function dadosDoFormulario(MetaAdsService $meta, ?MetaAdsCampanha $campanha): array
    {
        $produtos = $this->listaDeProdutos();

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
    private function periodo(Request $request): string
    {
        $periodo = (string) $request->query('periodo', 'maximum');

        return array_key_exists($periodo, MetaAdsService::PERIODOS) ? $periodo : 'maximum';
    }

    /**
     * Estrutura real da Meta (Campanha > Conjuntos > Anúncios) com métricas.
     * Erro da Meta não derruba a tela: volta a mensagem para mostrar.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $noKazaKora  ids da Meta => registro local (ver noKazaKora())
     * @return array{campanhas: list<array<string, mixed>>, erro: ?string}
     */
    private function espelho(MetaAdsService $meta, string $periodo, ?string $campaignId, array $noKazaKora = []): array
    {
        if (! $meta->configurado()) {
            return ['campanhas' => [], 'erro' => null];
        }

        try {
            $campanhas = array_map(function (array $c) use ($noKazaKora, $meta) {
                $local = $noKazaKora['campanhas'][$c['id']] ?? null;
                $c['conjuntos'] = array_map(function (array $s) use ($noKazaKora, $local, $meta) {
                    $conjunto = $local ? ($noKazaKora['conjuntos'][$s['id']] ?? null) : null;
                    $ativos = collect($s['anuncios'] ?? [])->where('status', 'ACTIVE')->count();
                    // Editar e excluir (2026-10-19): só anúncio que nasceu no KazaKora.
                    $s['anuncios'] = array_map(fn (array $a) => $a + [
                        'local' => $noKazaKora['anuncios'][$a['id']] ?? null,
                        'gerenciador' => $meta->linkAnuncioGerenciador((string) $a['id']),
                        'ultimo_ativo' => $a['status'] === 'ACTIVE' && $ativos === 1,
                    ], $s['anuncios'] ?? []);

                    return $s + [
                        'local_id' => $conjunto['id'] ?? null,
                        'novo_anuncio' => (bool) ($conjunto['novo_anuncio'] ?? false),
                    ];
                }, $c['conjuntos'] ?? []);

                return $c + [
                    'local_id' => $local['id'] ?? null,
                    'novo_conjunto' => (bool) ($local['novo_conjunto'] ?? false),
                ];
            }, $meta->espelho($periodo, $campaignId));

            return ['campanhas' => $campanhas, 'erro' => null, 'status_atualizado' => $this->acompanharStatus($campanhas)];
        } catch (MetaAdsException $erro) {
            return ['campanhas' => [], 'erro' => $erro->getMessage()];
        }
    }

    /**
     * Status do KazaKora igual ao da Meta para as campanhas que estão nos
     * dois lados. Devolve true se alguma mudou (a tela recarrega a lista).
     *
     * @param  list<array<string, mixed>>  $campanhas  espelho da Meta
     */
    private function acompanharStatus(array $campanhas): bool
    {
        $statusNaMeta = collect($campanhas)->mapWithKeys(fn (array $c) => [(string) $c['id'] => (string) $c['status']]);

        if ($statusNaMeta->isEmpty()) {
            return false;
        }

        return MetaAdsCampanha::query()
            ->whereIn('meta_campaign_id', $statusNaMeta->keys())
            ->get()
            ->map(fn (MetaAdsCampanha $c) => $c->acompanharStatusDaMeta($statusNaMeta[(string) $c->meta_campaign_id]))
            ->contains(true);
    }

    /**
     * Campanhas e conjuntos do Gerenciador que existem no KazaKora, com o
     * que dá para acrescentar em cada um (1 campanha > N conjuntos > N
     * anúncios, como no Gerenciador). Pedido do Lira 2026-10-19.
     *
     * @return array{campanhas: array<string, array{id: int, novo_conjunto: bool}>, conjuntos: array<string, array{id: int, novo_anuncio: bool}>}
     */
    private function noKazaKora(?int $soCampanha = null): array
    {
        $mapa = ['campanhas' => [], 'conjuntos' => [], 'anuncios' => []];
        $meta = app(MetaAdsService::class);

        MetaAdsCampanha::query()
            ->whereNotNull('meta_campaign_id')
            ->when($soCampanha, fn ($q) => $q->whereKey($soCampanha))
            ->with(['conjuntos', 'videos'])
            ->get()
            ->each(function (MetaAdsCampanha $c) use (&$mapa, $meta) {
                $mapa['campanhas'][(string) $c->meta_campaign_id] = [
                    'id' => $c->id,
                    'novo_conjunto' => $c->podeAdicionarConjunto() && $c->conjuntos->count() < MetaAdsCampanha::MAX_CONJUNTOS,
                ];
                foreach ($c->conjuntos as $s) {
                    if (filled($s->meta_adset_id)) {
                        $mapa['conjuntos'][(string) $s->meta_adset_id] = ['id' => $s->id, 'novo_anuncio' => $s->aceitaAnuncioNovo($c)];
                    }
                }
                // Anúncio da Meta que existe aqui (casado pelo meta_ad_id): ganha Editar e Excluir.
                foreach ($c->videos as $v) {
                    if ($v->estaNaMeta()) {
                        $mapa['anuncios'][(string) $v->meta_ad_id] = $this->dadosParaEditar($c, $v, $meta);
                    }
                }
            });

        return $mapa;
    }

    /**
     * O que o modal de editar e excluir precisa: valores efetivos (os que
     * vão no criativo), conjunto e se pode mexer agora.
     *
     * @return array<string, mixed>
     */
    private function dadosParaEditar(MetaAdsCampanha $campanha, MetaAdsVideo $video, MetaAdsService $meta): array
    {
        $criativo = $video->criativo($campanha);

        return [
            'id' => $video->id,
            'campanha_id' => $campanha->id,
            'conjunto' => $campanha->conjuntos->firstWhere('id', $video->conjunto_id)?->nome,
            'nome' => $meta->nomeDoAnuncio($campanha, $video),
            'tipo' => $video->tipo,
            'arquivo' => $video->nome_original,
            'na_meta' => $video->estaNaMeta(),
            'produto_id' => $video->produto_id ?? $campanha->produto_id,
            'link_destino' => $criativo['link'],
            'texto_principal' => $criativo['texto'],
            'titulo' => $criativo['titulo'],
            'descricao' => $criativo['descricao'],
            'chamada' => $criativo['chamada'],
            'trocando_arquivo' => $video->temEdicaoPendente(),
            'bloqueio' => $this->bloqueioDoAnuncio($campanha, $video, $meta),
        ];
    }

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
            'adicionado_depois' => $conjunto->adicionado_depois,
            'enviando' => $conjunto->enviando,
            'ativado_em' => $conjunto->ativado_em?->toIso8601String(),
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
            'formato' => FormatoDeVideo::descrever($video->largura, $video->altura),
            'vira_vertical' => $video->precisaFicarVertical(),
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
            'adicionado_depois' => $video->adicionado_depois,
            'ativado_em' => $video->ativado_em?->toIso8601String(),
            'meta_ad_id' => $video->meta_ad_id,
            // Modal de editar e excluir (2026-10-19).
            'editar' => $this->dadosParaEditar($campanha, $video, app(MetaAdsService::class)),
        ];
    }
}
