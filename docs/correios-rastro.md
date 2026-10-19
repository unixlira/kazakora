# Correios: rastreio (SRO Rastro)

Levantado em 2026-10-19 no swagger oficial (`SRO - Rastro`, versão 3.8.2,
API 87 da plataforma api.correios.com.br) e em consultas reais com o
contrato da KazaKora.

## Acesso

- Base: `https://api.correios.com.br/srorastro` (config
  `services.correios.rastro_base_url`, env `CORREIOS_RASTRO_BASE_URL`).
- Autorização "Contrato", "Exige serviço: Sim". O contrato da KazaKora já tem
  o serviço liberado: consulta em lote de 50 objetos respondeu HTTP 200 em
  19/10/2026.
- Token: o mesmo do Preço/Prazo (`CorreiosTokenService::tokenForPrecoPrazo()`,
  chave genérica `CORREIOS_CODIGO_ACESSO` no `/token/v1/autentica`). O token da
  pré-postagem (cartão de postagem) volta `GTW-012` nesta API.
- Swagger: `GET /srorastro/v3/api-docs` com o token (sem token: `GTW-006`).
- Limite do gateway (cabeçalhos da resposta): `X-RateLimit-Replenish-Rate: 150`,
  `X-RateLimit-Burst-Capacity: 155`, 1 ficha por requisição. Na prática, folga
  enorme: a loja faz 1 chamada por lote de até 50 objetos a cada 5 minutos.

## Endpoints

| Método | Caminho | Uso |
|---|---|---|
| GET | `/v1/objetos?codigosObjetos=A&codigosObjetos=B&resultado=T` | Lote, **máximo 50 objetos** (parâmetro repetido, não `codigosObjetos[]`). Padrão `resultado=U`. |
| GET | `/v1/objetos/{codigo}?resultado=T` | Um objeto. Padrão `resultado=T`. |
| POST | `/v1/objetos/imagens` | Imagens de baixa de uma lista (assíncrono, devolve recibo). |
| GET | `/v1/recibo/{recibo}` | Resultado de um recibo. |
| GET | `/v1/ar-digital?objetos=...` | Imagem do AR digital, até 10 objetos. |

`resultado`: `T` todos os eventos, `U` só o último, `P` só o primeiro.

## Resposta

`{ versao, quantidade, tipoResultado, objetos: [ { codObjeto, tipoPostal{sigla,descricao,categoria}, dtPrevista, contrato, peso, formato, modalidade, valorDeclarado, mensagem?, eventos: [...] } ] }`

- Eventos vêm do **mais recente para o mais antigo**.
- Evento: `codigo`, `tipo`, `dtHrCriado` (horário de Brasília, sem fuso),
  `descricao`, `detalhe`, `unidade{nome,tipo,endereco{cidade,uf}}`,
  `unidadeDestino{endereco{cidade,uf}}` nas transferências, e às vezes
  `latitude/longitude`, `recebedor`, `dtLimiteRetirada` (LDI).
- Objeto não encontrado vem dentro da lista com `mensagem` (ex.:
  `SRO-020: Objeto não encontrado...`) e sem eventos; a chamada continua 200.
- O SRO do nosso contrato não traz o nome de quem recebeu (conferido em
  17/10 e 19/10/2026).

## Códigos de evento usados

| Código/tipo | Significado | O que a loja faz |
|---|---|---|
| `FC/82` (e outros FC) | Etiqueta emitida, aguardando postagem | Ainda não é movimento físico. |
| `PO/01` | Objeto postado | Pedido do site `pago` → `enviado`; data da postagem; WhatsApp "postado". |
| `RO`, `DO` `/01` | Em transferência | Situação "A caminho". |
| `OEC/01` | Saiu para entrega | WhatsApp "saiu para entrega" (enquanto for o último evento). |
| `LDI/01` | Aguardando retirada | WhatsApp "aguardando retirada". |
| `BDE/BDI/BDR` tipo `01` | Entregue (destinatário, porteiro, vizinho...) | Pedido → `concluído`, WhatsApp "pedido entregue" (template aprovado). |
| `BDE/BDI/BDR` outros tipos (`20`, `68`...) | Tentativa sem sucesso, retirada, devolução | Lido pela descrição; não conclui o pedido. |

Devolução ao remetente também aparece como `BDE/01` "entregue ao
destinatário" (caso AP475425146BR): a loja compara a cidade da unidade que
entregou com a cidade do pedido. Diferente = devolvido (não conclui, não avisa
o cliente, chama os admins).

## Como a loja usa

- Comando `loja:acompanhar-rastreio` (agendado de 5 em 5 minutos, fuso
  America/Sao_Paulo, `routes/console.php`):
  - pedidos do site (`origin=loja`, `pago` ou `enviado`, últimos 45 dias) com
    objeto da pré-postagem: consulta em lote, atualiza status, grava histórico
    e avisa o cliente;
  - pedidos de outros canais com objeto dos Correios (Amazon, Mercado Livre):
    só grava o histórico, sem mudar status nem avisar (o canal cuida);
  - objeto entregue ou devolvido fica encerrado e não é mais consultado;
    pacote fora da rota de entrega é reconsultado a cada 30 minutos;
  - opções: `--simular`, `--pedido=ID`, `--dias=45`, `--sem-outros-canais`.
- Histórico: tabelas `rastreios` e `rastreio_eventos` (evento único por objeto,
  código, tipo e data: consultar de novo não duplica). Sem dado pessoal do
  recebedor, destinatário, carteiro ou coordenadas.
- Tela: Logística › Rastreio (`/admin/logistica/rastreio`).
- WhatsApp ao cliente só com `CORREIOS_RASTREIO_WHATSAPP=true` no `.env`
  (desligado por padrão para o homolog não mandar mensagem a cliente real).
  Cada etapa avisa uma vez (`orders.whatsapp_enviado_em`,
  `whatsapp_saiu_entrega_em`, `whatsapp_retirada_em`, `whatsapp_entregue_em`,
  gravados de forma atômica) e só se a etapa é das últimas 72 horas.
  **Produção precisa da variável `true` antes do deploy desta versão**, senão
  os avisos que já saem hoje param.

Código: `app/Services/Correios/CorreiosRastroService.php` (chamada e leitura
das etapas), `app/Modules/Marketplace/Support/RegistroDeRastreio.php`
(histórico e situação), `app/Console/Commands/AcompanharRastreioLoja.php`.
