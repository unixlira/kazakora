# Instala o agente do TikTok neste Windows (2026-10-11).
# Rodar como Administrador, dentro da pasta C:\tiktok-agente (com agente.mjs e config.json).
$ErrorActionPreference = 'Stop'
$pasta = 'C:\tiktok-agente'
Set-Location $pasta

# 1) Node.js LTS
if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    winget install -e --id OpenJS.NodeJS.LTS --accept-source-agreements --accept-package-agreements
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
}

# 2) Playwright (usa o Chrome já instalado; não baixa navegador)
if (-not (Test-Path "$pasta\package.json")) { npm init -y | Out-Null }
npm pkg set type=module | Out-Null
npm install playwright@1.48.2 --no-audit --no-fund

# 3) Chrome dedicado do agente (perfil próprio, depuração local na porta 9222)
$chrome = "${env:ProgramFiles}\Google\Chrome\Application\chrome.exe"
$perfil = "$pasta\perfil-chrome"
$atalho = (New-Object -ComObject WScript.Shell).CreateShortcut("$env:Public\Desktop\Chrome do Agente TikTok.lnk")
$atalho.TargetPath = $chrome
$atalho.Arguments = "--remote-debugging-port=9222 --remote-debugging-address=127.0.0.1 --user-data-dir=`"$perfil`" https://seller-br.tiktok.com/finance/bills?subTab=bills&tab=statements"
$atalho.Save()

# 4) Chrome do agente abre sozinho quando o Windows liga
$acaoChrome = New-ScheduledTaskAction -Execute $chrome -Argument $atalho.Arguments
Register-ScheduledTask -TaskName 'Agente TikTok - Chrome' -Action $acaoChrome -Trigger (New-ScheduledTaskTrigger -AtLogOn) -Force | Out-Null

# 5) Agente todo dia às 23:30
$acao = New-ScheduledTaskAction -Execute 'node.exe' -Argument "`"$pasta\agente.mjs`" diario" -WorkingDirectory $pasta
$opcoes = New-ScheduledTaskSettingsSet -WakeToRun -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
Register-ScheduledTask -TaskName 'Agente TikTok - 23h30' -Action $acao -Trigger (New-ScheduledTaskTrigger -Daily -At 23:30) -Settings $opcoes -Force | Out-Null

# 6) Não dormir com o PC ligado na tomada
powercfg /change standby-timeout-ac 0
powercfg /change hibernate-timeout-ac 0

Write-Host 'Pronto. Abra "Chrome do Agente TikTok" na Área de Trabalho e faça login na Central do Vendedor uma vez.'
