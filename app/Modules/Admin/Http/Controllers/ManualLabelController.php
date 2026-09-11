<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Marketplace\Support\LabelFetchService;
use App\Modules\Marketplace\Support\LabelProcessingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Gera etiqueta manualmente a partir de conteúdo ZPL colado/enviado (sem
 * pedido/produto associado — order_id fica nulo, que é o próprio sinal
 * usado aqui pra distinguir "veio dessa tela" dos PrintJobs reais do
 * pipeline automático). Reaproveita a mesma fila (PrintJob) que o KoraSync
 * já consome, então uma etiqueta gerada aqui é impressa do mesmo jeito.
 */
class ManualLabelController extends Controller
{
    /**
     * Canais que a etiqueta avulsa aceita.
     *
     * TikTok Shop e Shein ficam DE FORA — regra do usuário, repetida três
     * vezes (2026-09-06, 2026-09-07 e 2026-09-10): **a etiqueta do TikTok
     * só sai no painel do próprio canal; aqui só Shopee e Mercado Livre**.
     * Mandar a do TikTok pra impressora da loja trava a térmica (já
     * queimou etiqueta duas vezes).
     *
     * Esta tela era o último caminho que ainda furava: todos os outros
     * (automático, lote, reimprimir) já checam
     * LabelFetchService::CANAIS_SEM_IMPRESSAO_NOSSA, mas aqui o canal era
     * escolhido na mão numa lista que oferecia TikTok. A lista agora é
     * derivada da mesma constante — fonte única, e canal novo que entrar
     * na proibição some daqui sozinho.
     */
    private const CHANNELS = [
        MarketplaceAccount::CHANNEL_SHOPEE => 'Shopee',
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE => 'Mercado Livre',
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP => 'TikTok Shop',
        MarketplaceAccount::CHANNEL_AMAZON => 'Amazon',
    ];

    /** @return array<string, string> */
    private static function canaisPermitidos(): array
    {
        return array_diff_key(self::CHANNELS, array_flip(LabelFetchService::CANAIS_SEM_IMPRESSAO_NOSSA));
    }

    /**
     * Cópia fixa do PDF de agradecimento no disco 'local' (mesmo disco que
     * PrintAgentController::label() lê) — copiada uma vez no deploy a
     * partir de storage/app/public/images/etiqueta_obrigado.pdf, pra não
     * precisar duplicar o arquivo a cada job criado.
     */
    private const THANK_YOU_LABEL_PATH = 'labels/etiqueta_obrigado.pdf';

    public function create(): Response
    {
        return Inertia::render('Admin/EtiquetasManuais/Form', [
            'channels' => $this->channelOptions(),
        ]);
    }

    public function store(Request $request, LabelProcessingService $processor): RedirectResponse
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::in(array_keys(self::canaisPermitidos()))],
            'file' => ['required_without:content', 'nullable', 'file', 'mimes:txt', 'max:2048'],
            'content' => ['required_without:file', 'nullable', 'string'],
            'print_thank_you' => ['nullable', 'boolean'],
            'duas_colunas' => ['nullable', 'boolean'],
        ]);

        $rawContent = $request->hasFile('file')
            ? $request->file('file')->get()
            : $validated['content'];

        try {
            $pdfBytes = $processor->convertZplToPdf($rawContent);

            // Rolo de etiqueta pequena de 2 colunas (5 x 2,5 cm, vão de
            // 2 mm) — ver LabelProcessingService::montarDuasColunas().
            if ($request->boolean('duas_colunas')) {
                $pdfBytes = $processor->montarDuasColunas($pdfBytes);
            }
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Falha ao gerar o PDF da etiqueta: '.$exception->getMessage());
        }

        $path = 'labels/manual-'.now()->timestamp.'-'.uniqid().'.pdf';
        Storage::disk('local')->put($path, $pdfBytes);

        $printJob = PrintJob::create([
            'order_id' => null,
            'channel' => $validated['channel'],
            'label_path' => $path,
            'origin' => PrintJob::ORIGEM_MANUAL,
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        $message = "Etiqueta #{$printJob->id} gerada — o KoraSync vai imprimir assim que estiver aberto.";

        if ($request->boolean('print_thank_you')) {
            if (! Storage::disk('local')->exists(self::THANK_YOU_LABEL_PATH)) {
                return redirect()
                    ->route('admin.etiquetas-manuais.listar')
                    ->with('warning', $message.' Etiqueta de agradecimento NÃO foi enfileirada: arquivo não encontrado no servidor.');
            }

            PrintJob::create([
                'order_id' => null,
                'channel' => $validated['channel'],
                'label_path' => self::THANK_YOU_LABEL_PATH,
                'origin' => PrintJob::ORIGEM_MANUAL,
            'status' => PrintJob::STATUS_QUEUED,
                'is_thank_you' => true,
            ]);

            $message .= ' Etiqueta de agradecimento enfileirada logo em seguida.';
        }

        return redirect()->route('admin.etiquetas-manuais.listar')->with('success', $message);
    }

    public function list(): Response
    {
        $jobs = PrintJob::query()
            ->whereNull('order_id')
            // Etiqueta de agradecimento é gerada automaticamente junto com a
            // principal (mesmo PrintJob que o KoraSync consome), mas não é
            // o que o usuário quer listar aqui — só a etiqueta em si, pedido
            // explícito.
            ->where('is_thank_you', false)
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PrintJob $job) => [
                'id' => $job->id,
                'channel' => self::CHANNELS[$job->channel] ?? $job->channel ?? '—',
                'status' => $job->status,
                'errorMessage' => $job->error_message,
                'createdAt' => $job->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                'printedAt' => $job->printed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Admin/EtiquetasManuais/Listar', [
            'jobs' => $jobs,
        ]);
    }

    public function show(PrintJob $printJob): Response
    {
        abort_if($printJob->order_id !== null, 404);

        return Inertia::render('Admin/EtiquetasManuais/Show', [
            'job' => [
                'id' => $printJob->id,
                'channel' => $printJob->channel,
                'isThankYou' => $printJob->is_thank_you,
                'status' => $printJob->status,
                'errorMessage' => $printJob->error_message,
                'createdAt' => $printJob->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            ],
            'channels' => $this->channelOptions(),
        ]);
    }

    public function pdf(PrintJob $printJob): HttpResponse
    {
        abort_if($printJob->order_id !== null, 404);
        abort_unless(Storage::disk('local')->exists($printJob->label_path), 404, 'Arquivo da etiqueta não encontrado.');

        return response(Storage::disk('local')->get($printJob->label_path), 200, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function update(Request $request, PrintJob $printJob, LabelProcessingService $processor): RedirectResponse
    {
        abort_if($printJob->order_id !== null, 404);

        if ($printJob->is_thank_you) {
            return back()->with('error', 'Etiqueta de agradecimento usa um arquivo fixo — não é editável por aqui.');
        }

        $validated = $request->validate([
            'channel' => ['required', Rule::in(array_keys(self::canaisPermitidos()))],
            'file' => ['nullable', 'file', 'mimes:txt', 'max:2048'],
            'content' => ['nullable', 'string'],
        ]);

        $printJob->channel = $validated['channel'];

        $rawContent = $request->hasFile('file') ? $request->file('file')->get() : ($validated['content'] ?? null);

        if (filled($rawContent)) {
            try {
                $pdfBytes = $processor->convertZplToPdf($rawContent);
            } catch (Throwable $exception) {
                report($exception);

                return back()->withInput()->with('error', 'Falha ao gerar o PDF da etiqueta: '.$exception->getMessage());
            }

            Storage::disk('local')->put($printJob->label_path, $pdfBytes);
            $printJob->status = PrintJob::STATUS_QUEUED;
            $printJob->error_message = null;
        }

        $printJob->save();

        return redirect()->route('admin.etiquetas-manuais.listar')->with('success', "Etiqueta #{$printJob->id} atualizada.");
    }

    public function destroy(PrintJob $printJob): RedirectResponse
    {
        abort_if($printJob->order_id !== null, 404);

        if (! $printJob->is_thank_you && Storage::disk('local')->exists($printJob->label_path)) {
            Storage::disk('local')->delete($printJob->label_path);
        }

        $printJob->delete();

        return back()->with('success', "Etiqueta #{$printJob->id} removida.");
    }

    /**
     * Só os canais permitidos — a lista da tela é que oferecia TikTok (ver
     * CHANNELS). A listagem continua traduzindo o nome de job antigo de
     * qualquer canal pelo CHANNELS completo.
     */
    private function channelOptions(): array
    {
        return collect(self::canaisPermitidos())->map(fn ($name, $channel) => ['value' => $channel, 'label' => $name])->values()->all();
    }
}
