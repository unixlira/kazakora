<?php

namespace App\Modules\Operacional\Support;

use App\Modules\Cadastros\Models\Supplier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Fiscal\Models\ProductFiscalData;
use InvalidArgumentException;
use SimpleXMLElement;

/**
 * Lê a NF-e de compra (XML do fornecedor) para pré-preencher a tela Compras
 * (pedido do Lira em 12/11/2026). Nada é gravado aqui: o usuário confere,
 * escolhe o produto que não casou e salva.
 *
 * Casamento do produto, nesta ordem: GTIN (product_fiscal_data.gtin), SKU
 * igual ao código do fornecedor (cProd), nome igual. Fornecedor: CNPJ/CPF,
 * depois nome.
 */
class LeitorXmlCompra
{
    /**
     * @return array{invoice_key: ?string, purchase_date: ?string, supplier_id: ?int, supplier_name: string, supplier_document: ?string, freight: float, other_costs: float, items: list<array{product_id: ?int, description: string, codigo: string, gtin: ?string, quantity: int, unit_cost: float}>}
     */
    public function ler(string $conteudo): array
    {
        // Sem namespace fica mais simples de navegar; LIBXML_NONET bloqueia
        // qualquer acesso à rede pelo XML.
        $semNs = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $conteudo);
        $xml = @simplexml_load_string((string) $semNs, SimpleXMLElement::class, LIBXML_NONET);
        if (! $xml) {
            throw new InvalidArgumentException('Não consegui ler esse arquivo como XML de NF-e.');
        }

        $inf = $xml->NFe->infNFe ?? $xml->infNFe ?? null;
        if (! $inf || ! isset($inf->det)) {
            throw new InvalidArgumentException('O XML não parece uma NF-e (sem infNFe/det).');
        }

        $chave = preg_replace('/\D/', '', (string) ($inf['Id'] ?? ''));
        $emit = $inf->emit;
        $documento = preg_replace('/\D/', '', (string) ($emit->CNPJ ?? $emit->CPF ?? '')) ?: null;
        $nome = trim((string) ($emit->xNome ?? ''));
        $fornecedor = $documento
            ? Supplier::query()->get(['id', 'document'])->first(fn (Supplier $s) => preg_replace('/\D/', '', (string) $s->document) === $documento)
            : null;
        $fornecedor ??= $nome !== '' ? Supplier::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($nome)])->first() : null;

        $tot = $inf->total->ICMSTot ?? null;
        $valor = fn ($campo) => (float) ($tot?->{$campo} ?? 0);
        $outros = $valor('vSeg') + $valor('vOutro') + $valor('vIPI') + $valor('vST') - $valor('vDesc');

        $itens = [];
        foreach ($inf->det as $det) {
            $prod = $det->prod;
            $gtin = trim((string) ($prod->cEAN ?? ''));
            $gtin = ctype_digit($gtin) ? $gtin : null;
            $codigo = trim((string) ($prod->cProd ?? ''));
            $descricao = trim((string) ($prod->xProd ?? ''));

            $itens[] = [
                'product_id' => $this->casarProduto($gtin, $codigo, $descricao),
                'description' => $descricao,
                'codigo' => $codigo,
                'gtin' => $gtin,
                'quantity' => max(1, (int) round((float) ($prod->qCom ?? 1))),
                'unit_cost' => round((float) ($prod->vUnCom ?? 0), 2),
            ];
        }

        $data = (string) ($inf->ide->dhEmi ?? $inf->ide->dEmi ?? '');

        return [
            'invoice_key' => strlen((string) $chave) === 44 ? $chave : null,
            'purchase_date' => $data !== '' ? substr($data, 0, 10) : null,
            'supplier_id' => $fornecedor?->id,
            'supplier_name' => $fornecedor?->name ?? $nome,
            'supplier_document' => $documento,
            'freight' => round($valor('vFrete'), 2),
            'other_costs' => round(max(0.0, $outros), 2),
            'items' => $itens,
        ];
    }

    private function casarProduto(?string $gtin, string $codigo, string $descricao): ?int
    {
        if ($gtin) {
            $id = ProductFiscalData::query()->where('gtin', $gtin)->value('product_id');
            if ($id) {
                return (int) $id;
            }
        }

        if ($codigo !== '') {
            $id = Product::query()->where('sku', $codigo)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        if ($descricao !== '') {
            $id = Product::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($descricao)])->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }
}
