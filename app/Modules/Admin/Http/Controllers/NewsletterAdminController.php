<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Newsletter\Mail\BlogPorEmail;
use App\Modules\Newsletter\Models\NewsletterEnvio;
use App\Modules\Newsletter\Models\NewsletterInscrito;
use App\Modules\Newsletter\Support\ResumoDoBlog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin > Newsletter (pedido do Lira 24/11/2026): contagem e lista de
 * inscritos, CSV, prévia do resumo do blog e "Enviar teste para mim" (só
 * para o e-mail de quem clicou; nunca para a lista).
 */
class NewsletterAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), [NewsletterInscrito::ATIVO, NewsletterInscrito::PENDENTE, NewsletterInscrito::DESCADASTRADO], true)
            ? $request->query('status') : null;
        $busca = trim((string) $request->query('busca', ''));

        $inscritos = NewsletterInscrito::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('email', 'like', '%'.$busca.'%')->orWhere('nome', 'like', '%'.$busca.'%')))
            ->orderByDesc('id')
            ->paginate(50, ['id', 'email', 'nome', 'status', 'origem', 'created_at', 'confirmado_em', 'descadastrado_em', 'ultimo_envio_em'])
            ->withQueryString();

        $contagem = NewsletterInscrito::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $resumo = ResumoDoBlog::do();

        return Inertia::render('Admin/Newsletter/Index', [
            'inscritos' => $inscritos,
            'contagem' => [
                'ativos' => (int) ($contagem[NewsletterInscrito::ATIVO] ?? 0),
                'pendentes' => (int) ($contagem[NewsletterInscrito::PENDENTE] ?? 0),
                'descadastrados' => (int) ($contagem[NewsletterInscrito::DESCADASTRADO] ?? 0),
            ],
            'filtros' => ['status' => $status, 'busca' => $busca],
            'blog' => [
                'agendado' => (bool) config('newsletter.blog_agendado'),
                'frequencia' => $resumo->frequencia,
                'hora' => (string) config('newsletter.blog_hora'),
                'assunto' => $resumo->vazio() ? null : $resumo->assunto(),
                'materias' => $resumo->posts->count(),
            ],
            'envios' => NewsletterEnvio::query()->latest('id')->limit(10)->get(['id', 'referencia', 'assunto', 'destinatarios', 'lotes', 'created_at']),
        ]);
    }

    public function exportar(Request $request): StreamedResponse
    {
        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => 'export',
            'entity' => 'NewsletterInscrito',
            'entity_id' => null,
            'old_values' => null,
            'new_values' => ['ip' => $request->ip()],
            'created_at' => now(),
        ]);

        return response()->streamDownload(function () {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['E-mail', 'Nome', 'Situação', 'Inscrição', 'Confirmação', 'Saída', 'Último envio', 'Origem'], ';');
            $data = fn ($d) => $d?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? '';

            foreach (NewsletterInscrito::query()->orderBy('id')->cursor() as $i) {
                fputcsv($saida, [
                    self::celula($i->email), self::celula((string) $i->nome), $i->status,
                    $data($i->created_at), $data($i->confirmado_em), $data($i->descadastrado_em), $data($i->ultimo_envio_em),
                    self::celula((string) $i->origem),
                ], ';');
            }

            fclose($saida);
        }, 'newsletter-inscritos-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Prévia no navegador do resumo de hoje (ou do último dia com matéria). */
    public function previa(Request $request): \Illuminate\Http\Response
    {
        $resumo = $this->resumoParaTeste($request);

        return response((new BlogPorEmail($resumo->assunto(), $resumo->itens(), url('/newsletter/sair/exemplo-de-link-de-saida'), $request->user()?->name))->render());
    }

    public function teste(Request $request): RedirectResponse
    {
        $email = (string) $request->user()?->email;
        $resumo = $this->resumoParaTeste($request);

        if ($email === '' || $resumo->vazio()) {
            return back()->with('error', $resumo->vazio() ? 'Ainda não há matéria publicada no blog para montar o e-mail.' : 'Seu usuário não tem e-mail.');
        }

        // O SMTP da Hostinger às vezes fecha a conexão no meio (500 em 25/11/2026): tenta de novo
        // uma vez e, se ainda falhar, avisa na tela em vez de estourar a página.
        try {
            retry(2, fn () => Mail::to($email)->send(new BlogPorEmail($resumo->assunto(), $resumo->itens(), url('/newsletter/sair/exemplo-de-link-de-saida'), $request->user()->name, teste: true)), 3000);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'O servidor de e-mail não respondeu agora. Tente de novo em 1 minuto.');
        }

        return back()->with('success', "E-mail de teste enviado para {$email}.");
    }

    /** Resumo de hoje; sem matéria hoje, o do último dia que teve, para o teste nunca sair vazio. */
    private function resumoParaTeste(Request $request): ResumoDoBlog
    {
        $resumo = ResumoDoBlog::do($request->query('data') ?: null);
        if (! $resumo->vazio()) {
            return $resumo;
        }

        $ultima = \App\Modules\Editorial\Models\EditorialPost::query()->doSite('kazakora')->visible()->latest('published_at')->value('published_at');

        return $ultima ? ResumoDoBlog::do(\Illuminate\Support\Carbon::parse($ultima)->toDateString()) : $resumo;
    }

    /** Planilha não executa fórmula vinda de dado digitado por visitante. */
    private static function celula(string $valor): string
    {
        return preg_match('/^[=+\-@\t\r]/', $valor) ? "'".$valor : $valor;
    }
}
