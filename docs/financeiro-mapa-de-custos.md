# Mapa de custos e lucro — KazaKora

Criado em 10/10/2026. **Uma conta só** para lucro em todo o sistema:
`App\Modules\Marketplace\Support\ContributionMargin::periodo()`. Painel
financeiro, KoraSync e o e-mail "Resultado do dia" (23:50, regra e ordem da
noite em `docs/financeiro-email-resultado-diario.md`) usam ela. Tela ou relatório
novo que mostre lucro **tem que usar essa classe**, nunca refazer a conta.

```
lucro líquido = receita
              − custo dos produtos
              − taxas dos canais
              − frete pago pela loja
              − anúncios (consumo)
              − imposto (Simples Nacional)      ← em todas as telas desde 18/10/2026
```

## Cada linha, de onde vem

| Linha | Fonte | Observação |
|---|---|---|
| Receita | `orders.subtotal` dos pedidos pagos/enviados/entregues, sem notas de devolução | **Frete do comprador fica fora** (Shopee/ML/TikTok: ele paga direto à logística do canal). **Exceção Amazon:** a loja envia pelos próprios Correios e o frete do comprador vem no repasse — é receita. **Site (2026-10-12):** subtotal − `discount_amount` (cupom, Pix e desconto da plataforma do frete embutido são da própria loja); o frete embutido no preço é receita e o custo dos Correios sai na linha de frete. |
| Custo dos produtos | `products.cost_price × quantidade`; item sem produto: `order_items.manual_cost_price` (já é o **total da linha**, não multiplicar) | Produto sem custo cadastrado conta como custo zero → lucro inflado. Conferir em Financeiro. |
| Taxas dos canais | `order_channel_fees` (por pedido) | `source`: `api` (real, ML/Shopee), `report`/`tiktok_income_settlement` (extrato real), **`estimate`** (TikTok antes do extrato — provisão), `manual`. |
| Frete pago pela loja | Correios (`correios_pre_postagens.postage_price`) + Flex (entregas × custo por entrega) | `orders.shipping_cost` **não é custo** — é o frete do comprador (fica só informativo). |
| Anúncios | `channel_ad_spends.spend` (consumo **diário** por canal) | Ver armadilhas abaixo. |
| Imposto | receita × `LOJA_SIMPLES_ALIQUOTA` (4,5%) desde `LOJA_SIMPLES_DESDE` | Estimativa do DAS; o oficial é a guia. Vence dia 20 do mês seguinte. Empresa no **Simples Nacional** desde 01/09/2026 (antes MEI). |

## Armadilhas dos anúncios (ADS)

**Gasto total com ADS ≠ gasto nos anúncios do período.** Três coisas diferentes,
que **nunca** podem ser somadas entre si:

1. **Recarga de carteira** (`ads_recharges`, tela Recargas de Anúncio) — dinheiro
   que saiu da conta e foi para o saldo de anúncios da Shopee/ML. É **movimento de
   caixa**, não custo: o custo é quando o saldo é consumido. **Nunca entra no lucro.**
   Somar recarga + consumo conta o mesmo dinheiro duas vezes.
2. **Consumo diário** (`channel_ad_spends`, `php artisan ads:sync-spend`, todo dia
   06:00, janela de 3 dias porque a Shopee ajusta o dia anterior) — **este é o
   custo** que entra no lucro. Hoje: **Shopee Ads e Mercado Ads**.
3. **Anúncio descontado na liquidação** (TikTok GMV Max: `marketplace_settlement_details.gmv_max_ad_fee`)
   — vem dentro do extrato do canal, junto das taxas. Se um dia o TikTok Ads for
   lançado também em `channel_ad_spends`, o GMV Max **não** pode ser contado de novo.

**Buracos conhecidos (10/10/2026):**
- ~~TikTok Ads não é registrado em lugar nenhum~~ **Resolvido (18/10/2026):** o GMV Max
  vem no extrato como linha própria "Pagamento de GMV para anúncios do TikTok" (sem
  pedido) e entra no ADS do TikTok pela data do demonstrativo
  (`ContributionMargin::adsDoExtrato`). A coluna `gmv_max_ad_fee` do pedido continua
  zerada e não é somada.
- **Amazon Ads** idem (sem integração).

## Valor da nota fiscal = valor que o cliente pagou (regra fixa, 11/10/2026)

Decisão do Lira: **toda NF-e de venda sai pelo valor que o cliente pagou**,
não pelo preço cheio do anúncio. Cupom e subsídio das plataformas (TikTok,
Shopee, Mercado Livre, Amazon — todas dão cupom próprio às vezes) abatem o
valor da nota, e com isso o imposto do Simples.

- No Bling (notas do TikTok/Amazon), o pedido chega com os itens pelo preço
  cheio e as parcelas pelo valor pago. `BlingInvoiceImporter::alinharNotaAoCadastro()`
  reparte o pago entre os itens (proporcional ao preço cheio) antes de enviar;
  sem isso o Bling recusa ("total das parcelas difere do total da nota").
- Pedido de R$ 0 (amostra/brinde) não vira nota de venda.

## Buracos de valor conhecidos (10/10/2026)

- **Comissão da Amazon:** o Bling devolve `taxaComissao = 0` nos pedidos da Amazon
  (ele não recebe a comissão). 36 de 37 pedidos sem taxa → lucro da Amazon inflado
  (~12–15% da venda). Fonte real: relatório de pagamentos da Amazon (Seller Central)
  ou SP-API Finances (conta SP-API desconectada desde 12/08).
  **Resolvido (17/10/2026):** a taxa real vem da SP-API, Finances API 2024-06-19
  (`php artisan amazon:taxas-reais --dias=30`, de hora em hora aos 35 min;
  `--seco` só simula). Grava como o relatório (`source = report`, linha em
  `marketplace_settlement_details` com `source_file = sp-api:finances/2024-06-19`):
  taxa = produto + frete do cliente − líquido. Lançamento diferido e liberado
  contam uma vez; reembolso não muda a taxa (a venda sai pelo `estornado_em`);
  taxa manual não é sobrescrita. A coleta pela tela da Seller Central na Cora Box
  foi desligada. Detalhes em `TaxasAmazonSpApi`.
- **TikTok:** taxa da maioria dos pedidos desde setembro é **estimada**
  (`orders:estimar-taxas-tiktok`); último extrato real importado vai até 21/09.
  Valor real só com o extrato (Income) do TikTok Seller Center.
- **Itens vendidos sem custo do produto:** 27 (conferir em Financeiro).

## Conferências feitas na migração para a produção (10/10/2026)

Pedidos no banco × fonte, mês a mês: **nenhum faltando** — ML (API, mai–out),
Shopee (API, jul–out; os não pagos ficam de fora de propósito), TikTok e Amazon
(Bling, jun–out).

## Auditoria de 2026-10-11: uma conta só

- **Conta oficial:** `ContributionMargin::periodo()` e `porCanal()`. O painel, o KoraSync, o e-mail diário e o Financeiro usam as duas. O Financeiro não faz mais conta própria.
- **Venda efetiva** (`Order::vendaEfetiva()`) é o que conta como receita. Ficam de fora:
  - notas de devolução;
  - nota avulsa, porque até hoje toda avulsa foi nota reemitida de uma venda que já existe no ML ou no TikTok e contava em dobro;
  - pedido estornado por inteiro (`orders.estornado_em`).
- **Mercado Pago:** o comando `mercadopago:conciliar` roda todo dia às 05:40 e cobre os últimos 60 dias. Ele baixa todos os pagamentos da conta e separa:
  - **site:** pelo número da transação em `payments.mercadopago_payment_id`;
  - **Mercado Livre:** pelo `order.type = mercadolibre`;
  - **bônus do Flex:** pelas transferências `bonificaciones_flex`.
- **Taxa real** (`source = report`):
  - ML: receita − líquido recebido, incluindo comissão e frete cobrado do vendedor;
  - site: taxa do Mercado Pago;
  - essa taxa nunca é sobrescrita pela API nem pelo Bling.
- **Bônus do Flex:** entra somando na margem (`bonificacoes`).
- **Imposto (Simples):** a base é o valor da NF-e de venda autorizada. Se o pedido ainda não tem nota importada, usa a receita.

## Validação de 2026-10-18

- **Imposto na conta oficial:** `ContributionMargin::periodo()`/`porCanal()` devolvem
  `imposto` e `lucro` (= `margem` − imposto). O card do painel ("Lucro líquido do mês"),
  o Financeiro (topo, por canal e "Como o lucro é calculado"), o KoraSync
  (`net_profit_today`) e o e-mail das 23h50 mostram o `lucro`. `margem` continua
  existindo (antes do imposto) para Indicadores e alertas.
- **Extrato do TikTok em dobro:** o TikTok dá ID provisório ao demonstrativo
  "Pagamento pendente" e troca pelo definitivo quando paga; como o ID entra na chave
  única, o mesmo pedido era gravado de novo a cada download (116 pedidos de ago a
  out/2026: receita e taxa em dobro, margem do TikTok inflada em R$ 2.035 em agosto,
  R$ 1.153 em setembro e R$ 1.044 em outubro). O importador agora remove a linha
  superada (`ImportadorDemonstrativoTikTok::removerLinhasSuperadas`) e
  `php artisan tiktok:limpar-extrato [--seco]` limpa o que já entrou (cópia em
  `storage/app/private/backups/`) e refaz a taxa real.
- **Taxa estimada do TikTok** (pedido sem extrato) é conservadora: nos pedidos já
  liquidados de ago a out/2026 ela previu de 7% a 25% MENOS de repasse do que o real.
  Não foi mudada; some quando o extrato chega.
- **Notas de devolução** sem estorno no Mercado Pago (12 vendas de ago/set): o
  dinheiro ficou na conta, então a venda continua na receita. O DAS oficial abate
  devoluções; a estimativa daqui não abate (fica um pouco maior que a guia).
- **Reembolso de logística/da plataforma do TikTok** (R$ 560 em set, R$ 291 em out):
  dinheiro que entra por pedido perdido/cancelado; não entra no lucro (conservador).

