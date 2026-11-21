# Sequência de carrinho abandonado (KazaKora e FerraKora)

Pedido do Lira em 21/11/2026. Substitui os 8 e-mails antigos
(`loja:carrinho-abandonado`, desligado no `routes/console.php`; o código
ficou). **Sem desconto nenhum**: nenhuma mensagem fala de cupom, desconto,
preço, promoção ou frete.

## Como funciona

Comando `php artisan loja:carrinho-abandonado-sequencia`, de 10 em 10 minutos
(`withoutOverlapping`). Cada rodada:

1. **Importa** para `adm_abandoned_carts` os carrinhos abertos e parados há
   60 minutos ou mais:
   - `lead_checkout` aberto (quem preencheu o checkout e não pagou; é a fonte
     com telefone), KazaKora e FerraKora;
   - `cart_snapshots` com e-mail ou usuário (carrinho do kazakora.com).
   Mais de 30 dias parado não entra. **Um registro por pessoa e loja**: mesmo
   e-mail, telefone ou usuário com dois carrinhos vira um registro só, com o
   carrinho mais recente; a sequência continua de onde estava.
2. **Para** quem deve parar (ver abaixo).
3. **Manda** os disparos que estão na hora, no máximo
   `CARRINHO_SEQUENCIA_LIMITE` (padrão 50) por rodada.

`--simular` faz tudo dentro de uma transação desfeita no fim e só lista o que
mandaria (loja, cliente, disparo, canais, produto e link). Rodar antes de
ligar o agendamento em produção.

## Calendário

Dias contados de `inicio_sequencia_em`: **D+1, D+3, D+5, D+7, D+20 e D+30**,
e para (6 disparos). Só sai **entre 9h e 20h** (São Paulo); fora disso, vai
para as 9h da próxima janela. Entre dois disparos, pelo menos 20 horas.

`inicio_sequencia_em` = hora do abandono, mas nunca antes de "agora menos 1
dia". Assim, o estoque que já estava abandonado quando a sequência foi ligada
(últimos 30 dias) recebe o 1º disparo na primeira rodada.

## Conteúdo

| Disparo | Dia | Assunto |
|---|---|---|
| 1 | D+1 | Lembrete do carrinho (nome, produto, link) |
| 2 | D+3 | Dica de uso |
| 3 | D+5 | Curiosidade |
| 4 | D+7 | Cuidado e manutenção |
| 5 | D+20 | Para quem é |
| 6 | D+30 | O que observar ao escolher |

Do 2º ao 6º, o texto (até 300 caracteres) é escrito pelo Gemini
(`GEMINI_API_KEY`) a partir do nome, categoria e descrição do produto, sem
preço, sem desconto, sem promessa de saúde ou resultado e sem inventar
especificação. Texto que fura a regra, ou Gemini fora do ar, vira um texto de
reserva neutro montado com o nome do produto. O texto que saiu fica no
`historico` do registro.

Produto principal = o item de maior valor do carrinho.

### Link

- KazaKora: post publicado do blog ligado ao produto (tabelas
  `editorial_posts` / `editorial_post_product`), quando o blog existir no
  ar; senão, a página do produto (`https://kazakora.com/produtos/<slug>`).
- FerraKora: sempre a página do produto no site da FerraKora
  (`https://ferrakora.com/produto/<slug>/`), para não levar o cliente à
  outra marca.
- UTM: `utm_source=whatsapp|email`, `utm_medium=carrinho_abandonado`,
  `utm_campaign=d1|d3|d5|d7|d20|d30`.

## Canais

- **WhatsApp**: template aprovado da Meta, um por WABA (KazaKora e FerraKora
  têm os seus). Template vazio no `.env` = o WhatsApp não sai (só o e-mail),
  sem erro. Carrinho só com telefone e sem template fica esperando, sem
  gastar a vez. FerraKora sai só pelo número da FerraKora.
- **E-mail**: `App\Modules\Cart\Mail\SequenciaCarrinho`, com a marca da loja
  do carrinho e o link "Não quero mais receber".

## Quando para

- `comprou`: pedido do mesmo e-mail, telefone ou usuário depois do abandono,
  ou o lead do checkout virou "convertido". Se a pessoa abandonar outro
  carrinho depois de comprar, começa uma sequência nova.
- `pediu_parar`: respondeu só "parar", "sair", "pare", "stop", "cancelar" ou
  "não quero mais" no WhatsApp (webhook), ou clicou no descadastro do e-mail.
  Vale para as duas lojas e não volta.
- `sem_promocoes`: usuário com `recebe_promocoes = false`. Não volta.
- `sem_contato`: nem e-mail nem celular.
- `fim`: mandou os 6, ou passou de 32 dias do início.

## Admin

`/admin/carrinhos-abandonados/sequencia` (permissão `pedidos.view`), só
leitura: loja, cliente, produto, disparos feitos, próximo disparo e situação.

## Variáveis do `.env`

| Variável | Padrão | Para quê |
|---|---|---|
| `WHATSAPP_TEMPLATE_CARRINHO_LEMBRETE` | vazio | template do 1º disparo, KazaKora |
| `WHATSAPP_TEMPLATE_CARRINHO_LEMBRETE_IDIOMA` | `pt_BR` | idioma dele |
| `WHATSAPP_TEMPLATE_CARRINHO_NOVIDADE` | vazio | template do 2º ao 6º, KazaKora |
| `WHATSAPP_TEMPLATE_CARRINHO_NOVIDADE_IDIOMA` | `pt_BR` | idioma dele |
| `WHATSAPP_FERRAKORA_TEMPLATE_CARRINHO_LEMBRETE` | vazio | template do 1º disparo, FerraKora |
| `WHATSAPP_FERRAKORA_TEMPLATE_CARRINHO_LEMBRETE_IDIOMA` | `pt_BR` | idioma dele |
| `WHATSAPP_FERRAKORA_TEMPLATE_CARRINHO_NOVIDADE` | vazio | template do 2º ao 6º, FerraKora |
| `WHATSAPP_FERRAKORA_TEMPLATE_CARRINHO_NOVIDADE_IDIOMA` | `pt_BR` | idioma dele |
| `CARRINHO_SEQUENCIA_LIMITE` | `50` | carrinhos com disparo por rodada |

## Templates para enviar à Meta

Todos: categoria **MARKETING**, idioma **Portuguese (BR) / pt_BR**. O botão é
"Visitar site" com **URL dinâmica**: a base fica fixa no template e o app
manda só o final (`{{1}}` do botão). Os nomes abaixo são sugestão; o que
valer é o que for posto no `.env`.

### 1. KazaKora, lembrete: `carrinho_lembrete_kazakora`

- Cabeçalho: nenhum.
- Corpo:

  ```
  Oi, {{1}}! Vimos que o {{2}} ficou no seu carrinho da KazaKora. Ele continua guardado para você: é só tocar no botão abaixo para voltar e finalizar quando quiser.
  ```

  Exemplos: `{{1}}` = `Maria`, `{{2}}` = `Organizador de Gaveta`.
- Rodapé: `Responda SAIR para não receber mais mensagens.`
- Botão (URL dinâmica): texto `Ver meu carrinho`, URL
  `https://kazakora.com/{{1}}`, exemplo
  `produtos/organizador-de-gaveta?utm_source=whatsapp&utm_medium=carrinho_abandonado&utm_campaign=d1`.

### 2. KazaKora, novidade: `carrinho_novidade_kazakora`

- Cabeçalho: **Imagem** (exemplo: uma foto de produto da loja).
- Corpo:

  ```
  Oi, {{1}}! Separamos uma novidade sobre o {{2}}, que ficou no seu carrinho da KazaKora:

  {{3}}

  Toque no botão abaixo para saber mais.
  ```

  Exemplos: `{{1}}` = `Maria`, `{{2}}` = `Organizador de Gaveta`, `{{3}}` =
  `Separe os itens por tipo antes de guardar: assim fica mais fácil achar tudo na gaveta.`
- Rodapé: `Responda SAIR para não receber mais mensagens.`
- Botão (URL dinâmica): texto `Saber mais`, URL `https://kazakora.com/{{1}}`,
  exemplo
  `produtos/organizador-de-gaveta?utm_source=whatsapp&utm_medium=carrinho_abandonado&utm_campaign=d3`.

### 3. FerraKora, lembrete: `carrinho_lembrete_ferrakora` (na WABA da FerraKora)

- Cabeçalho: nenhum.
- Corpo:

  ```
  Oi, {{1}}! Vimos que o {{2}} ficou no seu carrinho da FerraKora. Ele continua guardado para você: é só tocar no botão abaixo para voltar e finalizar quando quiser.
  ```

  Exemplos: `{{1}}` = `João`, `{{2}}` = `Jogo de Chaves Combinadas`.
- Rodapé: `Responda SAIR para não receber mais mensagens.`
- Botão (URL dinâmica): texto `Ver meu carrinho`, URL
  `https://ferrakora.com/{{1}}`, exemplo
  `produto/jogo-de-chaves-combinadas/?utm_source=whatsapp&utm_medium=carrinho_abandonado&utm_campaign=d1`.

### 4. FerraKora, novidade: `carrinho_novidade_ferrakora` (na WABA da FerraKora)

- Cabeçalho: **Imagem** (exemplo: uma foto de ferramenta da loja).
- Corpo:

  ```
  Oi, {{1}}! Separamos uma novidade sobre o {{2}}, que ficou no seu carrinho da FerraKora:

  {{3}}

  Toque no botão abaixo para saber mais.
  ```

  Exemplos: `{{1}}` = `João`, `{{2}}` = `Jogo de Chaves Combinadas`, `{{3}}` =
  `Depois do uso, limpe as chaves com um pano seco e guarde no estojo: elas ficam organizadas e protegidas.`
- Rodapé: `Responda SAIR para não receber mais mensagens.`
- Botão (URL dinâmica): texto `Saber mais`, URL `https://ferrakora.com/{{1}}`,
  exemplo
  `produto/jogo-de-chaves-combinadas/?utm_source=whatsapp&utm_medium=carrinho_abandonado&utm_campaign=d3`.

Depois de aprovados: pôr os nomes no `.env` do servidor (avisar o Lira),
rodar `php artisan loja:carrinho-abandonado-sequencia --simular` e conferir.

## Como reverter

Comentar a linha `loja:carrinho-abandonado-sequencia` no `routes/console.php`
(e, se quiser a sequência antiga de volta, descomentar a
`loja:carrinho-abandonado`). A tabela `adm_abandoned_carts` pode ficar.
