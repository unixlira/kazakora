<?php

namespace App\Modules\Materiais\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Support\GeoDoVisitante;
use App\Modules\Materiais\Mail\MaterialPorEmail;
use App\Modules\Materiais\Models\LeadMaterial;
use App\Modules\Materiais\Support\DddPorLocal;
use App\Modules\Materiais\Support\EmailDeLead;
use App\Modules\Materiais\Support\Materiais;
use App\Modules\Materiais\Support\TelefoneBrasil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Captação de lead com material gratuito (pedido do Lira 26/11/2026): a
 * página /guia-de-viagem da FerraKora manda nome, e-mail e celular; aqui a
 * gente confere de novo (a tela já confere), grava o lead com a origem e as
 * UTMs do link e devolve o link assinado do PDF, que a tela baixa na hora.
 *
 *   GET  /api/ferrakora/materiais/local          DDD provável pelo IP (sem GPS)
 *   POST /api/ferrakora/materiais/{material}/lead cadastro (honeypot + limite por IP)
 *   GET  /api/ferrakora/materiais/{material}/pdf/{lead}?signature=  download
 *   GET  /api/ferrakora/materiais/sair/{lead}?signature=            sair dos contatos
 */
class MaterialLeadController extends Controller
{
    public function __construct(private readonly GeoDoVisitante $geo) {}

    public function local(Request $request): JsonResponse
    {
        $lugar = $this->lugar($request);

        return response()->json([
            'pais' => $lugar['pais'] ?? null,
            'uf' => $lugar['uf'] ?? null,
            'cidade' => $lugar['cidade'] ?? null,
            'ddd' => $lugar['ddd'] ?? null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function cadastrar(Request $request, string $material): JsonResponse
    {
        $dados = Materiais::dados($material);
        abort_if($dados === null, 404);

        if (! $this->origemPermitida($request, (string) $dados['site'])) {
            return response()->json(['message' => 'Origem não permitida.'], 403);
        }

        // Campo escondido que só robô preenche: finge que deu certo e não grava nada.
        if (filled($request->input('website'))) {
            return response()->json(['ok' => true, 'download' => null]);
        }

        $nome = Str::of(strip_tags((string) $request->input('nome')))->squish()->limit(120, '')->toString();
        $email = EmailDeLead::normalizar($request->input('email'));
        $nacional = TelefoneBrasil::nacional($request->input('telefone'));
        $lugar = $this->lugar($request);

        $erros = [];
        if (mb_strlen($nome) < 2 || preg_match('/[A-Za-zÀ-ÿ]{2}/u', $nome) !== 1) {
            $erros['nome'] = ['Digite seu nome.'];
        }
        if ($erro = EmailDeLead::erro($email)) {
            $erros['email'] = [$erro];
        }
        if ($erro = TelefoneBrasil::erro($nacional)) {
            $erros['telefone'] = [$erro];
        } elseif (substr($nacional, 0, 2) === '55' && ($lugar['uf'] ?? null) !== null && $lugar['uf'] !== 'RS'
            && ! $request->boolean('confirmou_ddd55')) {
            // O 55 é o código do Brasil; DDD 55 é só do noroeste do RS.
            $erros['telefone'] = ['O +55 do Brasil já está no campo. Seu DDD é mesmo 55 (noroeste do RS)? Se for, confirme; se não, digite o DDD da sua cidade.'];
            $erros['confirmou_ddd55'] = ['confirmar'];
        }
        if (! $request->boolean('aceite')) {
            $erros['aceite'] = ['Marque a caixinha para receber o guia.'];
        }

        if ($erros !== []) {
            return response()->json(['message' => reset($erros)[0], 'errors' => $erros], 422);
        }

        $livro = Materiais::livro($material);
        if ($livro === null) {
            Log::warning('materiais.pdf_ausente', ['material' => $material]);

            return response()->json(['message' => 'O guia está sendo atualizado. Tente de novo em alguns minutos.'], 503);
        }

        $texto = fn (string $campo, int $max) => filled($request->input($campo))
            ? Str::limit(strip_tags((string) $request->input($campo)), $max, '') : null;
        $origem = $texto('origem', 40);
        $origem = $origem !== null ? (Str::slug($origem) ?: null) : null;

        $lead = LeadMaterial::query()->firstOrNew(['material' => $material, 'email' => $email]);
        $novo = ! $lead->exists;
        $lead->fill([
            'site' => $dados['site'],
            'nome' => $nome,
            'telefone' => TelefoneBrasil::completo($nacional),
            'ddd' => substr($nacional, 0, 2),
            'uf_ip' => $lugar['uf'] ?? null,
            'cidade_ip' => $lugar['cidade'] ?? null,
            'ddd_sugerido' => $lugar['ddd'] ?? null,
            'aceita_contato' => true,
            'consentimento_versao' => (string) config('materiais.consentimento_versao'),
            'consentimento_texto' => (string) config('materiais.consentimento_texto'),
            'consentimento_em' => now(),
            'descadastrado_em' => null,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'referrer' => $texto('referrer', 255),
        ]);
        // Origem e UTMs: as do primeiro cadastro; num novo cadastro, só preenche o que faltava.
        foreach (['origem' => $origem, 'utm_source' => $texto('utm_source', 150), 'utm_medium' => $texto('utm_medium', 150),
            'utm_campaign' => $texto('utm_campaign', 150), 'utm_content' => $texto('utm_content', 150), 'utm_term' => $texto('utm_term', 150)] as $campo => $valor) {
            if ($novo || blank($lead->{$campo})) {
                $lead->{$campo} = $valor;
            }
        }
        if (! $novo) {
            $lead->cadastros = (int) $lead->cadastros + 1;
        }
        $lead->save();

        $link = Materiais::linkDoPdf($lead);

        // E-mail com o link: no máximo 1 a cada 10 minutos para o mesmo endereço.
        if (config('materiais.enviar_email', true) && ($lead->email_enviado_em === null || $lead->email_enviado_em->lt(now()->subMinutes(10)))) {
            try {
                Mail::to($lead->email)->queue(new MaterialPorEmail($lead, $link));
                $lead->forceFill(['email_enviado_em' => now()])->saveQuietly();
            } catch (Throwable $e) {
                Log::warning('materiais.email_falhou', ['erro' => $e::class]);
            }
        }

        return response()->json([
            'ok' => true,
            'download' => $link,
            'nome_arquivo' => $dados['nome_arquivo'] ?? $livro->nome_arquivo,
        ]);
    }

    public function pdf(Request $request, string $material, LeadMaterial $lead): StreamedResponse|Response
    {
        abort_unless($lead->material === $material, 404);
        $livro = Materiais::livro($material);
        abort_if($livro === null, 404);

        $lead->forceFill(['downloads' => (int) $lead->downloads + 1, 'ultimo_download_em' => now()])->saveQuietly();
        $nome = (string) (Materiais::dados($material)['nome_arquivo'] ?? $livro->nome_arquivo);

        return Storage::disk('local')->download($livro->arquivo, $nome, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function sair(LeadMaterial $lead): Response
    {
        $lead->forceFill(['aceita_contato' => false, 'descadastrado_em' => now()])->saveQuietly();

        return response(
            '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>FerraKora</title><body style="font-family:Arial,sans-serif;text-align:center;padding:48px 16px;color:#271e91">'
            .'<h1 style="font-size:22px">Pronto, você não vai mais receber nossos contatos.</h1>'
            .'<p><a href="'.e(rtrim((string) config('services.ferrakora.url'), '/')).'" style="color:#271e91">Voltar para a FerraKora</a></p></body>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex'],
        );
    }

    /** @return array{pais: ?string, uf: ?string, cidade: ?string, ddd: ?string}|array{} */
    private function lugar(Request $request): array
    {
        try {
            $lugar = $this->geo->localizar($request);
        } catch (Throwable) {
            $lugar = null;
        }
        if ($lugar === null) {
            return [];
        }

        return [
            'pais' => $lugar['pais'] ?? null,
            'uf' => ($lugar['pais'] ?? null) === 'BR' ? ($lugar['uf'] ?? null) : null,
            'cidade' => ($lugar['pais'] ?? null) === 'BR' ? ($lugar['cidade'] ?? null) : null,
            'ddd' => ($lugar['pais'] ?? null) === 'BR'
                ? DddPorLocal::sugerir($lugar['uf'] ?? null, $lugar['latitude'] ?? null, $lugar['longitude'] ?? null)
                : null,
        ];
    }

    private function origemPermitida(Request $request, string $site): bool
    {
        $url = (string) ($request->headers->get('origin') ?: $request->headers->get('referer'));
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        $dominios = array_values(array_filter(array_map(
            fn ($d) => preg_replace('/^www\./', '', strtolower(trim($d))),
            explode(',', (string) config("services.{$site}.origens")),
        )));

        return $host !== '' && in_array($host, $dominios, true);
    }
}
