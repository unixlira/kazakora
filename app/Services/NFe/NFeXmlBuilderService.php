<?php

namespace App\Services\NFe;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use Illuminate\Support\Str;
use NFePHP\NFe\Make;
use RuntimeException;
use stdClass;

/**
 * Monta o XML da NF-e (modelo 55, layout 4.00) a partir do pedido + dados
 * fiscais já cadastrados (Company = emitente, ProductFiscalData por item).
 * Não depende do certificado digital — só monta e valida a estrutura, a
 * assinatura é uma etapa separada (NFeCertificateService/Tools::signNFe).
 */
class NFeXmlBuilderService
{
    public function __construct(private readonly IbgeMunicipioResolver $ibge)
    {
    }

    public function build(Order $order, int $numero): array
    {
        $company = Company::query()->firstOrFail();
        $order->loadMissing(['items.product.fiscalData', 'user', 'payments']);

        if ($order->items->isEmpty()) {
            throw new RuntimeException('Pedido sem itens — não é possível montar a NF-e.');
        }

        foreach ($order->items as $item) {
            if (! $item->product?->fiscalData) {
                throw new RuntimeException("Produto \"{$item->product_name}\" não tem dados fiscais cadastrados.");
            }
        }

        $make = new Make();

        $cUF = (int) config('nfe.cuf');
        $tpAmb = config('nfe.ambiente') === 'producao' ? 1 : 2;
        $cMunFG = $this->ibge->resolve($company->city, $company->state);

        $infNFe = new stdClass();
        $infNFe->versao = '4.00';
        $make->taginfNFe($infNFe);

        $ide = new stdClass();
        $ide->cUF = $cUF;
        $ide->natOp = 'Venda de mercadoria';
        $ide->mod = 55;
        $ide->serie = config('nfe.serie');
        $ide->nNF = $numero;
        $ide->tpNF = 1; // saída
        $ide->idDest = $order->shipping_state === $company->state ? 1 : 2;
        $ide->cMunFG = $cMunFG;
        $ide->tpImp = 1; // retrato
        $ide->tpEmis = 1; // emissão normal
        $ide->tpAmb = $tpAmb;
        $ide->finNFe = 1; // normal
        $ide->indFinal = 1; // consumidor final
        $ide->indPres = 2; // não presencial, internet
        $ide->procEmi = 0;
        $ide->verProc = '1.0.0';
        $make->tagide($ide);

        $emit = new stdClass();
        $emit->xNome = $company->razao_social;
        $emit->xFant = $company->nome_fantasia;
        $emit->IE = $company->inscricao_estadual;
        // Estava fixo em 1 (Simples Nacional) sempre, ignorando o regime
        // real cadastrado — rejeição real da SEFAZ em produção 2026-08-02
        // ("Código Regime Tributário do emitente diverge do cadastro")
        // porque o valor enviado não bate com o que está registrado pra
        // esse CNPJ. CRT=2 (Simples Nacional excesso de sublimite) não tem
        // como inferir de Company::regime_tributario — se for esse o caso,
        // precisa de campo/config novo, não dá pra adivinhar.
        $emit->CRT = match ($company->regime_tributario) {
            Company::REGIME_LUCRO_PRESUMIDO, Company::REGIME_LUCRO_REAL => 3,
            default => 1, // simples_nacional e mei
        };
        $emit->CNPJ = preg_replace('/\D/', '', $company->cnpj);
        $emit->CNAE = $company->cnae;
        $make->tagemit($emit);

        $enderEmit = new stdClass();
        $enderEmit->xLgr = $company->street;
        $enderEmit->nro = $company->number;
        $enderEmit->xCpl = $company->complement;
        $enderEmit->xBairro = $company->neighborhood;
        $enderEmit->cMun = $cMunFG;
        $enderEmit->xMun = $company->city;
        $enderEmit->UF = $company->state;
        $enderEmit->CEP = preg_replace('/\D/', '', (string) $company->zip);
        $enderEmit->cPais = 1058;
        $enderEmit->xPais = 'Brasil';
        $enderEmit->fone = preg_replace('/\D/', '', (string) $company->phone);
        $make->tagenderEmit($enderEmit);

        $customer = $order->user;
        $cMunDest = $this->ibge->resolve($order->shipping_city, $order->shipping_state);

        // Pedido de canal externo não tem Order::user (user_id fica null
        // em importação de marketplace) — o CPF real vem de
        // buyer_document, capturado na importação via endpoint dedicado
        // do canal (ver MercadoLivreDriver::importOrder()). Pedido do
        // site sempre tem um user local com cpf (checkout, inclusive
        // convidado, exige o campo).
        $document = preg_replace('/\D/', '', (string) ($order->buyer_document ?: $customer?->cpf));

        if ($document === '') {
            // CNPJ/CPF/idEstrangeiro é elemento obrigatório no schema do
            // modelo 55 antes de xNome — sem isso o XML é inválido e a
            // SEFAZ rejeita (confirmado ao vivo, 2026-08-02: erro
            // "Element xNome: not expected"). Falha cedo com mensagem
            // clara em vez de deixar o validador do schema estourar com
            // um erro críptico.
            throw new RuntimeException("Pedido #{$order->id}: não foi possível identificar o CPF/CNPJ do comprador — não é possível emitir NF-e sem essa informação.");
        }

        $dest = new stdClass();
        $dest->xNome = $order->shipping_name;
        $dest->indIEDest = 9; // não contribuinte
        $dest->email = $customer?->email;

        if (strlen($document) === 14) {
            $dest->CNPJ = $document;
        } else {
            $dest->CPF = $document;
        }

        $make->tagdest($dest);

        // Str::limit(..., 60) nos campos de texto livre abaixo: o schema da
        // NFe limita xLgr/xCpl/xBairro/xMun a 60 caracteres, mas pedido
        // importado de marketplace usa texto livre sem esse limite (ex:
        // Mercado Livre manda o "comment" do comprador, tipo instrução de
        // entrega, direto pro complemento) — sem isso o XML falha na
        // validação local do sped-nfe e a emissão nunca sai do lugar
        // (bug real em produção, pedidos #15/#16, 2026-08-03).
        $enderDest = new stdClass();
        $enderDest->xLgr = Str::limit((string) $order->shipping_street, 60, '');
        $enderDest->nro = $order->shipping_number;
        $enderDest->xCpl = Str::limit((string) $order->shipping_complement, 60, '');
        $enderDest->xBairro = Str::limit((string) $order->shipping_neighborhood, 60, '');
        $enderDest->cMun = $cMunDest;
        $enderDest->xMun = Str::limit((string) $order->shipping_city, 60, '');
        $enderDest->UF = $order->shipping_state;
        $enderDest->CEP = preg_replace('/\D/', '', $order->shipping_zip);
        $enderDest->cPais = 1058;
        $enderDest->xPais = 'Brasil';
        $enderDest->fone = preg_replace('/\D/', '', (string) $order->shipping_phone);
        $make->tagenderDest($enderDest);

        $totalVProd = 0.0;

        foreach ($order->items as $index => $item) {
            $n = $index + 1;
            $fiscal = $item->product->fiscalData;
            $cfop = $order->shipping_state === $company->state ? $fiscal->cfop : $fiscal->cfop_outros_estados;

            $prod = new stdClass();
            $prod->item = $n;
            $prod->cProd = $item->product->sku;
            $prod->cEAN = $fiscal->gtin ?: 'SEM GTIN';
            $prod->xProd = $item->product_name;
            $prod->NCM = $fiscal->ncm;
            $prod->CFOP = $cfop;
            $prod->uCom = $fiscal->unidade_tributavel;
            $prod->qCom = (float) $item->quantity;
            $prod->vUnCom = (float) $item->product_price;
            $prod->vProd = (float) $item->subtotal;
            $prod->cEANTrib = $fiscal->gtin ?: 'SEM GTIN';
            $prod->uTrib = $fiscal->unidade_tributavel;
            $prod->qTrib = (float) $item->quantity;
            $prod->vUnTrib = (float) $item->product_price;
            $prod->indTot = 1;
            if ($fiscal->cest) {
                $prod->CEST = $fiscal->cest;
            }
            $make->tagprod($prod);

            $totalVProd += (float) $item->subtotal;

            $imposto = new stdClass();
            $imposto->item = $n;
            $imposto->vTotTrib = round((float) $item->subtotal * (float) ($fiscal->percentual_aproximado_tributos ?? 0) / 100, 2);
            $make->tagimposto($imposto);

            $icms = new stdClass();
            $icms->item = $n;
            $icms->orig = $fiscal->origem;
            $icms->CSOSN = $fiscal->icms_situacao_tributaria;
            $make->tagICMSSN($icms);

            $pisAliquota = (float) ($fiscal->pis_aliquota ?? 0);
            $pis = new stdClass();
            $pis->item = $n;
            $pis->CST = $fiscal->pis_situacao_tributaria;
            $pis->vBC = $pisAliquota > 0 ? (float) $item->subtotal : 0;
            $pis->pPIS = $pisAliquota;
            $pis->vPIS = round((float) $item->subtotal * $pisAliquota / 100, 2);
            $make->tagPIS($pis);

            $cofinsAliquota = (float) ($fiscal->cofins_aliquota ?? 0);
            $cofins = new stdClass();
            $cofins->item = $n;
            $cofins->CST = $fiscal->cofins_situacao_tributaria;
            $cofins->vBC = $cofinsAliquota > 0 ? (float) $item->subtotal : 0;
            $cofins->pCOFINS = $cofinsAliquota;
            $cofins->vCOFINS = round((float) $item->subtotal * $cofinsAliquota / 100, 2);
            $make->tagCOFINS($cofins);
        }

        $icmsTot = new stdClass();
        $icmsTot->vBC = 0;
        $icmsTot->vICMS = 0;
        $icmsTot->vICMSDeson = 0;
        $icmsTot->vBCST = 0;
        $icmsTot->vST = 0;
        $icmsTot->vProd = $totalVProd;
        $icmsTot->vFrete = (float) $order->shipping_cost;
        $icmsTot->vSeg = 0;
        $icmsTot->vDesc = (float) $order->discount_amount;
        $icmsTot->vII = 0;
        $icmsTot->vIPI = 0;
        $icmsTot->vPIS = 0;
        $icmsTot->vCOFINS = 0;
        $icmsTot->vOutro = 0;
        $icmsTot->vNF = (float) $order->total;
        $make->tagICMSTot($icmsTot);

        $transp = new stdClass();
        $transp->modFrete = 0; // por conta do emitente (CIF)
        $make->tagtransp($transp);

        $pag = new stdClass();
        $make->tagpag($pag);

        $paymentMethodCodes = [
            'card' => '03',
            'pix' => '17',
            'boleto' => '15',
        ];

        if ($order->payments->isEmpty()) {
            // Pedido de canal externo nunca tem Payment local — o
            // pagamento acontece do lado do marketplace, não aqui. SEFAZ
            // exige xPag (descrição) sempre que tPag=99 "outros"
            // (rejeição real 441, confirmada em produção 2026-08-02).
            $detPag = new stdClass();
            $detPag->indPag = 0;
            $detPag->tPag = '99';
            $detPag->xPag = $order->origin === Order::ORIGIN_STORE
                ? 'Pagamento processado externamente'
                : 'Pagamento processado pelo canal de origem do pedido';
            $detPag->vPag = (float) $order->total;
            $make->tagdetPag($detPag);
        } else {
            foreach ($order->payments as $payment) {
                $detPag = new stdClass();
                $detPag->indPag = 0;
                $detPag->tPag = $paymentMethodCodes[$payment->method_type] ?? '99';

                if ($detPag->tPag === '99') {
                    $detPag->xPag = "Pagamento via {$payment->method_type}";
                }

                $detPag->vPag = (float) $payment->amount;
                $make->tagdetPag($detPag);
            }
        }

        $infAdic = new stdClass();
        $infAdic->infCpl = "Pedido #{$order->id} - KazaKora";
        $make->taginfAdic($infAdic);

        $xml = $make->getXML();

        if (! empty($make->getErrors())) {
            throw new RuntimeException('Erros ao montar o XML da NF-e: '.implode(' | ', $make->getErrors()));
        }

        return [
            'xml' => $xml,
            'chave' => $make->getChave(),
        ];
    }
}
