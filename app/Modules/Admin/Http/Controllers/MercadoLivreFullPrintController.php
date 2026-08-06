<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Marketplace\Support\LabelProcessingService;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Impressão em lote de etiquetas do Full (Mercado Envios Full) — pedido
 * explícito 2026-08-06. Reaproveita o MESMO endpoint que
 * MercadoLivreDriver::fetchLabel() já usa pra 1 etiqueta
 * (GET shipment_labels?shipment_ids=...&response_type=...), só que aqui
 * passando o(s) código(s) que o usuário digitar direto pra
 * response_type=zpl2 — a API do ML devolve um único arquivo ZPL com todas
 * as etiquetas em sequência (confirmado contra um ZPL real baixado pelo
 * usuário: várias marcações ^XA...^XZ concatenadas, uma por volume/envio).
 *
 * Não implementado/confirmado nesta sessão: se o "código" que o usuário
 * tem em mãos é um shipment_id individual, uma lista deles, ou o ID do
 * envio "pai" (self-service inbound) que a API resolve sozinha pro lado
 * de dentro — não existe documentação pública clara pra essa resolução, e
 * não temos acesso a um envio Full real pra testar antes. A tela aceita
 * o texto como o usuário digitar (1 código ou vários separados por vírgula/
 * linha) e repassa direto pro parâmetro shipment_ids; se a API do ML
 * rejeitar, o erro real dela aparece na tela pra ajustar na hora.
 *
 * Reaproveita LabelProcessingService::convertZplToPdf() (mesma conversão
 * já usada pra Shopee/Etiquetas Manuais) — a API pública da Labelary
 * devolve um PDF de várias páginas quando o ZPL de entrada tem vários
 * blocos ^XA...^XZ, uma página por etiqueta, então o PDF final já sai
 * pronto pra imprimir tudo em sequência sem nenhum código novo de
 * paginação aqui.
 */
class MercadoLivreFullPrintController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Admin/Integracoes/MercadoLivre/ImpressaoFull');
    }

    public function store(Request $request, MercadoLivreClient $client, LabelProcessingService $processor): RedirectResponse
    {
        $validated = $request->validate([
            'codes' => ['required', 'string'],
        ]);

        // Aceita vírgula, quebra de linha ou espaço como separador — o
        // usuário pode ter só 1 código ou vários, não força um formato
        // específico.
        $ids = collect(preg_split('/[\s,]+/', trim($validated['codes'])))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return back()->withInput()->with('error', 'Informe pelo menos um código.');
        }

        try {
            $label = $client->getBinary('shipment_labels', [
                'shipment_ids' => $ids->implode(','),
                'response_type' => 'zpl2',
            ]);

            $pdfBytes = $processor->convertZplToPdf($label['contents']);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with(
                'error',
                'Falha ao buscar/converter a etiqueta no Mercado Livre: '.$exception->getMessage()
            );
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

        $count = $ids->count();
        $plural = $count > 1 ? "{$count} códigos" : '1 código';

        return redirect()
            ->route('admin.integracoes.mercado-livre.impressao-full')
            ->with('success', "Etiqueta #{$printJob->id} gerada a partir de {$plural} — o KoraSync vai imprimir tudo em sequência assim que estiver aberto.");
    }
}
