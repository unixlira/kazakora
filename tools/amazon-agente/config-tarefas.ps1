# Agente da Amazon no PC da operação (2026-10-12). Mesmo Chrome e mesma pasta
# do agente do TikTok (C:\tiktok-agente). Rodar na sessão do usuário "user".
#
# Segundo plano: o node roda pelo oculto.vbs (sem janela de console) e
# o amazon.mjs abre a aba sem foco e minimiza o Chrome, então a tela do
# KoraSync (separação/etiquetas) fica na frente.
#
#   .\config-tarefas.ps1                     -> cria a tarefa "Agente Financeiro - hora em hora"
#                                              (TikTok + Amazon, financeiro-hora.mjs, todo hh:10)
#   .\config-tarefas.ps1 -ChromeMinimizado   -> também faz o Chrome do agente abrir
#                                              minimizado no logon (muda a tarefa do TikTok)
param([switch]$ChromeMinimizado)
$ErrorActionPreference = 'Stop'
$pasta = 'C:\tiktok-agente'
foreach ($a in 'amazon.mjs', 'amazon.json', 'financeiro-hora.mjs', 'oculto.vbs') {
  if (-not (Test-Path "$pasta\$a")) { throw "copie $a para $pasta" }
}

$usuario = "$env:COMPUTERNAME\$env:USERNAME"
$principal = New-ScheduledTaskPrincipal -UserId $usuario -LogonType Interactive -RunLevel Limited
$node = "`"C:\Program Files\nodejs\node.exe`""

# De hora em hora (2026-10-16), aos 10 min: TikTok e depois Amazon no mesmo
# Chrome (financeiro-hora.mjs). A tarefa "Agente TikTok - 23h30" continua; se
# cair junto, a trava do agente.mjs faz a segunda sair sem fazer nada.
$gatilho = New-ScheduledTaskTrigger -Once -At (Get-Date -Hour 0 -Minute 10 -Second 0) `
  -RepetitionInterval (New-TimeSpan -Hours 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$opcoesHora = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 55)
Register-ScheduledTask -TaskName 'Agente Financeiro - hora em hora' -Principal $principal -Settings $opcoesHora -Force `
  -Action (New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "`"$pasta\oculto.vbs`" $node `"$pasta\financeiro-hora.mjs`"" -WorkingDirectory $pasta) `
  -Trigger $gatilho | Out-Null
Unregister-ScheduledTask -TaskName 'Agente Amazon - 23h50' -Confirm:$false -ErrorAction SilentlyContinue

# Atalho para o Lira trazer o Chrome do agente para a frente e logar na Amazon.
$atalho = (New-Object -ComObject WScript.Shell).CreateShortcut((Join-Path ([Environment]::GetFolderPath('Desktop')) 'Logar na Amazon (agente).lnk'))
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

Get-ScheduledTask -TaskName 'Agente Financeiro*', 'Agente TikTok*' | Select-Object TaskName, State
