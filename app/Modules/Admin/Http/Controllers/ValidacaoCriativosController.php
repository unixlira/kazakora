<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\CriativoValidacao;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Support\DuracaoDeVideo;
use App\Modules\Marketplace\Support\FormatoDeVideo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Marketing › Validação de criativos (pedido do Lira 2026-10-21): a equipe
 * sobe os vídeos de anúncio (Meta, TikTok, Google...) e o Lira assiste,
 * aprova, pede ajuste ou reprova, com comentário. Só administrador avalia.
 *
 * O envio vai em partes de 8 MB (enviarParte) porque o servidor aceita no
 * máximo 64 MB por requisição e vídeo de criativo costuma passar disso. As
 * partes se juntam em criativos/envios/ e, no fim (store), o arquivo vai
 * para criativos/AAAA-MM/ no disco local (privado). Parte esquecida há mais
 * de um dia é limpa quando a tela abre.
 */
class ValidacaoCriativosController extends Controller
{
    private const POR_PAGINA = 24;

    public const TAMANHO_PARTE = 8 * 1024 * 1024;

    private const PASTA_ENVIOS = 'criativos/envios';

    private const TIPOS = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
    ];

    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'status' => ['nullable', Rule::in(CriativoValidacao::STATUS)],
            'plataforma' => ['nullable', Rule::in(array_keys(CriativoValidacao::PLATAFORMAS))],
            'busca' => ['nullable', 'string', 'max:100'],
            'lixeira' => ['nullable', 'boolean'],
        ]);
        $busca = trim((string) ($filtros['busca'] ?? ''));
        $lixeira = (bool) ($filtros['lixeira'] ?? false);

        $this->limparEnviosEsquecidos();

        $criativos = CriativoValidacao::query()
            ->with(['produto:id,name', 'enviadoPor:id,name', 'avaliadoPor:id,name'])
            ->when($lixeira, fn (Builder $q) => $q->onlyTrashed())
            ->when($filtros['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filtros['plataforma'] ?? null, fn (Builder $q, $plataforma) => $q->where('plataforma', $plataforma))
            ->when($busca !== '', function (Builder $q) use ($busca) {
                $like = '%'.addcslashes($busca, '\\%_').'%';
                $q->where(fn (Builder $w) => $w->where('titulo', 'like', $like)
                    ->orWhere('nome_original', 'like', $like)
                    ->orWhere('observacao', 'like', $like)
                    ->orWhereHas('produto', fn (Builder $p) => $p->where('name', 'like', $like)));
            })
            ->latest('created_at')->latest('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (CriativoValidacao $c) => $c->paraTela());

        $porStatus = CriativoValidacao::query()->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Admin/ValidacaoCriativos/Index', [
            'criativos' => $criativos,
            'totais' => collect(CriativoValidacao::STATUS)->mapWithKeys(fn ($s) => [$s => (int) ($porStatus[$s] ?? 0)])->all()
                + ['lixeira' => CriativoValidacao::onlyTrashed()->count()],
            'filtros' => [
                'status' => $filtros['status'] ?? '',
                'plataforma' => $filtros['plataforma'] ?? '',
                'busca' => $busca,
                'lixeira' => $lixeira,
            ],
            'plataformas' => CriativoValidacao::PLATAFORMAS,
            'produtos' => Product::query()->whereNull('parent_product_id')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Product $p) => ['id' => $p->id, 'nome' => $p->name]),
            'podeAvaliar' => (bool) $request->user()?->isAdmin(),
            'extensoes' => CriativoValidacao::EXTENSOES,
            'maxMb' => CriativoValidacao::MAX_MB,
            'tamanhoParte' => self::TAMANHO_PARTE,
        ]);
    }

    /**
     * Uma parte do vídeo. `inicio` é o byte onde a parte começa: se não bate
     * com o que já chegou, responde 409 com `recebido` e o navegador continua
     * dali (conexão que caiu no meio não estraga o arquivo).
     */
    public function enviarParte(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'envio' => ['required', 'uuid'],
            'inicio' => ['required', 'integer', 'min:0'],
            'parte' => ['required', 'file', 'max:'.(int) ((self::TAMANHO_PARTE / 1024) + 1024)],
        ]);

        $disco = Storage::disk('local');
        $caminho = $this->caminhoDoEnvio($request, $dados['envio']);
        $recebido = $disco->exists($caminho) ? $disco->size($caminho) : 0;

        if ((int) $dados['inicio'] !== $recebido) {
            return response()->json(['message' => 'Parte fora de ordem.', 'recebido' => $recebido], 409);
        }

        $parte = $request->file('parte');
        if ($recebido + $parte->getSize() > CriativoValidacao::MAX_MB * 1048576) {
            $disco->delete($caminho);

            return response()->json(['message' => 'O vídeo passa de '.CriativoValidacao::MAX_MB.' MB. Comprima antes de enviar.'], 422);
        }

        $disco->makeDirectory(self::PASTA_ENVIOS);
        $destino = fopen($disco->path($caminho), 'ab');
        $origem = fopen($parte->getRealPath(), 'rb');
        stream_copy_to_stream($origem, $destino);
        fclose($origem);
        fclose($destino);
        clearstatcache(true, $disco->path($caminho));

        return response()->json(['recebido' => $disco->size($caminho)]);
    }

    /** Fecha o envio: confere o arquivo inteiro, guarda e cria o criativo. */
    public function store(Request $request, DuracaoDeVideo $duracao, FormatoDeVideo $formato): JsonResponse
    {
        $dados = $request->validate([
            'envio' => ['required', 'uuid'],
            'nome_original' => ['required', 'string', 'max:255'],
            'tamanho' => ['required', 'integer', 'min:1'],
        ] + $this->regrasDosDados(), $this->mensagens());

        $extensao = strtolower(pathinfo($dados['nome_original'], PATHINFO_EXTENSION));
        if (! in_array($extensao, CriativoValidacao::EXTENSOES, true)) {
            return response()->json(['message' => 'Use vídeo MP4, MOV, M4V ou WebM.'], 422);
        }

        $disco = Storage::disk('local');
        $temporario = $this->caminhoDoEnvio($request, $dados['envio']);
        if (! $disco->exists($temporario) || $disco->size($temporario) !== (int) $dados['tamanho']) {
            return response()->json(['message' => 'O vídeo não chegou inteiro. Envie de novo.'], 422);
        }

        if (! $this->pareceVideo($disco->path($temporario))) {
            $disco->delete($temporario);

            return response()->json(['message' => 'O arquivo não é um vídeo. Use MP4, MOV, M4V ou WebM.'], 422);
        }

        $final = 'criativos/'.now()->format('Y-m').'/'.Str::uuid().'.'.$extensao;
        if (! $disco->move($temporario, $final)) {
            return response()->json(['message' => 'Não consegui guardar o vídeo no servidor.'], 500);
        }

        $absoluto = $disco->path($final);
        $criativo = CriativoValidacao::create([
            'titulo' => $dados['titulo'],
            'plataforma' => $dados['plataforma'],
            'produto_id' => $dados['produto_id'] ?? null,
            'observacao' => $dados['observacao'] ?? null,
            'nome_original' => mb_substr($dados['nome_original'], 0, 255),
            'path' => $final,
            'tamanho' => $disco->size($final),
            'duracao_segundos' => $duracao->segundos($absoluto),
            'enviado_por' => $request->user()?->id,
        ] + ($formato->medir($absoluto) ?? []));

        return response()->json(['criativo' => $criativo->load(['produto', 'enviadoPor'])->paraTela()], 201);
    }

    public function update(Request $request, CriativoValidacao $criativo): RedirectResponse
    {
        $criativo->update($request->validate($this->regrasDosDados(), $this->mensagens()));

        return back()->with('success', 'Criativo atualizado.');
    }

    /** Aprovar, pedir ajuste ou reprovar: só administrador (o Lira). */
    public function avaliar(Request $request, CriativoValidacao $criativo): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Só administrador avalia criativo.');

        $dados = $request->validate([
            'status' => ['required', Rule::in(CriativoValidacao::STATUS)],
            'comentario' => ['nullable', 'string', 'max:2000', Rule::requiredIf(in_array($request->input('status'), [CriativoValidacao::AJUSTAR, CriativoValidacao::REPROVADO], true))],
        ], [
            'comentario.required' => 'Diga o que precisa mudar, para quem fez o vídeo saber o que ajustar.',
        ]);

        $voltouParaFila = $dados['status'] === CriativoValidacao::AGUARDANDO;
        $criativo->update([
            'status' => $dados['status'],
            'comentario_avaliacao' => $dados['comentario'] ?? null,
            'avaliado_por' => $voltouParaFila ? null : $request->user()->id,
            'avaliado_em' => $voltouParaFila ? null : now(),
        ]);

        $mensagem = [
            CriativoValidacao::APROVADO => 'Criativo aprovado.',
            CriativoValidacao::AJUSTAR => 'Pedido de ajuste registrado.',
            CriativoValidacao::REPROVADO => 'Criativo reprovado.',
            CriativoValidacao::AGUARDANDO => 'Criativo voltou para a fila.',
        ][$dados['status']];

        return back()->with('success', $mensagem);
    }

    /** Vai para a lixeira: some da lista, o arquivo fica e dá para restaurar. */
    public function destroy(CriativoValidacao $criativo): RedirectResponse
    {
        $criativo->delete();

        return back()->with('success', 'Criativo movido para a lixeira.');
    }

    public function restaurar(CriativoValidacao $criativo): RedirectResponse
    {
        $criativo->restore();

        return back()->with('success', 'Criativo restaurado.');
    }

    /** Tocar (com avanço pelo meio do vídeo, Range) ou baixar com ?baixar=1. */
    public function arquivo(Request $request, CriativoValidacao $criativo): BinaryFileResponse
    {
        $disco = Storage::disk('local');
        abort_unless($disco->exists($criativo->path), 404, 'Vídeo não encontrado no servidor.');

        $extensao = strtolower(pathinfo($criativo->path, PATHINFO_EXTENSION));
        $cabecalhos = [
            'Content-Type' => self::TIPOS[$extensao] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($request->boolean('baixar')) {
            $nome = (Str::slug($criativo->titulo) ?: 'criativo-'.$criativo->id).'.'.$extensao;

            return response()->download($disco->path($criativo->path), $nome, $cabecalhos);
        }

        return response()->file($disco->path($criativo->path), $cabecalhos);
    }

    /** @return array<string, array<int, mixed>> */
    private function regrasDosDados(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:150'],
            'plataforma' => ['required', Rule::in(array_keys(CriativoValidacao::PLATAFORMAS))],
            'produto_id' => ['nullable', 'integer', 'exists:products,id'],
            'observacao' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    private function mensagens(): array
    {
        return [
            'titulo.required' => 'Dê um nome ao criativo.',
            'plataforma.required' => 'Escolha a plataforma do anúncio.',
        ];
    }

    /** Uma pasta de envio por pessoa: ninguém continua o envio de outro. */
    private function caminhoDoEnvio(Request $request, string $envio): string
    {
        return self::PASTA_ENVIOS.'/'.(int) $request->user()?->id.'-'.strtolower($envio).'.part';
    }

    /** Confere o conteúdo, não só a extensão (HTML renomeado para .mp4 não passa). */
    private function pareceVideo(string $caminho): bool
    {
        $tipo = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($caminho);

        return str_starts_with($tipo, 'video/') || in_array($tipo, ['application/mp4', 'application/octet-stream'], true);
    }

    private function limparEnviosEsquecidos(): void
    {
        /** @var Filesystem $disco */
        $disco = Storage::disk('local');
        $limite = now()->subDay()->getTimestamp();

        foreach ($disco->files(self::PASTA_ENVIOS) as $arquivo) {
            if ($disco->lastModified($arquivo) < $limite) {
                $disco->delete($arquivo);
            }
        }
    }
}
