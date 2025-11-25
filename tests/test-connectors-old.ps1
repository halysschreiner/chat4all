# Test Script: Verificacao dos Connectors Mock
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

Write-Host "Passo 1: Verificando se containers estao rodando..." -ForegroundColor Cyan
Write-Host ""

# Verificar WhatsApp Connector
$whatsappRunning = docker ps --filter "name=connector-whatsapp" --format "{{.Names}}" 2>&1

if ($whatsappRunning -match "connector-whatsapp") {
    Write-Host "  [OK] WhatsApp Connector esta rodando" -ForegroundColor Green
    "[OK] WhatsApp Connector container ativo`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] WhatsApp Connector NAO esta rodando" -ForegroundColor Red
    "[ERRO] WhatsApp Connector NAO encontrado`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

# Verificar Instagram Connector
$instagramRunning = docker ps --filter "name=connector-instagram" --format "{{.Names}}" 2>&1

if ($instagramRunning -match "connector-instagram") {
    Write-Host "  [OK] Instagram Connector esta rodando" -ForegroundColor Green
    "[OK] Instagram Connector container ativo`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Instagram Connector NAO esta rodando" -ForegroundColor Red
    "[ERRO] Instagram Connector NAO encontrado`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 2: Testando Health Checks..." -ForegroundColor Cyan
Write-Host ""

# Health check WhatsApp
try {
    $whatsappHealth = curl -s http://localhost:8081/health | ConvertFrom-Json
    if ($whatsappHealth.status -eq "healthy") {
        Write-Host "  [OK] WhatsApp Connector respondendo: $($whatsappHealth.service)" -ForegroundColor Green
        "[OK] WhatsApp Health OK: $($whatsappHealth.service)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    } else {
        Write-Host "  [ERRO] WhatsApp Connector nao esta healthy" -ForegroundColor Red
        "[ERRO] WhatsApp Health FAIL`n" | Out-File $RESULT_FILE -Append
        $testsFailed++
    }
} catch {
    Write-Host "  [ERRO] Erro ao acessar WhatsApp Health: $_" -ForegroundColor Red
    "[ERRO] WhatsApp Health inacessivel`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

# Health check Instagram
try {
    $instagramHealth = curl -s http://localhost:8082/health | ConvertFrom-Json
    if ($instagramHealth.status -eq "healthy") {
        Write-Host "  [OK] Instagram Connector respondendo: $($instagramHealth.service)" -ForegroundColor Green
        "[OK] Instagram Health OK: $($instagramHealth.service)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    } else {
        Write-Host "  [ERRO] Instagram Connector nao esta healthy" -ForegroundColor Red
        "[ERRO] Instagram Health FAIL`n" | Out-File $RESULT_FILE -Append
        $testsFailed++
    }
} catch {
    Write-Host "  [ERRO] Erro ao acessar Instagram Health: $_" -ForegroundColor Red
    "[ERRO] Instagram Health inacessivel`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 3: Enviando mensagem de teste para WhatsApp Mock..." -ForegroundColor Cyan
Write-Host ""

$whatsappPayload = @{
    to = "+5511999999999"
    message = "Teste de mensagem para WhatsApp Mock"
    timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
} | ConvertTo-Json

try {
    $whatsappSend = curl -s -X POST http://localhost:8081/send `
        -H "Content-Type: application/json" `
        -d $whatsappPayload | ConvertFrom-Json
    
    if ($whatsappSend.success) {
        Write-Host "  [OK] Mensagem aceita pelo WhatsApp Mock" -ForegroundColor Green
        Write-Host "    Message ID: $($whatsappSend.message_id)" -ForegroundColor Gray
        "[OK] WhatsApp Mock aceitou mensagem: $($whatsappSend.message_id)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    } else {
        Write-Host "  [ERRO] WhatsApp Mock rejeitou mensagem" -ForegroundColor Red
        "[ERRO] WhatsApp Mock rejeitou mensagem`n" | Out-File $RESULT_FILE -Append
        $testsFailed++
    }
} catch {
    Write-Host "  [ERRO] Erro ao enviar para WhatsApp: $_" -ForegroundColor Red
    "[ERRO] Erro ao enviar para WhatsApp`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 4: Enviando mensagem de teste para Instagram Mock..." -ForegroundColor Cyan
Write-Host ""

$instagramPayload = @{
    to = "user_instagram_123"
    message = "Teste de mensagem para Instagram Mock"
    timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
} | ConvertTo-Json

try {
    $instagramSend = curl -s -X POST http://localhost:8082/send `
        -H "Content-Type: application/json" `
        -d $instagramPayload | ConvertFrom-Json
    
    if ($instagramSend.success) {
        Write-Host "  [OK] Mensagem aceita pelo Instagram Mock" -ForegroundColor Green
        Write-Host "    Message ID: $($instagramSend.message_id)" -ForegroundColor Gray
        "[OK] Instagram Mock aceitou mensagem: $($instagramSend.message_id)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    } else {
        Write-Host "  [ERRO] Instagram Mock rejeitou mensagem" -ForegroundColor Red
        "[ERRO] Instagram Mock rejeitou mensagem`n" | Out-File $RESULT_FILE -Append
        $testsFailed++
    }
} catch {
    Write-Host "  [ERRO] Erro ao enviar para Instagram: $_" -ForegroundColor Red
    "[ERRO] Erro ao enviar para Instagram`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 5: Verificando logs do WhatsApp Connector..." -ForegroundColor Cyan
Write-Host ""

$whatsappLogs = docker logs connector-whatsapp --tail 50 2>&1

if ($whatsappLogs) {
    # Contar linhas de log
    $logLines = $whatsappLogs.Split("`n").Count
    Write-Host "  [OK] WhatsApp Connector tem $logLines linhas de log" -ForegroundColor Green
    
    # Verificar se tem logs de processamento
    if ($whatsappLogs -match "Message sent|Processing message|Kafka") {
        Write-Host "  [OK] Logs indicam processamento ativo" -ForegroundColor Green
        "[OK] WhatsApp Logs OK ($logLines linhas, processamento ativo)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    } else {
        Write-Host "  [AVISO] Logs nao mostram processamento recente" -ForegroundColor Yellow
        "[AVISO] WhatsApp Logs sem processamento recente`n" | Out-File $RESULT_FILE -Append
    }
    
    # Salvar amostra dos logs
    "Amostra dos logs do WhatsApp (ultimas 10 linhas):`n" | Out-File $RESULT_FILE -Append
    ($whatsappLogs.Split("`n") | Select-Object -Last 10) -join "`n" | Out-File $RESULT_FILE -Append
    "`n" | Out-File $RESULT_FILE -Append
} else {
    Write-Host "  [ERRO] Sem logs do WhatsApp Connector" -ForegroundColor Red
    "[ERRO] WhatsApp sem logs`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 6: Verificando logs do Instagram Connector..." -ForegroundColor Cyan
Write-Host ""

$instagramLogs = docker logs connector-instagram --tail 50 2>&1

if ($instagramLogs) {
    # Contar linhas de log
    $logLines = $instagramLogs.Split("`n").Count
    Write-Host "  [OK] Instagram Connector tem $logLines linhas de log" -ForegroundColor Green
    
    # Verificar se tem logs de processamento
    if ($instagramLogs -match "Message sent|Processing message|Kafka") {
        Write-Host "  [OK] Logs indicam processamento ativo" -ForegroundColor Green
        "[OK] Instagram Logs OK ($logLines linhas, processamento ativo)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    } else {
        Write-Host "  [AVISO] Logs nao mostram processamento recente" -ForegroundColor Yellow
        "[AVISO] Instagram Logs sem processamento recente`n" | Out-File $RESULT_FILE -Append
    }
    
    # Salvar amostra dos logs
    "Amostra dos logs do Instagram (ultimas 10 linhas):`n" | Out-File $RESULT_FILE -Append
    ($instagramLogs.Split("`n") | Select-Object -Last 10) -join "`n" | Out-File $RESULT_FILE -Append
    "`n" | Out-File $RESULT_FILE -Append
} else {
    Write-Host "  [ERRO] Sem logs do Instagram Connector" -ForegroundColor Red
    "[ERRO] Instagram sem logs`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 7: Verificando Kafka consumer status..." -ForegroundColor Cyan
Write-Host ""

# Verificar se consumers estao ativos
$whatsappKafka = docker logs connector-whatsapp --tail 100 2>&1 | Select-String "Kafka|consumer|Subscribed"
$instagramKafka = docker logs connector-instagram --tail 100 2>&1 | Select-String "Kafka|consumer|Subscribed"

if ($whatsappKafka) {
    Write-Host "  [OK] WhatsApp consumer Kafka ativo" -ForegroundColor Green
    "[OK] WhatsApp Kafka consumer ativo`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [AVISO] WhatsApp consumer Kafka nao detectado nos logs" -ForegroundColor Yellow
    "[AVISO] WhatsApp Kafka consumer nao detectado`n" | Out-File $RESULT_FILE -Append
}

if ($instagramKafka) {
    Write-Host "  [OK] Instagram consumer Kafka ativo" -ForegroundColor Green
    "[OK] Instagram Kafka consumer ativo`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [AVISO] Instagram consumer Kafka nao detectado nos logs" -ForegroundColor Yellow
    "[AVISO] Instagram Kafka consumer nao detectado`n" | Out-File $RESULT_FILE -Append
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
"==========================================`n" | Out-File $RESULT_FILE -Append

Write-Host "Resultado salvo em: $RESULT_FILE" -ForegroundColor Cyan
Write-Host ""

if ($testsFailed -eq 0) {
    Write-Host "[OK] TODOS OS TESTES PASSARAM!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "[ERRO] ALGUNS TESTES FALHARAM!" -ForegroundColor Red
    exit 1
}
