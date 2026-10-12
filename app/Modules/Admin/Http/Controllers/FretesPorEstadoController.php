<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Services\CorreiosTabelaPorEstado;
use App\Modules\Checkout\Services\MelhorEnvioTabelaPorEstado;
use App\Services\MelhorEnvio\MelhorEnvioAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Logística → Fretes por estado (pedido do Lira em 2026-10-12): PAC e SEDEX
 * dos Correios para as 27 capitais, com o preço do contrato da KazaKora, para
 * um pacote escolhido (produto cadastrado ou medidas digitadas). Só consulta:
 * não cria postagem nem muda frete do site.
 *
 * Duas abas (2026-10-12): Correios (contrato próprio) e Melhor Envio (todas
 * as transportadoras da conta conectada), para comparar e decidir o frete
 * grátis. O pacote é o mesmo nas duas.
 */
class FretesPorEstadoController extends Controller
{
    /** Pacote inicial da tela: uma caixa pequena típica da loja. */
    private const PACOTE_PADRAO = ['peso_g' => 500, 'comprimento' => 20, 'largura' => 15, 'altura' => 10, 'valor' => 50];

    public function index(MelhorEnvioAuthService $melhorEnvio): Response
    {
        $produtos = Product::query()
            ->where('is_active', true)
            ->whereHas('fiscalData', fn ($q) => $q->where('peso_bruto', '>', 0)
                ->where('altura_cm', '>', 0)->where('largura_cm', '>', 0)->where('profundidade_cm', '>', 0))
            ->with('fiscalData:id,product_id,peso_bruto,altura_cm,largura_cm,profundidade_cm')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'price'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'nome' => $p->name,
                'sku' => $p->sku,
                'pacote' => self::pacoteDoProduto($p),
            ])
            ->values();

        return Inertia::render('Admin/FretesPorEstado/Index', [
            'produtos' => $produtos,
            'pacotePadrao' => self::PACOTE_PADRAO,
            'melhorEnvio' => [
                'conectado' => (bool) $melhorEnvio->currentToken(),
                'ambiente' => MelhorEnvioTabelaPorEstado::ambiente(),
            ],
        ]);
    }

    public function cotar(Request $request, CorreiosTabelaPorEstado $tabela): JsonResponse
    {
        $pacote = $this->pacote($request);

        return $pacote instanceof JsonResponse ? $pacote : response()->json($tabela->cotar($pacote));
    }

    public function cotarMelhorEnvio(Request $request, MelhorEnvioTabelaPorEstado $tabela): JsonResponse
    {
        $pacote = $this->pacote($request);

        return $pacote instanceof JsonResponse ? $pacote : response()->json($tabela->cotar($pacote));
    }

    /** Pacote validado, ou a resposta 422 para a tela. */
    private function pacote(Request $request): array|JsonResponse
    {
        // Limites do PAC/SEDEX para "Pacote": até 30 kg, 16x11x2 cm no mínimo
        // e 100 cm por lado no máximo. Validator manual: fora de api/* o app
        // redireciona no erro de validação (bootstrap/app.php), e estas rotas
        // respondem JSON para a tela.
        $validador = Validator::make($request->all(), [
            'peso_g' => ['required', 'integer', 'min:1', 'max:30000'],
            'comprimento' => ['required', 'numeric', 'min:16', 'max:100'],
            'largura' => ['required', 'numeric', 'min:11', 'max:100'],
            'altura' => ['required', 'numeric', 'min:2', 'max:100'],
            'valor' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], [
            'peso_g.max' => 'O peso máximo do PAC e do SEDEX é 30 kg (30.000 g).',
            'comprimento.min' => 'O comprimento mínimo dos Correios é 16 cm.',
            'largura.min' => 'A largura mínima dos Correios é 11 cm.',
            'altura.min' => 'A altura mínima dos Correios é 2 cm.',
            'comprimento.max' => 'Cada lado pode ter no máximo 100 cm.',
            'largura.max' => 'Cada lado pode ter no máximo 100 cm.',
            'altura.max' => 'Cada lado pode ter no máximo 100 cm.',
        ]);

        if ($validador->fails()) {
            return response()->json(['message' => 'Medidas inválidas.', 'errors' => $validador->errors()], 422);
        }
        $dados = $validador->validated();

        return [
            'peso_g' => (int) $dados['peso_g'],
            'comprimento' => (float) $dados['comprimento'],
            'largura' => (float) $dados['largura'],
            'altura' => (float) $dados['altura'],
            'valor' => (float) ($dados['valor'] ?? 0),
        ];
    }

    /** Mesma regra do checkout (CorreiosFreightQuoteService): mínimos de 16x11x2 cm. */
    private static function pacoteDoProduto(Product $p): array
    {
        $f = $p->fiscalData;

        return [
            'peso_g' => max(1, (int) round((float) $f->peso_bruto * 1000)),
            'comprimento' => max(16.0, (float) $f->profundidade_cm),
            'largura' => max(11.0, (float) $f->largura_cm),
            'altura' => max(2.0, (float) $f->altura_cm),
            // Valor declarado (seguro do Melhor Envio) = preço do produto.
            'valor' => round((float) $p->price, 2),
        ];
    }
}
