<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Modules\Fiscal\Models\NumeracaoOcorrencia;
use App\Modules\Fiscal\Services\FechamentoFiscalService;
use App\Modules\Fiscal\Services\InutilizacaoService;
use App\Modules\Fiscal\Services\UfespService;
use App\Services\NFe\NFeCertificateNotConfiguredException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/** Notas fiscais > Fechamento do mês (ver FechamentoFiscalService). */
class FiscalClosingController extends Controller
{
    public function __construct(private readonly FechamentoFiscalService $fechamento) {}

    public function index(Request $request, UfespService $ufesp): Response
    {
        $mes = $this->mes($request->string('mes')->toString());
        $relatorio = $this->fechamento->gerar($mes);
        unset($relatorio['notas']);

        $ano = (int) now()->year;

        return Inertia::render('Admin/Invoices/Fechamento', [
            'relatorio' => $relatorio,
            'meses' => collect(range(0, 12))->map(fn ($i) => now()->startOfMonth()->subMonthsNoOverflow($i))
                ->map(fn (Carbon $m) => ['valor' => $m->format('Y-m'), 'rotulo' => ucfirst($m->locale('pt_BR')->translatedFormat('F/Y'))])->values(),
            'enviadoEm' => Setting::get('fiscal.fechamento.enviado.'.$mes->format('Y-m')),
            'destinatario' => config('nfe.fechamento_email') ?: 'usuários admin',
            'duplicidadesAbertas' => NumeracaoOcorrencia::query()->duplicidadesAbertas()->latest()->get()
                ->map(fn (NumeracaoOcorrencia $o) => [
                    'id' => $o->id, 'serie' => $o->serie, 'numero' => $o->numero_inicial, 'pedido' => $o->order_id,
                    'motivo' => $o->motivo, 'criado_em' => $o->created_at?->format('d/m/Y H:i'),
                ]),
            'ufesp' => [
                'atual' => $ufesp->registro($ano) ?? ['ano' => $ano, 'valor' => $ufesp->valor($ano), 'base_legal' => null, 'fonte' => null],
                'proximo' => $ufesp->registro($ano + 1),
            ],
        ]);
    }

    public function zip(string $mes): BinaryFileResponse
    {
        $relatorio = $this->fechamento->gerar($this->mes($mes));
        $caminho = $this->fechamento->zip($relatorio);

        return response()->download(Storage::disk('local')->path($caminho), basename($caminho));
    }

    public function csv(string $mes): HttpResponse
    {
        $relatorio = $this->fechamento->gerar($this->mes($mes));

        return response($this->fechamento->csv($relatorio), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"notas-{$relatorio['mes']}.csv\"",
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $mes = $this->mes($request->string('mes')->toString());
        $codigo = Artisan::call('fiscal:fechamento-mensal', ['--mes' => $mes->format('Y-m'), '--forcar' => true]);
        $saida = trim(Artisan::output());

        return back()->with($codigo === 0 ? 'success' : 'error', $saida ?: 'Falha ao enviar o fechamento.');
    }

    public function inutilize(Request $request, InutilizacaoService $inutilizacao): RedirectResponse
    {
        $dados = $request->validate([
            'inicio' => ['required', 'integer', 'min:1'],
            'fim' => ['required', 'integer', 'gte:inicio'],
            'justificativa' => ['required', 'string', 'min:15', 'max:255'],
            'confirmacao' => ['required', 'in:INUTILIZAR'],
        ], ['confirmacao.in' => 'Digite INUTILIZAR para confirmar.']);

        try {
            $o = $inutilizacao->inutilizar((int) $dados['inicio'], (int) $dados['fim'], $dados['justificativa'], $request->user());
        } catch (NFeCertificateNotConfiguredException) {
            return back()->with('error', 'Certificado digital não configurado.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Erro ao falar com a SEFAZ: '.$e->getMessage());
        }

        return back()->with('success', "Números {$o->numero_inicial} a {$o->numero_final} inutilizados na SEFAZ (protocolo {$o->protocolo}).");
    }

    public function resolveDuplicate(Request $request, NumeracaoOcorrencia $ocorrencia): RedirectResponse
    {
        abort_unless($ocorrencia->tipo === NumeracaoOcorrencia::TIPO_DUPLICIDADE, 404);

        $ocorrencia->update(['resolvido_em' => now(), 'user_id' => $request->user()?->id]);

        return back()->with('success', "Duplicidade da nota {$ocorrencia->numero_inicial} marcada como conferida.");
    }

    public function updateUfesp(Request $request, UfespService $ufesp): RedirectResponse
    {
        if ($request->boolean('buscar')) {
            try {
                $r = $ufesp->atualizar((int) $request->integer('ano', (int) now()->year));
            } catch (Throwable $e) {
                return back()->with('error', $e->getMessage());
            }

            return back()->with('success', "UFESP {$r['ano']} confirmada na página oficial: R$ ".number_format($r['valor'], 2, ',', '.').'.');
        }

        $dados = $request->validate([
            'ano' => ['required', 'integer', 'between:2020,2100'],
            'valor' => ['required', 'numeric', 'between:1,1000'],
            'base_legal' => ['nullable', 'string', 'max:255'],
        ]);

        $ufesp->definir((int) $dados['ano'], (float) $dados['valor'], $dados['base_legal'] ?? null);

        return back()->with('success', "UFESP {$dados['ano']} gravada.");
    }

    private function mes(string $valor): Carbon
    {
        return preg_match('/^\d{4}-\d{2}$/', $valor)
            ? Carbon::createFromFormat('Y-m-d', "{$valor}-01")->startOfMonth()
            : now()->startOfMonth()->subMonthNoOverflow();
    }
}
