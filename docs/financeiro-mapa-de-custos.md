# Mapa de custos e lucro — KazaKora

Criado em 10/10/2026. **Uma conta só** para lucro em todo o sistema:
`App\Modules\Marketplace\Support\ContributionMargin::periodo()`. Painel
financeiro, KoraSync e o e-mail "Resultado do dia" usam ela. Tela ou relatório
novo que mostre lucro **tem que usar essa classe**, nunca refazer a conta.

```
lucro líquido = receita
              − custo dos produtos
              − taxas dos canais
              − frete pago pela loja
              − anúncios (consumo)
              − imposto (Simples Nacional)      ← só no e-mail de resultado
```

## Cada linha, de onde vem

| Linha | Fonte | Observação |
|---|---|---|
| Receita | `orders.subtotal` dos pedidos pagos/enviados/entregues, sem notas de devolução | **Frete do comprador fica fora** (Shopee/ML/TikTok: ele paga direto à logística do canal). **Exceção Amazon:** a loja envia pelos próprios Correios e o frete do comprador vem no repasse — é receita. |
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
- **TikTok Ads não é registrado em lugar nenhum** — `ads:sync-spend` só lê Shopee e ML,
  e o extrato importado veio com GMV Max zerado. Se há anúncio pago no TikTok, o lucro
  do TikTok está inflado.
- **Amazon Ads** idem (sem integração).

## Buracos de valor conhecidos (10/10/2026)

- **Comissão da Amazon:** o Bling devolve `taxaComissao = 0` nos pedidos da Amazon
  (ele não recebe a comissão). 36 de 37 pedidos sem taxa → lucro da Amazon inflado
  (~12–15% da venda). Fonte real: relatório de pagamentos da Amazon (Seller Central)
  ou SP-API Finances (conta SP-API desconectada desde 12/08).
- **TikTok:** taxa da maioria dos pedidos desde setembro é **estimada**
  (`orders:estimar-taxas-tiktok`); último extrato real importado vai até 21/09.
  Valor real só com o extrato (Income) do TikTok Seller Center.
- **Itens vendidos sem custo do produto:** 27 (conferir em Financeiro).

## Conferências feitas na migração para a produção (10/10/2026)

Pedidos no banco × fonte, mês a mês: **nenhum faltando** — ML (API, mai–out),
Shopee (API, jul–out; os não pagos ficam de fora de propósito), TikTok e Amazon
(Bling, jun–out).
