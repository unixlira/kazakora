<#
.SYNOPSIS
    Agente local de impressão do Kazakora — roda numa máquina Windows
    fisicamente na loja, conectada à impressora de etiquetas via USB.

.DESCRIPTION
    Faz polling em GET /api/print-agent/jobs no servidor, reivindica cada
    etiqueta pronta, baixa o PDF e manda pro spooler de impressão do Windows
    via SumatraPDF (impressão silenciosa de linha de comando — mais
    confiável que abrir o PDF num visualizador e simular Ctrl+P, que
    costuma prender numa janela ou pedir confirmação).

    Não precisa de nenhum runtime extra: PowerShell já vem no Windows.
    A única dependência externa é o SumatraPDF (grátis, portátil, ~10MB).

.NOTES
    Configuração antes de rodar (edite os valores abaixo ou passe como
    parâmetros): ApiBaseUrl, ApiToken (o mesmo PRINT_AGENT_TOKEN do .env do
    servidor), PrinterName (nome exato da impressora no Windows — veja em
    Configurações > Impressoras e scanners), SumatraPdfPath.

    Pra rodar continuamente sem depender de alguém deixar uma janela
    aberta, registre como Tarefa Agendada do Windows (Agendador de
    Tarefas > Criar Tarefa Básica > Repetir a cada 1 minuto > Ação:
    iniciar powershell.exe -ExecutionPolicy Bypass -File
    "C:\caminho\kazakora-print-agent.ps1").
#>

param(
    [string]$ApiBaseUrl = "https://kazakora.devlira.com.br/api/print-agent",
    [string]$ApiToken = "COLE_AQUI_O_PRINT_AGENT_TOKEN",
    [string]$PrinterName = "NOME_DA_IMPRESSORA_AQUI",
    [string]$SumatraPdfPath = "C:\Program Files\SumatraPDF\SumatraPDF.exe",
    [string]$AgentId = $env:COMPUTERNAME,
    [string]$TempDir = "$env:TEMP\kazakora-print-agent"
)

$ErrorActionPreference = "Stop"
$headers = @{ Authorization = "Bearer $ApiToken"; Accept = "application/json" }

if (-not (Test-Path $TempDir)) {
    New-Item -ItemType Directory -Path $TempDir | Out-Null
}

if (-not (Test-Path $SumatraPdfPath)) {
    Write-Error "SumatraPDF não encontrado em '$SumatraPdfPath'. Baixe em https://www.sumatrapdfreader.org/download-free-pdf-viewer (versão portátil) e ajuste -SumatraPdfPath."
    exit 1
}

function Complete-Job {
    param([int]$JobId, [string]$Status, [string]$ErrorMessage = $null)

    $body = @{ status = $Status }
    if ($ErrorMessage) { $body.error_message = $ErrorMessage }

    Invoke-RestMethod -Uri "$ApiBaseUrl/jobs/$JobId/complete" -Method Post -Headers $headers -Body ($body | ConvertTo-Json) -ContentType "application/json" | Out-Null
}

try {
    $jobsResponse = Invoke-RestMethod -Uri "$ApiBaseUrl/jobs" -Method Get -Headers $headers
} catch {
    Write-Error "Falha ao consultar a fila de impressão: $_"
    exit 1
}

foreach ($job in $jobsResponse.jobs) {
    $jobId = $job.id
    Write-Host "Processando job #$jobId (pedido #$($job.order_id))..."

    try {
        Invoke-RestMethod -Uri "$ApiBaseUrl/jobs/$jobId/claim" -Method Post -Headers $headers -Body (@{ agent_id = $AgentId } | ConvertTo-Json) -ContentType "application/json" | Out-Null
    } catch {
        # Outro agente pode ter reivindicado primeiro (409) — normal, pula.
        Write-Host "Job #$jobId já foi reivindicado por outro agente, pulando."
        continue
    }

    $labelPath = Join-Path $TempDir "etiqueta-pedido-$($job.order_id).pdf"

    try {
        Invoke-WebRequest -Uri "$ApiBaseUrl/jobs/$jobId/label" -Headers $headers -OutFile $labelPath

        # -print-to-default usaria a impressora padrão do Windows; -print-to
        # especifica o nome exato, pra não depender de qual impressora está
        # marcada como padrão na máquina.
        $process = Start-Process -FilePath $SumatraPdfPath -ArgumentList "-print-to `"$PrinterName`" -silent `"$labelPath`"" -Wait -PassThru -WindowStyle Hidden

        if ($process.ExitCode -ne 0) {
            throw "SumatraPDF saiu com código $($process.ExitCode) — verifique se a impressora '$PrinterName' está ligada e com papel."
        }

        Complete-Job -JobId $jobId -Status "printed"
        Write-Host "Job #$jobId impresso com sucesso."
    } catch {
        $message = $_.Exception.Message
        Write-Error "Falha ao imprimir job #$jobId : $message"
        Complete-Job -JobId $jobId -Status "failed" -ErrorMessage $message
    } finally {
        if (Test-Path $labelPath) { Remove-Item $labelPath -Force -ErrorAction SilentlyContinue }
    }
}

if ($jobsResponse.jobs.Count -eq 0) {
    Write-Host "Nenhuma etiqueta pendente."
}
