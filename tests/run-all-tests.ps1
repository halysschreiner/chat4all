# Script para executar todos os testes integrados
# Conforme especificado em TAREFA.md

Write-Host ""
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host "   CHAT4ALL - TESTES INTEGRADOS (TAREFA.MD)" -ForegroundColor Cyan
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host ""

$RESULTS_DIR = "results"
$REPORT_FILE = "$RESULTS_DIR/test-report.txt"

# Criar diretório de resultados
if (!(Test-Path $RESULTS_DIR)) {
    New-Item -ItemType Directory -Path $RESULTS_DIR | Out-Null
    Write-Host "[OK] Diretorio de resultados criado" -ForegroundColor Green
}

# Limpar resultados anteriores
if (Test-Path $REPORT_FILE) {
    Remove-Item $REPORT_FILE
}

# Iniciar relatório
$timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
$startTime = Get-Date

@"
================================================================
        RELATORIO DE TESTES INTEGRADOS
              Chat4All System
================================================================

Data/Hora: $timestamp

Testes conforme especificado em TAREFA.md:
1. Enviar mensagens e arquivos via API
2. Verificar armazenamento
3. Verificar logs dos connectors mock
4. Testar multiplos usuarios simultaneamente

================================================================

"@ | Out-File $REPORT_FILE

Write-Host "Verificando se todos os servicos estao rodando..." -ForegroundColor Yellow
Write-Host ""

# Verificar serviços essenciais
$services = @("chat4all-gateway", "chat4all-api", "chat4all-postgres", "chat4all-kafka")
$allServicesRunning = $true

foreach ($service in $services) {
    $running = docker ps --filter "name=$service" --format "{{.Names}}" 2>&1
    if ($running -match $service) {
        Write-Host "  [OK] $service" -ForegroundColor Green
    } else {
        Write-Host "  [ERRO] $service NAO esta rodando!" -ForegroundColor Red
        $allServicesRunning = $false
    }
}

if (-not $allServicesRunning) {
    Write-Host ""
    Write-Host "[ERRO] Alguns servicos nao estao rodando!" -ForegroundColor Red
    Write-Host "Execute: docker-compose up -d" -ForegroundColor Yellow
    Write-Host ""
    exit 1
}

Write-Host ""
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host ""

# Array para armazenar resultados
$testResults = @()

# Definir diretório de testes
$testsDir = $PSScriptRoot
if (!$testsDir) { $testsDir = Split-Path -Parent $MyInvocation.MyCommand.Path }

# TESTE 1: Mensagens
Write-Host "[>>] EXECUTANDO TESTE 1/4: Mensagens via API" -ForegroundColor Cyan
Write-Host ""
$test1Start = Get-Date
$test1Result = & "$testsDir\test-messages.ps1"
$test1End = Get-Date
$test1Duration = ($test1End - $test1Start).TotalSeconds
$test1Status = $LASTEXITCODE

$testResults += @{
    name = "Teste 1: Mensagens via API"
    duration = $test1Duration
    passed = ($test1Status -eq 0)
}

Write-Host ""
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host ""

# TESTE 2: Arquivos
Write-Host "[>>] EXECUTANDO TESTE 2/4: Upload de Arquivos" -ForegroundColor Cyan
Write-Host ""
$test2Start = Get-Date
$test2Result = & "$testsDir\test-files.ps1"
$test2End = Get-Date
$test2Duration = ($test2End - $test2Start).TotalSeconds
$test2Status = $LASTEXITCODE

$testResults += @{
    name = "Teste 2: Upload de Arquivos"
    duration = $test2Duration
    passed = ($test2Status -eq 0)
}

Write-Host ""
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host ""

# TESTE 3: Connectors
Write-Host "[>>] EXECUTANDO TESTE 3/4: Logs dos Connectors Mock" -ForegroundColor Cyan
Write-Host ""
$test3Start = Get-Date
$test3Result = & "$testsDir\test-connectors.ps1"
$test3End = Get-Date
$test3Duration = ($test3End - $test3Start).TotalSeconds
$test3Status = $LASTEXITCODE

$testResults += @{
    name = "Teste 3: Logs dos Connectors Mock"
    duration = $test3Duration
    passed = ($test3Status -eq 0)
}

Write-Host ""
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host ""

# TESTE 4: Múltiplos Usuários
Write-Host "[>>] EXECUTANDO TESTE 4/4: Multiplos Usuarios Simultaneos" -ForegroundColor Cyan
Write-Host ""
$test4Start = Get-Date
$test4Result = & "$testsDir\test-multiple-users.ps1"
$test4End = Get-Date
$test4Duration = ($test4End - $test4Start).TotalSeconds
$test4Status = $LASTEXITCODE

$testResults += @{
    name = "Teste 4: Multiplos Usuarios Simultaneos"
    duration = $test4Duration
    passed = ($test4Status -eq 0)
}

Write-Host ""
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host ""

# Calcular estatísticas
$endTime = Get-Date
$totalDuration = ($endTime - $startTime).TotalSeconds
$totalTests = $testResults.Count
$passedTests = ($testResults | Where-Object { $_.passed }).Count
$failedTests = $totalTests - $passedTests

# Gerar relatório consolidado
@"

================================================================
RESULTADOS DOS TESTES
================================================================

"@ | Out-File $REPORT_FILE -Append

foreach ($test in $testResults) {
    $status = if ($test.passed) { "[PASSOU]" } else { "[FALHOU]" }
    $color = if ($test.passed) { "Green" } else { "Red" }
    $line = "$status $($test.name) ($($test.duration.ToString('F2'))s)"
    
    Write-Host $line -ForegroundColor $color
    $line | Out-File $REPORT_FILE -Append
}

@"

================================================================
ESTATISTICAS GERAIS
================================================================

Total de testes:     $totalTests
[OK] Testes passaram:   $passedTests
[ERRO] Testes falharam:   $failedTests
[TEMPO] Tempo total:       $($totalDuration.ToString('F2')) segundos

"@ | Out-File $REPORT_FILE -Append

Write-Host ""
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host "ESTATISTICAS GERAIS" -ForegroundColor Cyan
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host "Total de testes:     $totalTests" -ForegroundColor White
Write-Host "[OK] Testes passaram:   $passedTests" -ForegroundColor Green
Write-Host "[ERRO] Testes falharam:   $failedTests" -ForegroundColor Red
Write-Host "[TEMPO] Tempo total:       $($totalDuration.ToString('F2')) segundos" -ForegroundColor Cyan
Write-Host ""

# Adicionar conteúdo dos resultados individuais ao relatório
@"
================================================================
DETALHES DOS TESTES
================================================================

"@ | Out-File $REPORT_FILE -Append

$resultFiles = @(
    "messages-test-result.txt",
    "files-test-result.txt",
    "connectors-test-result.txt",
    "multiple-users-test-result.txt"
)

foreach ($file in $resultFiles) {
    $filePath = "$RESULTS_DIR\$file"
    if (Test-Path $filePath) {
        "`n--- $file ---`n" | Out-File $REPORT_FILE -Append
        Get-Content $filePath | Out-File $REPORT_FILE -Append
    }
}

@"

================================================================
FIM DO RELATORIO
================================================================
"@ | Out-File $REPORT_FILE -Append

Write-Host "[RELATORIO] Relatorio completo salvo em: $REPORT_FILE" -ForegroundColor Cyan
Write-Host ""

# Status final
if ($failedTests -eq 0) {
    Write-Host "================================================================" -ForegroundColor Green
    Write-Host "  [OK] TODOS OS TESTES PASSARAM COM SUCESSO! [OK]" -ForegroundColor Green
    Write-Host "================================================================" -ForegroundColor Green
    Write-Host ""
    exit 0
} else {
    Write-Host "================================================================" -ForegroundColor Red
    Write-Host "    [ERRO] ALGUNS TESTES FALHARAM - REVISAR [ERRO]" -ForegroundColor Red
    Write-Host "================================================================" -ForegroundColor Red
    Write-Host ""
    Write-Host "Verifique os arquivos de resultado em: $RESULTS_DIR\" -ForegroundColor Yellow
    Write-Host ""
    exit 1
}
