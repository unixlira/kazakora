<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Materiais\Models\LeadMaterial;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin > Atendimento > Leads de materiais (pedido do Lira 26/11/2026): quem
 * baixou um material gratuito (Guia de Viagem de Bolso da FerraKora), com a
 * origem do link (?origem=) e as UTMs. Só leitura e CSV (registrado na Auditoria).
 */
class LeadsMateriaisController extends Controller
{
    public function index(Request $request): Response
    {
        $origem = trim((string) $request->query('origem', ''));
        $busca = trim((string) $request->query('busca', ''));

        $leads = $this->filtrados($origem, $busca)
            ->orderByDesc('id')
            ->paginate(50)
            ->through(fn (LeadMaterial $l) => [
                'id' => $l->id,
                'material' => $l->material,
                'nome' => $l->nome,
                'email' => $l->email,
                'telefone' => $l->telefoneFormatado(),
                'whatsapp' => 'https://wa.me/'.$l->telefone,
                'local' => trim(($l->cidade_ip ?? '').($l->uf_ip ? ' / '.$l->uf_ip : '')) ?: null,
                'origem' => $l->origem,
                'utm' => array_filter([
                    'source' => $l->utm_source, 'medium' => $l->utm_medium, 'campaign' => $l->utm_campaign,
                    'content' => $l->utm_content, 'term' => $l->utm_term,
                ]),
                'aceita_contato' => $l->aceita_contato,
                'cadastros' => $l->cadastros,
                'downloads' => $l->downloads,
                'created_at' => $l->created_at,
            ])
            ->withQueryString();

        return Inertia::render('Admin/LeadsMateriais/Index', [
            'leads' => $leads,
            'total' => LeadMaterial::query()->count(),
            'hoje' => LeadMaterial::query()->where('created_at', '>=', now()->startOfDay())->count(),
            'porOrigem' => LeadMaterial::query()->selectRaw("coalesce(origem, '(sem origem)') as origem, count(*) as total")
                ->groupBy('origem')->orderByDesc('total')->limit(20)->pluck('total', 'origem'),
            'filtros' => ['origem' => $origem, 'busca' => $busca],
        ]);
    }

    public function exportar(Request $request): StreamedResponse
    {
        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => 'export',
            'entity' => 'LeadMaterial',
            'entity_id' => null,
            'old_values' => null,
            'new_values' => ['ip' => $request->ip(), 'origem' => $request->query('origem'), 'busca' => $request->query('busca')],
            'created_at' => now(),
        ]);
        $consulta = $this->filtrados(trim((string) $request->query('origem', '')), trim((string) $request->query('busca', '')));

        return response()->streamDownload(function () use ($consulta) {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Data', 'Material', 'Nome', 'E-mail', 'Celular', 'Cidade/UF (IP)', 'Origem', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'Aceita contato', 'Downloads'], ';');
            foreach ($consulta->orderBy('id')->cursor() as $l) {
                fputcsv($saida, array_map(fn ($v) => self::celula((string) $v), [
                    $l->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'), $l->material, $l->nome, $l->email,
                    $l->telefoneFormatado(), trim(($l->cidade_ip ?? '').' '.($l->uf_ip ?? '')), $l->origem, $l->utm_source,
                    $l->utm_medium, $l->utm_campaign, $l->utm_content, $l->aceita_contato ? 'sim' : 'não', $l->downloads,
                ]), ';');
            }
            fclose($saida);
        }, 'leads-materiais-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtrados(string $origem, string $busca)
    {
        return LeadMaterial::query()
            ->when($origem !== '', fn ($q) => $origem === '(sem origem)' ? $q->whereNull('origem') : $q->where('origem', $origem))
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('email', 'like', '%'.$busca.'%')
                ->orWhere('nome', 'like', '%'.$busca.'%')
                ->when(preg_replace('/\D/', '', $busca) !== '', fn ($t) => $t->orWhere('telefone', 'like', '%'.preg_replace('/\D/', '', $busca).'%'))));
    }

    /** Evita fórmula no Excel (CSV injection). */
    private static function celula(string $valor): string
    {
        return preg_match('/^[=+\-@\t\r]/', $valor) === 1 ? "'".$valor : $valor;
    }
}
