# Test Script: Logs dos Connectors Mock
# Conforme TAREFA.md: "Verificar logs dos connectors mock"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "TESTE 3: Logs dos Connectors Mock" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$RESULTS_DIR = "results"
$RESULT_FILE = "$RESULTS_DIR/connectors-test-result.txt"

# Criar diretorio de resultados
if (!(Test-Path $RESULTS_DIR)) {
    New-Item -ItemType Directory -Path $RESULTS_DIR | Out-Null
}

# Iniciar log
$timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
"==========================================`n" | Out-File $RESULT_FILE
"TESTE DE CONNECTORS MOCK - $timestamp`n" | Out-File $RESULT_FILE -Append
"==========================================`n" | Out-File $RESULT_FILE -Append

$testsPassed = 0
$testsFailed = 0

Write-Host ""
Write-Host "Passo 1: Verificando se containers estao rodando..." -ForegroundColor Cyan
Write-Host ""

# Verificar WhatsApp Connector
try {
    $whatsappContainer = docker ps --filter "name=whatsapp-connector" --format "{{.Names}}" 2>&1
    if ($whatsappContainer -match "whatsapp") {
        Write-Host "  [OK] WhatsApp Connector esta rodando" -ForegroundColor Green
        "[OK] WhatsApp Connector ativo`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    } else {
        Write-Host "  [ERRO] WhatsApp Connector nao esta rodando" -ForegroundColor Red
        $testsFailed++
    }
} catch {
    Write-Host "  [ERRO] Erro ao verificar WhatsApp Connector: $_" -ForegroundColor Red
    $testsFailed++
}

# Verificar Instagram Connector
try {
    $instagramContainer = docker ps --filter "name=instagram-connector" --format "{{.Names}}" 2>&1
    if ($instagramContainer -match "instagram") {
        Write-Host "  [OK] Instagram Connector esta rodando" -ForegroundColor Green
        "[OK] Instagram Connector ativo`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    } else {
        Write-Host "  [ERRO] Instagram Connector nao esta rodando" -ForegroundColor Red
        $testsFailed++
    }
} catch {
    Write-Host "  [ERRO] Erro ao verificar Instagram Connector: $_" -ForegroundColor Red
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 2: Testando Health Checks..." -ForegroundColor Cyan
Write-Host ""

# Health check WhatsApp
try {
    $whatsappHealth = Invoke-RestMethod -Uri "http://localhost:9003/health" -Method Get -TimeoutSec 5
    if ($whatsappHealth) {
        Write-Host "  [OK] WhatsApp Connector health check OK" -ForegroundColor Green
        "[OK] WhatsApp health: $($whatsappHealth | ConvertTo-Json -Compress)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Erro ao acessar WhatsApp Health: $_" -ForegroundColor Red
    "[ERRO] WhatsApp health falhou`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

# Health check Instagram
try {
    $instagramHealth = Invoke-RestMethod -Uri "http://localhost:9004/health" -Method Get -TimeoutSec 5
    if ($instagramHealth) {
        Write-Host "  [OK] Instagram Connector health check OK" -ForegroundColor Green
        "[OK] Instagram health: $($instagramHealth | ConvertTo-Json -Compress)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Erro ao acessar Instagram Health: $_" -ForegroundColor Red
    "[ERRO] Instagram health falhou`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 3: Enviando mensagem de teste para WhatsApp Mock..." -ForegroundColor Cyan
Write-Host ""

# Enviar mensagem teste para WhatsApp
try {
    $waBody = @{
        to = "+5511999999999"
        message = "Mensagem de teste do sistema Chat4All"
        type = "text"
    } | ConvertTo-Json
    
    $waResponse = Invoke-RestMethod -Uri "http://localhost:9003/send" -Method Post `
        -Headers @{"Content-Type"="application/json"} -Body $waBody -TimeoutSec 5
    
    Write-Host "  [OK] Mensagem enviada para WhatsApp Mock" -ForegroundColor Green
    "[OK] WhatsApp resposta: $($waResponse | ConvertTo-Json -Compress)`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} catch {
    Write-Host "  [ERRO] Erro ao enviar para WhatsApp: $_" -ForegroundColor Red
    "[ERRO] $($_.Exception.Message)`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 4: Enviando mensagem de teste para Instagram Mock..." -ForegroundColor Cyan
Write-Host ""

# Enviar mensagem teste para Instagram
try {
    $igBody = @{
        to = "test_user"
        message = "Mensagem de teste do sistema Chat4All"
        type = "direct"
    } | ConvertTo-Json
    
    $igResponse = Invoke-RestMethod -Uri "http://localhost:9004/send" -Method Post `
        -Headers @{"Content-Type"="application/json"} -Body $igBody -TimeoutSec 5
    
    Write-Host "  [OK] Mensagem enviada para Instagram Mock" -ForegroundColor Green
    "[OK] Instagram resposta: $($igResponse | ConvertTo-Json -Compress)`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} catch {
    Write-Host "  [ERRO] Erro ao enviar para Instagram: $_" -ForegroundColor Red
    "[ERRO] $($_.Exception.Message)`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 5: Verificando logs do WhatsApp Connector..." -ForegroundColor Cyan
Write-Host ""

# Verificar logs do WhatsApp
try {
    $whatsappLogs = docker logs whatsapp-connector --tail 50 2>&1
    
    if ($whatsappLogs -is [System.Management.Automation.ErrorRecord]) {
        Write-Host "  [ERRO] Falha ao obter logs do WhatsApp" -ForegroundColor Red
        $testsFailed++
    } else {
        $logsText = $whatsappLogs | Out-String
        $logLines = ($logsText -split "`n").Count
        Write-Host "  [OK] WhatsApp Connector tem $logLines linhas de log" -ForegroundColor Green
        
        if ($logsText -match "processing|webhook|message|connected|started") {
            Write-Host "  [OK] Logs mostram atividade de processamento" -ForegroundColor Green
            $testsPassed++
        } else {
            Write-Host "  [AVISO] Logs nao mostram processamento recente" -ForegroundColor Yellow
        }
        
        $lastLogs = ($logsText -split "`n") | Select-Object -Last 10 | Out-String
        "[OK] WhatsApp logs (ultimas 10 linhas):`n$lastLogs`n" | Out-File $RESULT_FILE -Append
    }
} catch {
    Write-Host "  [ERRO] Erro ao verificar logs: $_" -ForegroundColor Red
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 6: Verificando logs do Instagram Connector..." -ForegroundColor Cyan
Write-Host ""

# Verificar logs do Instagram
try {
    $instagramLogs = docker logs instagram-connector --tail 50 2>&1
    
    if ($instagramLogs -is [System.Management.Automation.ErrorRecord]) {
        Write-Host "  [ERRO] Falha ao obter logs do Instagram" -ForegroundColor Red
        $testsFailed++
    } else {
        $logsText = $instagramLogs | Out-String
        $logLines = ($logsText -split "`n").Count
        Write-Host "  [OK] Instagram Connector tem $logLines linhas de log" -ForegroundColor Green
        
        if ($logsText -match "processing|webhook|message|connected|started") {
            Write-Host "  [OK] Logs mostram atividade de processamento" -ForegroundColor Green
            $testsPassed++
        } else {
            Write-Host "  [AVISO] Logs nao mostram processamento recente" -ForegroundColor Yellow
        }
        
        $lastLogs = ($logsText -split "`n") | Select-Object -Last 10 | Out-String
        "[OK] Instagram logs (ultimas 10 linhas):`n$lastLogs`n" | Out-File $RESULT_FILE -Append
    }
} catch {
    Write-Host "  [ERRO] Erro ao verificar logs: $_" -ForegroundColor Red
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 7: Verificando Kafka consumer status..." -ForegroundColor Cyan
Write-Host ""

# Verificar se connectors estão consumindo do Kafka
try {
    $whatsappLogs = docker logs whatsapp-connector --tail 100 2>&1 | Out-String
    if ($whatsappLogs -match "kafka|consumer|subscrib") {
        Write-Host "  [OK] WhatsApp consumer Kafka detectado nos logs" -ForegroundColor Green
        $testsPassed++
    } else {
        Write-Host "  [AVISO] WhatsApp consumer Kafka nao detectado nos logs" -ForegroundColor Yellow
    }
} catch {
    Write-Host "  [AVISO] Nao foi possivel verificar Kafka consumer" -ForegroundColor Yellow
}

try {
    $instagramLogs = docker logs instagram-connector --tail 100 2>&1 | Out-String
    if ($instagramLogs -match "kafka|consumer|subscrib") {
        Write-Host "  [OK] Instagram consumer Kafka detectado nos logs" -ForegroundColor Green
        $testsPassed++
    } else {
        Write-Host "  [AVISO] Instagram consumer Kafka nao detectado nos logs" -ForegroundColor Yellow
    }
} catch {
    Write-Host "  [AVISO] Nao foi possivel verificar Kafka consumer" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "RESUMO DO TESTE DE CONNECTORS" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "[OK] Testes passaram: $testsPassed" -ForegroundColor Green
Write-Host "[ERRO] Testes falharam: $testsFailed" -ForegroundColor Red
Write-Host ""

"`n==========================================`n" | Out-File $RESULT_FILE -Append
"RESUMO:`n" | Out-File $RESULT_FILE -Append
"[OK] Testes passaram: $testsPassed`n" | Out-File $RESULT_FILE -Append
"[ERRO] Testes falharam: $testsFailed`n" | Out-File $RESULT_FILE -Append

Write-Host "Resultado salvo em: $RESULT_FILE" -ForegroundColor Cyan
Write-Host ""

if ($testsFailed -eq 0) {
    Write-Host "[OK] TODOS OS TESTES PASSARAM!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "[ERRO] ALGUNS TESTES FALHARAM!" -ForegroundColor Red
    exit 1
}
