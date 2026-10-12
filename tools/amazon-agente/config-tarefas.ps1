# Agente da Amazon no PC da operação (2026-10-12). Mesmo Chrome e mesma pasta
# do agente do TikTok (C:\tiktok-agente). Rodar na sessão do usuário "user".
#
# Segundo plano: o node roda por "conhost --headless" (sem janela de console) e
# o amazon.mjs abre a aba sem foco e minimiza o Chrome, então a tela do
# KoraSync (separação/etiquetas) fica na frente.
#
#   .\config-tarefas.ps1                     -> cria a tarefa "Agente Amazon - 23h50"
#   .\config-tarefas.ps1 -ChromeMinimizado   -> também faz o Chrome do agente abrir
#                                              minimizado no logon (muda a tarefa do TikTok)
param([switch]$ChromeMinimizado)
$ErrorActionPreference = 'Stop'
$pasta = 'C:\tiktok-agente'
if (-not (Test-Path "$pasta\amazon.mjs")) { throw "copie amazon.mjs e amazon.json para $pasta" }

$usuario = "$env:COMPUTERNAME\$env:USERNAME"
$principal = New-ScheduledTaskPrincipal -UserId $usuario -LogonType Interactive -RunLevel Limited
$opcoes = New-ScheduledTaskSettingsSet -WakeToRun -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
$node = "`"C:\Program Files\nodejs\node.exe`""

# 23h50: depois do TikTok (23h30), para os dois não disputarem o Chrome.
Register-ScheduledTask -TaskName 'Agente Amazon - 23h50' -Principal $principal -Settings $opcoes -Force `
  -Action (New-ScheduledTaskAction -Execute 'C:\Windows\System32\conhost.exe' -Argument "--headless $node `"$pasta\amazon.mjs`" diario" -WorkingDirectory $pasta) `
  -Trigger (New-ScheduledTaskTrigger -Daily -At 23:50) | Out-Null

# Atalho para o Lira trazer o Chrome do agente para a frente e logar na Amazon.
$atalho = (New-Object -ComObject WScript.Shell).CreateShortcut("$env:Public\Desktop\Logar na Amazon (agente).lnk")
$atalho.TargetPath = 'C:\Program Files\nodejs\node.exe'
$atalho.Arguments = "`"$pasta\amazon.mjs`" mostrar"
$atalho.WorkingDirectory = $pasta
$atalho.WindowStyle = 7
$atalho.Save()

if ($ChromeMinimizado) {
  # Os três --disable-* mantêm a página trabalhando com a janela minimizada.
  $chrome = "${env:ProgramFiles}\Google\Chrome\Application\chrome.exe"
  $args = "--remote-debugging-port=9222 --remote-debugging-address=127.0.0.1 --user-data-dir=`"$pasta\perfil-chrome`" --no-first-run --start-minimized --disable-backgrounding-occluded-windows --disable-renderer-backgrounding --disable-background-timer-throttling https://seller-br.tiktok.com/finance/bills?subTab=bills&tab=statements"
  Export-ScheduledTask -TaskName 'Agente TikTok - Chrome' | Out-File "$pasta\backup-tarefa-chrome-$(Get-Date -Format yyyyMMdd-HHmmss).xml"
  Register-ScheduledTask -TaskName 'Agente TikTok - Chrome' -Principal $principal -Force `
    -Action (New-ScheduledTaskAction -Execute $chrome -Argument $args) `
    -Trigger (New-ScheduledTaskTrigger -AtLogOn -User $usuario) | Out-Null
}

Get-ScheduledTask -TaskName 'Agente Amazon*', 'Agente TikTok*' | Select-Object TaskName, State
