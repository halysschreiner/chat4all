# Test Script: Multiplos Usuarios Simultaneamente
# Conforme TAREFA.md: "Testar multiplos usuarios simultaneamente"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "TESTE 4: Multiplos Usuarios Simultaneos" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$API_URL = "http://localhost:8000/v1"
$RESULTS_DIR = "results"
$RESULT_FILE = "$RESULTS_DIR/multiple-users-test-result.txt"

# Criar diretorio de resultados
if (!(Test-Path $RESULTS_DIR)) {
    New-Item -ItemType Directory -Path $RESULTS_DIR | Out-Null
}

# Iniciar log
$timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
"==========================================`n" | Out-File $RESULT_FILE
"TESTE DE MULTIPLOS USUARIOS - $timestamp`n" | Out-File $RESULT_FILE -Append
"==========================================`n" | Out-File $RESULT_FILE -Append

$testsPassed = 0
$testsFailed = 0

Write-Host "Passo 1: Criando 5 usuarios de teste..." -ForegroundColor Cyan
Write-Host ""

$users = @()
$numUsers = 5

for ($i = 1; $i -le $numUsers; $i++) {
    $username = "user$i`_test"
    $email = "user$i`_test@test.com"
    
    # Criar usuario
    $createUser = curl -s -X POST "$API_URL/auth/register" `
        -H 'Content-Type: application/json' `
        -d "{`"username`":`"$username`",`"email`":`"$email`",`"password`":`"senha123`"}" | ConvertFrom-Json
    
    if ($createUser.success) {
        Write-Host "  [OK] Usuario $i criado: $username" -ForegroundColor Green
    } else {
        Write-Host "  [AVISO] Usuario $i ja existe (continuando...)" -ForegroundColor Yellow
    }
    
    # Fazer login
    $login = curl -s -X POST "$API_URL/auth/login" `
        -H 'Content-Type: application/json' `
        -d "{`"email`":`"$email`",`"password`":`"senha123`"}" | ConvertFrom-Json
    
    if ($login.token) {
        $users += @{
            id = $i
            username = $username
            email = $email
            token = $login.token
            user_id = $login.user.user_id
        }
        Write-Host "    Token obtido" -ForegroundColor Gray
    } else {
        Write-Host "  [ERRO] Falha ao obter token do usuario $i" -ForegroundColor Red
        $testsFailed++
        continue
    }
}

if ($users.Count -eq $numUsers) {
    Write-Host "  [OK] Todos os $numUsers usuarios autenticados" -ForegroundColor Green
    "[OK] $numUsers usuarios criados e autenticados`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Apenas $($users.Count) de $numUsers usuarios prontos" -ForegroundColor Red
    "[ERRO] Apenas $($users.Count) usuarios prontos`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 2: Criando grupo com todos os usuarios..." -ForegroundColor Cyan
Write-Host ""

# User 1 cria o grupo
$memberIds = ($users | Select-Object -Skip 1 | ForEach-Object { $_.user_id }) -join '","'
$createGroup = curl -s -X POST "$API_URL/conversations/group" `
    -H "Authorization: Bearer $($users[0].token)" `
    -H 'Content-Type: application/json' `
    -d "{`"group_name`":`"Teste Multiplos Usuarios`",`"member_user_ids`":[`"$memberIds`"]}" | ConvertFrom-Json

if ($createGroup.success) {
    $GROUP_ID = $createGroup.conversation.conversation_id
    Write-Host "  [OK] Grupo criado: $GROUP_ID" -ForegroundColor Green
    "[OK] Grupo criado: $GROUP_ID`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha ao criar grupo" -ForegroundColor Red
    "[ERRO] Falha ao criar grupo`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 3: Enviando mensagens simultaneas..." -ForegroundColor Cyan
Write-Host ""

# Criar jobs para envio paralelo
$jobs = @()
$startTime = Get-Date

foreach ($user in $users) {
    $job = Start-Job -ScriptBlock {
        param($apiUrl, $token, $groupId, $username)
        
        $message = "Mensagem de $username - " + (Get-Date -Format "HH:mm:ss.fff")
        
        $response = curl -s -X POST "$apiUrl/messages" `
            -H "Authorization: Bearer $token" `
            -H 'Content-Type: application/json' `
            -d "{`"conversation_id`":`"$groupId`",`"content`":`"$message`"}"
        
        return $response | ConvertFrom-Json
    } -ArgumentList $API_URL, $user.token, $GROUP_ID, $user.username
    
    $jobs += @{
        job = $job
        user = $user
    }
}

Write-Host "  [TEMPO] Aguardando envio paralelo de $($jobs.Count) mensagens..." -ForegroundColor Yellow

# Aguardar todos os jobs
$results = @()
foreach ($jobInfo in $jobs) {
    $result = Receive-Job -Job $jobInfo.job -Wait
    $results += @{
        user = $jobInfo.user
        result = $result
    }
    Remove-Job -Job $jobInfo.job
}

$endTime = Get-Date
$duration = ($endTime - $startTime).TotalSeconds

Write-Host ""
Write-Host "  [TEMPO] Tempo total: $duration segundos" -ForegroundColor Cyan

# Verificar resultados
$successCount = 0
$failCount = 0

foreach ($r in $results) {
    if ($r.result.success) {
        $successCount++
        Write-Host "  [OK] $($r.user.username): Mensagem enviada" -ForegroundColor Green
    } else {
        $failCount++
        Write-Host "  [ERRO] $($r.user.username): Falha no envio" -ForegroundColor Red
    }
}

"`nEnvio paralelo de mensagens:`n" | Out-File $RESULT_FILE -Append
"[OK] Sucesso: $successCount mensagens`n" | Out-File $RESULT_FILE -Append
"[ERRO] Falha: $failCount mensagens`n" | Out-File $RESULT_FILE -Append
"[TEMPO] Tempo total: $duration segundos`n" | Out-File $RESULT_FILE -Append

if ($successCount -eq $numUsers) {
    $testsPassed++
} else {
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 4: Verificando todas as mensagens no banco..." -ForegroundColor Cyan
Write-Host ""

Start-Sleep -Seconds 2

$dbCheck = docker exec chat4all-postgres psql -U chat4all_user -d chat4all -t -c "SELECT COUNT(*) FROM messages WHERE conversation_id = '$GROUP_ID';"

if ($dbCheck) {
    $msgCount = $dbCheck.Trim()
    Write-Host "  [OK] $msgCount mensagens armazenadas no PostgreSQL" -ForegroundColor Green
    "[OK] Verificacao DB: $msgCount mensagens no grupo`n" | Out-File $RESULT_FILE -Append
    
    if ([int]$msgCount -ge $numUsers) {
        $testsPassed++
    } else {
        Write-Host "  [AVISO] Esperado $numUsers mensagens, encontrado: $msgCount" -ForegroundColor Yellow
        $testsFailed++
    }
}

Write-Host ""
Write-Host "Passo 5: Testando concorrencia - Envios rapidos sequenciais..." -ForegroundColor Cyan
Write-Host ""

$rapidMessages = 10
$rapidStart = Get-Date

for ($i = 1; $i -le $rapidMessages; $i++) {
    $userIndex = $i % $users.Count
    $user = $users[$userIndex]
    
    $msg = curl -s -X POST "$API_URL/messages" `
        -H "Authorization: Bearer $($user.token)" `
        -H 'Content-Type: application/json' `
        -d "{`"conversation_id`":`"$GROUP_ID`",`"content`":`"Mensagem rapida #$i de $($user.username)`"}" | ConvertFrom-Json
    
    if ($msg.success) {
        Write-Host "  [OK] Mensagem rapida $i enviada" -ForegroundColor Green
    } else {
        Write-Host "  [ERRO] Falha na mensagem rapida $i" -ForegroundColor Red
    }
}

$rapidEnd = Get-Date
$rapidDuration = ($rapidEnd - $rapidStart).TotalSeconds
$messagesPerSecond = [math]::Round($rapidMessages / $rapidDuration, 2)

Write-Host ""
Write-Host "  [TEMPO] $rapidMessages mensagens em $rapidDuration segundos" -ForegroundColor Cyan
Write-Host "  [>>] Taxa: $messagesPerSecond mensagens/segundo" -ForegroundColor Cyan

"`nTeste de concorrencia (mensagens rapidas):`n" | Out-File $RESULT_FILE -Append
"Total: $rapidMessages mensagens`n" | Out-File $RESULT_FILE -Append
"Tempo: $rapidDuration segundos`n" | Out-File $RESULT_FILE -Append
"Taxa: $messagesPerSecond msg/s`n" | Out-File $RESULT_FILE -Append

$testsPassed++

Write-Host ""
Write-Host "Passo 6: Verificando race conditions..." -ForegroundColor Cyan
Write-Host ""

# Verificar se ha mensagens duplicadas ou perdidas
$raceCheck = docker exec chat4all-postgres psql -U chat4all_user -d chat4all -t -c "SELECT message_id, COUNT(*) as count FROM messages WHERE conversation_id = '$GROUP_ID' GROUP BY message_id HAVING COUNT(*) > 1;"

if ($raceCheck -match "\w") {
    Write-Host "  [ERRO] Detectadas mensagens duplicadas (race condition)" -ForegroundColor Red
    "[ERRO] Race condition detectada: mensagens duplicadas`n$raceCheck`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
} else {
    Write-Host "  [OK] Nenhuma race condition detectada" -ForegroundColor Green
    "[OK] Nenhuma race condition detectada`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
}

Write-Host ""
Write-Host "Passo 7: Verificando logs de auditoria..." -ForegroundColor Cyan
Write-Host ""

$auditCheck = docker exec chat4all-postgres psql -U chat4all_user -d chat4all -t -c "SELECT COUNT(*) FROM audit_logs WHERE event_type = 'message.sent';"

if ($auditCheck) {
    $auditCount = $auditCheck.Trim()
    Write-Host "  [OK] $auditCount registros de auditoria encontrados" -ForegroundColor Green
    "[OK] Auditoria: $auditCount registros de message.sent`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "RESUMO DO TESTE DE MULTIPLOS USUARIOS" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "[>>] Usuarios testados: $numUsers" -ForegroundColor Cyan
Write-Host "[>>] Mensagens enviadas: $($numUsers + $rapidMessages)" -ForegroundColor Cyan
Write-Host "[>>] Taxa maxima: $messagesPerSecond msg/s" -ForegroundColor Cyan
Write-Host ""
Write-Host "[OK] Testes passaram: $testsPassed" -ForegroundColor Green
Write-Host "[ERRO] Testes falharam: $testsFailed" -ForegroundColor Red
Write-Host ""

"`n==========================================`n" | Out-File $RESULT_FILE -Append
"RESUMO:`n" | Out-File $RESULT_FILE -Append
"[>>] Usuarios: $numUsers`n" | Out-File $RESULT_FILE -Append
"[>>] Mensagens: $($numUsers + $rapidMessages)`n" | Out-File $RESULT_FILE -Append
"[>>] Taxa: $messagesPerSecond msg/s`n" | Out-File $RESULT_FILE -Append
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
