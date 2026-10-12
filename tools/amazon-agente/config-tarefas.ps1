# Agente da Amazon no PC da operação (2026-10-12). Mesmo Chrome e mesma pasta
# do agente do TikTok (C:\tiktok-agente). Rodar na sessão do usuário "user".
$ErrorActionPreference = 'Stop'
$pasta = 'C:\tiktok-agente'
if (-not (Test-Path "$pasta\amazon.mjs")) { throw "copie amazon.mjs e amazon.json para $pasta" }

$usuario = "$env:COMPUTERNAME\$env:USERNAME"
$principal = New-ScheduledTaskPrincipal -UserId $usuario -LogonType Interactive -RunLevel Limited
$opcoes = New-ScheduledTaskSettingsSet -WakeToRun -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

# 23h50: depois do TikTok (23h30), para os dois não disputarem o Chrome.
Register-ScheduledTask -TaskName 'Agente Amazon - 23h50' -Principal $principal -Settings $opcoes -Force `
  -Action (New-ScheduledTaskAction -Execute 'C:\Program Files\nodejs\node.exe' -Argument "`"$pasta\amazon.mjs`" diario" -WorkingDirectory $pasta) `
  -Trigger (New-ScheduledTaskTrigger -Daily -At 23:50) | Out-Null

Get-ScheduledTask -TaskName 'Agente Amazon*' | Select-Object TaskName, State
