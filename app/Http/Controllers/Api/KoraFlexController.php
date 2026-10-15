<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\FlexPickupReceipt;
use App\Modules\Marketplace\Support\FlexPickupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;

/**
 * A API do KoraFlex — o app de celular que bipa o QR da etiqueta do Flex
 * (repositório à parte: github.com/unixlira/koraflex).
 *
 * O ciclo inteiro de uma caixa do Flex, em rotas: o que sai hoje (`dia`),
 * a bipada que a dá como pronta (`bipar`), a entrega ao entregador com
 * comprovante (`entregar`) ou sem (`coletar`), a imagem do comprovante e o
 * `desfazer` de um passo.
 *
 * A regra toda (janela do corte, pack com uma etiqueta só, venda
 * cancelada, consentimento) mora no FlexPickupService — o app é tela, o
 * dado é daqui. Mesma divisão que vale pro KoraSync Web.
 */
class KoraFlexController extends Controller
{
    public function __construct(private readonly FlexPickupService $flex) {}

    /** O que a transportadora tem que levar hoje. */
    public function dia(Request $request): JsonResponse
    {
        $dia = $this->flex->dia();
        $aviso = $this->avisoDeAtualizacao($request);

        if ($aviso !== null) {
            $dia['aviso'] = $aviso;
            $dia['atrasados'] = [$this->cartaoDeAviso($aviso), ...array_values(collect($dia['atrasados'])->all())];
        }

        return response()->json($dia);
    }

    /**
     * APK antigo do KoraFlex (1.3.0 ou anterior) não sabe se atualizar e
     * ninguém lembra em que celular ele está. O 1.4.0 em diante manda
     * X-KoraFlex-Versao; quem chega SEM o cabeçalho e de dentro de um app
     * Android (WebView: "; wv)" no user-agent) recebe o recado. O PWA do
     * iPhone e o navegador não entram aqui: eles se atualizam pelo site.
     */
    private function avisoDeAtualizacao(Request $request): ?string
    {
        $aviso = trim((string) config('services.koraflex.update_notice'));

        if ($aviso === '' || $request->hasHeader('X-KoraFlex-Versao')) {
            return null;
        }

        return preg_match('/;\s*wv\)/', (string) $request->userAgent()) ? $aviso : null;
    }

    /**
     * O recado no formato de uma venda, porque é o único lugar que o app
     * antigo desenha texto vindo do servidor. Pedido 0, sem peças e com
     * etiqueta "impressa" pra não acender alerta; estado pendente só pra o
     * cartão ficar em destaque (não entra em Faltam, que vem do servidor).
     */
    private function cartaoDeAviso(string $aviso): array
    {
        return [
            'pedido' => 0,
            'venda' => null,
            'envio' => null,
            'rastreio' => null,
            'cliente' => $aviso,
            'cidade' => 'aviso do sistema, não é uma venda',
            'bairro' => null,
            'vendida_em' => '—',
            'itens' => [],
            'pecas' => 0,
            'estado' => FlexPickupService::ESTADO_PENDENTE,
            'separado_em' => null,
            'pronto_em' => null,
            'coletado_em' => null,
            'etiqueta_impressa' => true,
        ];
    }

    /**
     * Uma bipada. Responde 200 mesmo quando recusa: quem está com a caixa
     * na mão precisa VER o motivo na tela, e um 4xx no meio do galpão (com
     * sinal ruim) só vira "erro de conexão" genérico no app.
     */
    public function bipar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'qr' => ['required', 'string', 'max:2000'],
            'dispositivo' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($this->flex->bipar($dados['qr'], $dados['dispositivo'] ?? null));
    }

    /**
     * "Conferida e entregue": a tela do comprovante — assinatura do
     * entregador, foto tirada no envio e o registro do consentimento.
     *
     * A foto e a assinatura chegam como data URL do celular e só são
     * gravadas com `consentimento` verdadeiro; sem ele a entrega vira uma
     * baixa simples, sem recibo assinado. `aviso` é o TEXTO exato que a
     * pessoa leu na tela, gravado junto pra valer daqui a meses.
     */
    public function entregar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pedidos' => ['required', 'array', 'min:1', 'max:200'],
            'pedidos.*' => ['integer'],
            'consentimento' => ['required', 'boolean'],
            // ~2,7 MB de base64 = ~2 MB de imagem; o app manda bem menos.
            'assinatura' => ['nullable', 'string', 'max:2800000'],
            'foto' => ['nullable', 'string', 'max:7000000'],
            'nome' => ['nullable', 'string', 'max:120'],
            'aviso' => ['nullable', 'string', 'max:4000'],
            'dispositivo' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($this->flex->entregar(
            pedidos: $dados['pedidos'],
            consentimento: (bool) $dados['consentimento'],
            assinatura: $dados['assinatura'] ?? null,
            foto: $dados['foto'] ?? null,
            nome: $dados['nome'] ?? null,
            dispositivo: $dados['dispositivo'] ?? null,
            textoDoAviso: $dados['aviso'] ?? null,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));
    }

    /** A entrega sem comprovante: o entregador com pressa, ou que não quis assinar. */
    public function coletar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pedidos' => ['required', 'array', 'min:1', 'max:200'],
            'pedidos.*' => ['integer'],
            'dispositivo' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($this->flex->coletar($dados['pedidos'], $dados['dispositivo'] ?? null));
    }

    /**
     * A imagem de um comprovante (assinatura ou foto).
     *
     * Servida por rota autenticada, nunca de pasta pública: é a foto de uma
     * pessoa. Sem isso o recibo seria uma prova que ninguém consegue ver.
     */
    public function imagemDoRecibo(FlexPickupReceipt $recibo, string $tipo): HttpResponse
    {
        $caminho = $tipo === 'assinatura' ? $recibo->signature_path : ($tipo === 'foto' ? $recibo->photo_path : null);

        abort_unless($caminho && Storage::disk('local')->exists($caminho), 404, 'Esse comprovante não tem essa imagem.');

        return response(Storage::disk('local')->get($caminho), 200, [
            'Content-Type' => str_ends_with($caminho, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Bipou errado, a caixa voltou, ou marcou entregue antes da hora. */
    public function desfazer(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pedido' => ['required', 'integer'],
        ]);

        return response()->json($this->flex->desfazer($dados['pedido']));
    }
}
