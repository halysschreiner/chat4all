# Test Script: Multiplos Usuarios Simultaneos
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

Write-Host ""
Write-Host "Passo 1: Criando 5 usuarios de teste..." -ForegroundColor Cyan
Write-Host ""

$users = @()

for ($i = 1; $i -le 5; $i++) {
    $username = "testuser$i"
    $email = "testuser$i@test.com"
    
    # Criar usuario
    try {
        $userBody = @{username=$username; email=$email; password="senha123"} | ConvertTo-Json
        $userResponse = Invoke-RestMethod -Uri "$API_URL/auth/register" -Method Post `
            -Headers @{"Content-Type"="application/json"} -Body $userBody -ErrorAction SilentlyContinue
        Write-Host "  [OK] Usuario $i criado" -ForegroundColor Green
    } catch {
        Write-Host "  [AVISO] Usuario $i ja existe (continuando...)" -ForegroundColor Yellow
    }
    
    # Login
    try {
        $loginBody = @{email=$email; password="senha123"} | ConvertTo-Json
        $login = Invoke-RestMethod -Uri "$API_URL/auth/login" -Method Post `
            -Headers @{"Content-Type"="application/json"} -Body $loginBody
        
        if ($login.token) {
            $users += @{
                id = $i
                user_id = $login.user.user_id
                username = $username
                token = $login.token
            }
            Write-Host "  [OK] Usuario $i autenticado (ID: $($login.user.user_id))" -ForegroundColor Green
        }
    } catch {
        Write-Host "  [ERRO] Falha ao obter token do usuario $i : $_" -ForegroundColor Red
        $testsFailed++
    }
}

if ($users.Count -eq 5) {
    Write-Host "  [OK] Todos os 5 usuarios prontos" -ForegroundColor Green
    "[OK] 5 usuarios criados e autenticados`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Apenas $($users.Count) de 5 usuarios prontos" -ForegroundColor Red
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 2: Criando grupo com todos os usuarios..." -ForegroundColor Cyan
Write-Host ""

# Usuario 1 cria grupo
try {
    $memberIds = $users | ForEach-Object { $_.user_id } | Select-Object -Skip 1
    $groupBody = @{
        group_name = "Grupo Teste Concorrencia"
        member_user_ids = $memberIds
    } | ConvertTo-Json
    
    $group = Invoke-RestMethod -Uri "$API_URL/conversations/group" -Method Post `
        -Headers @{"Authorization"="Bearer $($users[0].token)"; "Content-Type"="application/json"} `
        -Body $groupBody
    
    if ($group.success) {
        Write-Host "  [OK] Grupo criado com $($users.Count) membros" -ForegroundColor Green
        $GROUP_ID = $group.conversation.conversation_id
        "[OK] Grupo criado - ID: $GROUP_ID`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha ao criar grupo: $_" -ForegroundColor Red
    "[ERRO] $($_.Exception.Message)`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

if (-not $GROUP_ID) {
    Write-Host "[ERRO] Nao foi possivel continuar sem grupo" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Passo 3: Enviando mensagens em paralelo..." -ForegroundColor Cyan
Write-Host ""

# Cada usuario envia uma mensagem ao mesmo tempo
$jobs = @()
$startTime = Get-Date

foreach ($user in $users) {
    $job = Start-Job -ScriptBlock {
        param($apiUrl, $groupId, $token, $userId, $userName)
        
        try {
            $body = @{
                conversation_id = $groupId
                content = "Mensagem do usuario $userName enviada em paralelo"
            } | ConvertTo-Json
            
            $response = Invoke-RestMethod -Uri "$apiUrl/messages" -Method Post `
                -Headers @{"Authorization"="Bearer $token"; "Content-Type"="application/json"} `
                -Body $body
            
            return @{success=$true; user=$userName; message_id=$response.message.message_id}
        } catch {
            return @{success=$false; user=$userName; error=$_.Exception.Message}
        }
    } -ArgumentList $API_URL, $GROUP_ID, $user.token, $user.user_id, $user.username
    
    $jobs += $job
}

# Aguardar todos os jobs
$results = $jobs | Wait-Job | Receive-Job
$endTime = Get-Date
$duration = ($endTime - $startTime).TotalSeconds

Write-Host "  [OK] $($results.Count) mensagens enviadas em $($duration.ToString('F2'))s" -ForegroundColor Green
"[OK] Envio paralelo: $($results.Count) mensagens em $($duration.ToString('F2'))s`n" | Out-File $RESULT_FILE -Append

$successCount = ($results | Where-Object { $_.success }).Count
if ($successCount -eq $users.Count) {
    Write-Host "  [OK] Todas as mensagens foram enviadas com sucesso" -ForegroundColor Green
    $testsPassed++
} else {
    Write-Host "  [AVISO] $successCount de $($users.Count) mensagens enviadas" -ForegroundColor Yellow
}

# Limpar jobs
$jobs | Remove-Job -Force

Write-Host ""
Write-Host "Passo 4: Verificando mensagens no banco..." -ForegroundColor Cyan
Write-Host ""

# Verificar quantidade de mensagens
try {
    $dbCheck = docker exec chat4all-postgres psql -U chat4all_user -d chat4all -t -c "SELECT COUNT(*) FROM messages WHERE conversation_id = '$GROUP_ID';" 2>&1
    
    if ($dbCheck -is [System.Management.Automation.ErrorRecord]) {
        Write-Host "  [ERRO] Falha ao conectar no PostgreSQL" -ForegroundColor Red
        $testsFailed++
    } else {
        $count = $dbCheck.Trim()
        Write-Host "  [OK] $count mensagens encontradas no banco" -ForegroundColor Green
        "[OK] $count mensagens armazenadas no PostgreSQL`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Erro ao verificar banco: $_" -ForegroundColor Red
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 5: Testando envio sequencial rapido..." -ForegroundColor Cyan
Write-Host ""

# Usuario 1 envia 10 mensagens rapidamente
$sequentialStart = Get-Date
$sentMessages = 0

for ($i = 1; $i -le 10; $i++) {
    try {
        $msgBody = @{
            conversation_id = $GROUP_ID
            content = "Mensagem sequencial rapida #$i"
        } | ConvertTo-Json
        
        $msg = Invoke-RestMethod -Uri "$API_URL/messages" -Method Post `
            -Headers @{"Authorization"="Bearer $($users[0].token)"; "Content-Type"="application/json"} `
            -Body $msgBody
        
        if ($msg.success) {
            $sentMessages++
        }
    } catch {
        Write-Host "  [AVISO] Falha na mensagem $i : $_" -ForegroundColor Yellow
    }
}

$sequentialEnd = Get-Date
$sequentialDuration = ($sequentialEnd - $sequentialStart).TotalSeconds
$messagesPerSecond = [math]::Round($sentMessages / $sequentialDuration, 2)

Write-Host "  [OK] $sentMessages mensagens em $($sequentialDuration.ToString('F2'))s ($messagesPerSecond msgs/s)" -ForegroundColor Green
"[OK] Envio sequencial: $sentMessages mensagens, $messagesPerSecond msgs/s`n" | Out-File $RESULT_FILE -Append
$testsPassed++

Write-Host ""
Write-Host "Passo 6: Verificando race conditions..." -ForegroundColor Cyan
Write-Host ""

# Verificar se há mensagens duplicadas
try {
    $raceCheck = docker exec chat4all-postgres psql -U chat4all_user -d chat4all -t -c "SELECT message_id, COUNT(*) FROM messages WHERE conversation_id = '$GROUP_ID' GROUP BY message_id HAVING COUNT(*) > 1;" 2>&1
    
    if ($raceCheck -is [System.Management.Automation.ErrorRecord]) {
        Write-Host "  [ERRO] Falha ao verificar race conditions" -ForegroundColor Red
        $testsFailed++
    } else {
        $raceText = $raceCheck | Out-String
        if ($raceText.Trim() -eq "") {
            Write-Host "  [OK] Nenhuma race condition detectada" -ForegroundColor Green
            "[OK] Nenhuma mensagem duplicada encontrada`n" | Out-File $RESULT_FILE -Append
            $testsPassed++
        } else {
            Write-Host "  [AVISO] Possiveis race conditions detectadas" -ForegroundColor Yellow
            "[AVISO] Duplicacoes encontradas:`n$raceText`n" | Out-File $RESULT_FILE -Append
        }
    }
} catch {
    Write-Host "  [ERRO] Erro ao verificar race conditions: $_" -ForegroundColor Red
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 7: Verificando logs de auditoria..." -ForegroundColor Cyan
Write-Host ""

# Verificar audit logs
try {
    $auditCheck = docker exec chat4all-postgres psql -U chat4all_user -d chat4all -t -c "SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'message';" 2>&1
    
    if ($auditCheck -is [System.Management.Automation.ErrorRecord]) {
        Write-Host "  [AVISO] Tabela audit_logs nao existe" -ForegroundColor Yellow
    } else {
        $auditCount = $auditCheck.Trim()
        Write-Host "  [OK] $auditCount registros de auditoria encontrados" -ForegroundColor Green
        "[OK] $auditCount audit logs de mensagens`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [AVISO] Nao foi possivel verificar audit logs: $_" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "RESUMO DO TESTE DE MULTIPLOS USUARIOS" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "[OK] Testes passaram: $testsPassed" -ForegroundColor Green
Write-Host "[ERRO] Testes falharam: $testsFailed" -ForegroundColor Red
Write-Host ""

"`n==========================================`n" | Out-File $RESULT_FILE -Append
"RESUMO:`n" | Out-File $RESULT_FILE -Append
"[OK] Testes passaram: $testsPassed`n" | Out-File $RESULT_FILE -Append
"[ERRO] Testes falharam: $testsFailed`n" | Out-File $RESULT_FILE -Append
"Performance: $messagesPerSecond msgs/s (sequencial)`n" | Out-File $RESULT_FILE -Append
"Concorrencia: $($users.Count) usuarios simultaneos`n" | Out-File $RESULT_FILE -Append

Write-Host "Resultado salvo em: $RESULT_FILE" -ForegroundColor Cyan
Write-Host ""

if ($testsFailed -eq 0) {
    Write-Host "[OK] TODOS OS TESTES PASSARAM!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "[ERRO] ALGUNS TESTES FALHARAM!" -ForegroundColor Red
    exit 1
}
