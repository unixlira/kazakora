<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Marketplace\Support\LabelProcessingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Impressão em lote de etiquetas do Full (Mercado Envios Full) — pedido
 * explícito 2026-08-06.
 *
 * Versão 1 desta tela tentava buscar a etiqueta na API do ML a partir de
 * um "código" digitado (GET shipment_labels?shipment_ids=...) — corrigido
 * no mesmo dia depois de testar ao vivo: o usuário já baixa o ZPL pronto
 * direto do painel do Full (não precisa nenhuma chamada de API daqui), e
 * o download já vem com TODAS as etiquetas do lote concatenadas
 * (confirmado contra um arquivo real: 15 blocos ^XA...^XZ em sequência,
 * um por volume). Então essa tela é, na prática, a mesma coisa que
 * ManualLabelController já faz (colar/enviar ZPL -> PDF -> PrintJob) — só
 * que com entrada própria dentro da integração do Mercado Livre, pra ser
 * o lugar óbvio de colar isso toda vez que sair um envio Full, sem
 * misturar com a tela genérica de Etiquetas Manuais.
 *
 * Reaproveita LabelProcessingService::convertZplToPdf() sem nenhuma
 * mudança — a API pública da Labelary devolve um PDF de várias páginas
 * quando o ZPL de entrada tem vários blocos ^XA...^XZ, uma página por
 * etiqueta, então o PDF final já sai pronto pra imprimir tudo em
 * sequência.
 */
class MercadoLivreFullPrintController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Admin/Integracoes/MercadoLivre/ImpressaoFull');
    }

    public function store(Request $request, LabelProcessingService $processor): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required_without:content', 'nullable', 'file', 'mimes:txt', 'max:4096'],
            'content' => ['required_without:file', 'nullable', 'string'],
        ]);

        $rawContent = $request->hasFile('file')
            ? $request->file('file')->get()
            : $validated['content'];

        try {
            $pdfBytes = $processor->convertZplToPdf($rawContent);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Falha ao gerar o PDF das etiquetas: '.$exception->getMessage());
        }

        $path = 'labels/full-'.now()->timestamp.'-'.uniqid().'.pdf';
        Storage::disk('local')->put($path, $pdfBytes);

        // order_id nulo — igual à tela de Etiquetas Manuais: esse PDF pode
        // ter etiquetas de vários volumes/envios diferentes, não mapeia pra
        // um Order local específico.
        $printJob = PrintJob::create([
            'order_id' => null,
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'label_path' => $path,
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        return redirect()
            ->route('admin.integracoes.mercado-livre.impressao-full')
            ->with('success', "Etiqueta #{$printJob->id} gerada — o KoraSync vai imprimir tudo em sequência assim que estiver aberto.");
    }
}
