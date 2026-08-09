<?php

namespace App\Services\NFe;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
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

        // Pedido explícito 2026-08-09: item pode ser um produto real do
        // catálogo (fiscalData já cadastrado) OU um item digitado na hora na
        // emissão manual (produto fora do catálogo ou serviço avulso — a
        // empresa tem 2 CNAEs) — nesse segundo caso os dados fiscais vêm das
        // colunas próprias do OrderItem em vez de product->fiscalData. Ver
        // resolveFiscalData().
        foreach ($order->items as $item) {
            if (! $item->product && ! $item->ncm) {
                throw new RuntimeException("Item \"{$item->product_name}\" não tem dados fiscais — nem produto do catálogo vinculado, nem NCM/CFOP preenchidos manualmente.");
            }

            if ($item->product && ! $item->product->fiscalData) {
                throw new RuntimeException("Produto \"{$item->product_name}\" não tem dados fiscais cadastrados.");
            }
        }

        // Nota fiscal é uma coisa só (natOp/finNFe únicos pro documento
        // inteiro) — se TODOS os itens forem serviço, descreve a operação
        // como prestação de serviço; havendo qualquer produto (mesmo
        // misturado com serviço), mantém "venda de mercadoria" como já era.
        // Aviso real de negócio, não travado no código: NF-e modelo 55 é
        // formalmente pra mercadoria — prestação de serviço "pura" costuma
        // exigir NFS-e (municipal, sistema totalmente separado, não
        // implementado aqui). Fica a critério de quem emite.
        $allServices = $order->items->every(fn ($item) => $item->item_type === OrderItem::TYPE_SERVICE);

        $make = new Make();

        $cUF = (int) config('nfe.cuf');
        $tpAmb = config('nfe.ambiente') === 'producao' ? 1 : 2;
        $cMunFG = $this->ibge->resolve($company->city, $company->state);

        $infNFe = new stdClass();
        $infNFe->versao = '4.00';
        $make->taginfNFe($infNFe);

        $ide = new stdClass();
        $ide->cUF = $cUF;
        $ide->natOp = $allServices ? 'Prestação de serviço' : 'Venda de mercadoria';
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
        //
        // Segunda rejeição real (pedido #17, 2026-08-03): MEI ainda caía no
        // default (CRT=1), mas a NT 2024.001 tornou obrigatório CRT=4
        // ("Simples Nacional — Microempreendedor Individual — MEI") pra
        // emitentes MEI a partir de abril/2025 — confirmado contra a nota
        // técnica oficial, não é mais opcional/inferido.
        $emit->CRT = match ($company->regime_tributario) {
            Company::REGIME_LUCRO_PRESUMIDO, Company::REGIME_LUCRO_REAL => 3,
            Company::REGIME_MEI => 4,
            default => 1, // simples_nacional
        };
        $emit->CNPJ = preg_replace('/\D/', '', $company->cnpj);
        $emit->CNAE = $company->cnae;
        $make->tagemit($emit);

        $enderEmit = new stdClass();
        $enderEmit->xLgr = $company->street;
        $enderEmit->nro = $company->number;
        // Mesmo limite de 60 caracteres do xCpl do destinatário logo abaixo
        // — rejeição real da SEFAZ (pedidos #15/#16, 2026-08-03) porque o
        // complemento do emitente tinha 73 caracteres.
        $enderEmit->xCpl = Str::limit((string) $company->complement, 60, '');
        $enderEmit->xBairro = $company->neighborhood;
        $enderEmit->cMun = $cMunFG;
        $enderEmit->xMun = $company->city;
        $enderEmit->UF = $company->state;
        $enderEmit->CEP = preg_replace('/\D/', '', (string) $company->zip);
        $enderEmit->cPais = 1058;
        $enderEmit->xPais = 'Brasil';

        // Mesma defesa do enderDest logo abaixo — fone é opcional, só seta
        // quando tem dígito suficiente pro pattern [0-9]{6,14} da NF-e.
        $emitPhone = preg_replace('/\D/', '', (string) $company->phone);
        if (strlen($emitPhone) >= 6) {
            $enderEmit->fone = $emitPhone;
        }

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

        // Achado real 2026-08-06 (pedidos Shopee reais #180/#181): a Shopee
        // mascara o telefone por privacidade ("******97") — depois de tirar
        // os não-dígitos sobra só "97", 2 caracteres, e o schema da NF-e
        // exige 6-14 ([0-9]{6,14} pattern). Rejeição real do validador
        // local antes até de chegar na SEFAZ. fone é opcional no XML —
        // omite em vez de mandar um valor curto demais que nunca vai
        // validar.
        $destPhone = preg_replace('/\D/', '', (string) $order->shipping_phone);
        if (strlen($destPhone) >= 6) {
            $enderDest->fone = $destPhone;
        }

        $make->tagenderDest($enderDest);

        $totalVProd = 0.0;

        // Rejeição real da SEFAZ (535, pedido #180, 2026-08-06): "Total do
        // Frete difere do somatório dos itens" — o total (icmsTot->vFrete
        // logo abaixo) sempre foi só order->shipping_cost, sem NENHUM item
        // declarar frete individual, e a SEFAZ exige que o total bata com a
        // soma do vFrete de cada item quando o total é > 0. Distribuído
        // proporcionalmente ao valor de cada item (não dividido igual entre
        // eles — item mais caro carrega mais frete, mais justo e é o padrão
        // usado por a maioria dos emissores), com o resto do arredondamento
        // absorvido pelo último item pra sempre bater exatamente com o
        // total, nunca sobrar/faltar centavo por causa de round() em cada
        // parcela.
        $totalShipping = (float) $order->shipping_cost;
        $orderSubtotal = (float) $order->subtotal;
        $itemsCount = $order->items->count();
        $allocatedShipping = 0.0;

        foreach ($order->items as $index => $item) {
            $n = $index + 1;
            $fiscal = $this->resolveFiscalData($item);
            $cfop = $order->shipping_state === $company->state ? $fiscal->cfop : $fiscal->cfop_outros_estados;

            if ($n === $itemsCount) {
                $itemShipping = round($totalShipping - $allocatedShipping, 2);
            } else {
                $itemShipping = $orderSubtotal > 0
                    ? round($totalShipping * ((float) $item->subtotal / $orderSubtotal), 2)
                    : 0.0;
            }

            $allocatedShipping += $itemShipping;

            $prod = new stdClass();
            $prod->item = $n;
            // Item digitado na hora não tem SKU de catálogo — "SERV-{id}"
            // como código interno, só precisa ser único/estável, a SEFAZ não
            // valida contra nada externo.
            $prod->cProd = $item->product?->sku ?? "SERV-{$item->id}";
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
            if ($itemShipping > 0) {
                $prod->vFrete = $itemShipping;
            }
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

    /**
     * Unifica as duas fontes possíveis de dados fiscais por item: produto
     * real do catálogo (ProductFiscalData, já validado/estável) ou as
     * colunas manuais do próprio OrderItem (item digitado na emissão manual
     * — produto fora do catálogo ou serviço avulso). Devolve sempre o mesmo
     * formato pra quem chama não precisar saber a origem.
     */
    private function resolveFiscalData(OrderItem $item): stdClass
    {
        if ($item->product) {
            return (object) [
                'ncm' => $item->product->fiscalData->ncm,
                'cest' => $item->product->fiscalData->cest,
                'cfop' => $item->product->fiscalData->cfop,
                'cfop_outros_estados' => $item->product->fiscalData->cfop_outros_estados,
                'origem' => $item->product->fiscalData->origem,
                'gtin' => $item->product->fiscalData->gtin,
                'unidade_tributavel' => $item->product->fiscalData->unidade_tributavel,
                'icms_situacao_tributaria' => $item->product->fiscalData->icms_situacao_tributaria,
                'pis_situacao_tributaria' => $item->product->fiscalData->pis_situacao_tributaria,
                'pis_aliquota' => $item->product->fiscalData->pis_aliquota,
                'cofins_situacao_tributaria' => $item->product->fiscalData->cofins_situacao_tributaria,
                'cofins_aliquota' => $item->product->fiscalData->cofins_aliquota,
                'percentual_aproximado_tributos' => $item->product->fiscalData->percentual_aproximado_tributos,
            ];
        }

        return (object) [
            'ncm' => $item->ncm,
            'cest' => $item->cest,
            'cfop' => $item->cfop,
            'cfop_outros_estados' => $item->cfop_outros_estados ?: $item->cfop,
            'origem' => $item->origem_mercadoria ?? 0,
            'gtin' => $item->gtin,
            'unidade_tributavel' => $item->unidade_tributavel ?: 'UN',
            'icms_situacao_tributaria' => $item->icms_situacao_tributaria,
            'pis_situacao_tributaria' => $item->pis_situacao_tributaria,
            'pis_aliquota' => $item->pis_aliquota,
            'cofins_situacao_tributaria' => $item->cofins_situacao_tributaria,
            'cofins_aliquota' => $item->cofins_aliquota,
            'percentual_aproximado_tributos' => $item->percentual_aproximado_tributos,
        ];
    }
}
