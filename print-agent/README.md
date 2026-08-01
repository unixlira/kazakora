# Agente de impressão de etiquetas — Kazakora

Programa que roda no computador Windows da loja (o mesmo conectado à
impressora de etiquetas via USB), busca etiquetas prontas no servidor e
manda pra impressora sozinho.

## Instalação

1. **Baixe o SumatraPDF** (grátis, portátil, não precisa instalar):
   https://www.sumatrapdfreader.org/download-free-pdf-viewer — pegue a
   versão "portable" de 64-bit. Salve em `C:\Program Files\SumatraPDF\SumatraPDF.exe`
   (ou outro caminho, ajustando o parâmetro `-SumatraPdfPath` no script).

2. **Descubra o nome exato da impressora**: Configurações do Windows →
   Impressoras e scanners → clique na impressora de etiquetas → copie o
   nome exatamente como aparece.

3. **Copie `kazakora-print-agent.ps1`** pra uma pasta fixa nesse
   computador, ex: `C:\kazakora\kazakora-print-agent.ps1`.

4. **Edite os parâmetros no topo do script** (ou passe na linha de
   comando toda vez):
   - `ApiToken`: o mesmo valor de `PRINT_AGENT_TOKEN` configurado no
     servidor.
   - `PrinterName`: o nome copiado no passo 2.
   - `SumatraPdfPath`: se salvou em outro lugar no passo 1.

5. **Teste manualmente primeiro**, abrindo o PowerShell nessa pasta e
   rodando:
   ```powershell
   .\kazakora-print-agent.ps1
   ```
   Se não houver nenhuma etiqueta pendente ainda, ele só vai avisar
   "Nenhuma etiqueta pendente." — isso já confirma que a conexão com o
   servidor e o token estão certos.

6. **Registre como Tarefa Agendada** pra rodar sozinho sem precisar
   deixar uma janela aberta:
   - Abra o "Agendador de Tarefas" do Windows.
   - Criar Tarefa Básica → nome "Kazakora - Impressão de Etiquetas".
   - Disparador: Diariamente, repetir a cada **1 minuto**, indefinidamente.
   - Ação: Iniciar um programa.
     - Programa: `powershell.exe`
     - Argumentos: `-ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\kazakora\kazakora-print-agent.ps1"`
   - Marque "Executar estando o usuário conectado ou não" se quiser que
     funcione mesmo com a tela bloqueada.

## Como funciona

A cada execução, o agente:
1. Pergunta ao servidor se há etiquetas na fila (`GET /api/print-agent/jobs`).
2. Pra cada uma, avisa que vai processar ela (`POST .../claim`) — isso
   evita que dois agentes tentem imprimir a mesma etiqueta duas vezes.
3. Baixa o PDF da etiqueta.
4. Manda pra impressora via SumatraPDF em modo silencioso (sem abrir
   janela nenhuma).
5. Avisa o servidor se deu certo ou não (`POST .../complete`). Se falhar
   (impressora desligada, sem papel, etc.), o pedido fica marcado no
   admin como "etiqueta pronta mas não impressa" e os administradores
   recebem uma notificação — nada fica silenciosamente perdido.

## Se algo der errado

- **"SumatraPDF não encontrado"**: confira o caminho no passo 1/4.
- **Job sempre falha ao imprimir**: teste imprimir manualmente esse
  mesmo PDF (`SumatraPDF.exe -print-to "Nome da Impressora" -silent
  arquivo.pdf` direto no PowerShell) pra isolar se é a impressora ou o
  script.
- **401 ao consultar a fila**: o `ApiToken` não bate com o
  `PRINT_AGENT_TOKEN` do servidor — confirme os dois valores.
