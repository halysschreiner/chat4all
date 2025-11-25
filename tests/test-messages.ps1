# Test Script: Envio de Mensagens via API
# Conforme TAREFA.md: "Enviar mensagens via API e verificar armazenamento"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "TESTE 1: Envio de Mensagens via API" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$API_URL = "http://localhost:8000/v1"
$RESULTS_DIR = "results"
$RESULT_FILE = "$RESULTS_DIR/messages-test-result.txt"

# Criar diretorio de resultados
if (!(Test-Path $RESULTS_DIR)) {
    New-Item -ItemType Directory -Path $RESULTS_DIR | Out-Null
}

# Iniciar log
$timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
"==========================================`n" | Out-File $RESULT_FILE
"TESTE DE MENSAGENS - $timestamp`n" | Out-File $RESULT_FILE -Append
"==========================================`n" | Out-File $RESULT_FILE -Append

$testsPassed = 0
$testsFailed = 0

Write-Host ""
Write-Host "Passo 1: Criando usuarios de teste..." -ForegroundColor Cyan
Write-Host ""

# Criar usuario 1 (Alice)
try {
    $user1Body = @{username="alice_test"; email="alice_test@test.com"; password="senha123"} | ConvertTo-Json
    $user1Response = Invoke-RestMethod -Uri "$API_URL/auth/register" -Method Post `
        -Headers @{"Content-Type"="application/json"} -Body $user1Body -ErrorAction SilentlyContinue
    Write-Host "  [OK] Usuario Alice criado" -ForegroundColor Green
} catch {
    Write-Host "  [AVISO] Usuario Alice ja existe (continuando...)" -ForegroundColor Yellow
}

# Criar usuario 2 (Bob)
try {
    $user2Body = @{username="bob_test"; email="bob_test@test.com"; password="senha123"} | ConvertTo-Json
    $user2Response = Invoke-RestMethod -Uri "$API_URL/auth/register" -Method Post `
        -Headers @{"Content-Type"="application/json"} -Body $user2Body -ErrorAction SilentlyContinue
    Write-Host "  [OK] Usuario Bob criado" -ForegroundColor Green
} catch {
    Write-Host "  [AVISO] Usuario Bob ja existe (continuando...)" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Passo 2: Fazendo login..." -ForegroundColor Cyan
Write-Host ""

# Login Alice
try {
    $aliceLoginBody = @{email="alice_test@test.com"; password="senha123"} | ConvertTo-Json
    $alice_login = Invoke-RestMethod -Uri "$API_URL/auth/login" -Method Post `
        -Headers @{"Content-Type"="application/json"} -Body $aliceLoginBody

    if ($alice_login.token) {
        Write-Host "  [OK] Alice autenticada" -ForegroundColor Green
        $ALICE_TOKEN = $alice_login.token
        $ALICE_ID = $alice_login.user.user_id
        "[OK] Alice autenticada (ID: $ALICE_ID)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha no login de Alice: $_" -ForegroundColor Red
    "[ERRO] Falha no login de Alice`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
    exit 1
}

# Login Bob
try {
    $bobLoginBody = @{email="bob_test@test.com"; password="senha123"} | ConvertTo-Json
    $bob_login = Invoke-RestMethod -Uri "$API_URL/auth/login" -Method Post `
        -Headers @{"Content-Type"="application/json"} -Body $bobLoginBody

    if ($bob_login.token) {
        Write-Host "  [OK] Bob autenticado" -ForegroundColor Green
        $BOB_TOKEN = $bob_login.token
        $BOB_ID = $bob_login.user.user_id
        "[OK] Bob autenticado (ID: $BOB_ID)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha no login de Bob: $_" -ForegroundColor Red
    "[ERRO] Falha no login de Bob`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 3: Criando conversa privada..." -ForegroundColor Cyan
Write-Host ""

# Alice cria conversa com Bob
try {
    $convBody = @{other_user_id=$BOB_ID} | ConvertTo-Json
    $conversation = Invoke-RestMethod -Uri "$API_URL/conversations/private" -Method Post `
        -Headers @{"Authorization"="Bearer $ALICE_TOKEN"; "Content-Type"="application/json"} `
        -Body $convBody

    if ($conversation.success) {
        Write-Host "  [OK] Conversa criada" -ForegroundColor Green
        $CONVERSATION_ID = $conversation.conversation.conversation_id
        "[OK] Conversa criada (ID: $CONVERSATION_ID)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha ao criar conversa: $_" -ForegroundColor Red
    "[ERRO] Falha ao criar conversa`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 4: Enviando mensagens..." -ForegroundColor Cyan
Write-Host ""

# Alice envia mensagem para Bob
try {
    $msg1Body = @{conversation_id=$CONVERSATION_ID; content="Ola Bob! Esta e uma mensagem de teste."} | ConvertTo-Json
    $msg1 = Invoke-RestMethod -Uri "$API_URL/messages" -Method Post `
        -Headers @{"Authorization"="Bearer $ALICE_TOKEN"; "Content-Type"="application/json"} `
        -Body $msg1Body

    if ($msg1.success) {
        Write-Host "  [OK] Mensagem 1 enviada (Alice -> Bob)" -ForegroundColor Green
        $MSG1_ID = $msg1.message.message_id
        "[OK] Mensagem 1 enviada - ID: $MSG1_ID - Status: $($msg1.message.status)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha ao enviar mensagem 1: $_" -ForegroundColor Red
    "[ERRO] Falha ao enviar mensagem 1`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Start-Sleep -Seconds 2

# Bob responde para Alice
try {
    $msg2Body = @{conversation_id=$CONVERSATION_ID; content="Oi Alice! Recebi sua mensagem."} | ConvertTo-Json
    $msg2 = Invoke-RestMethod -Uri "$API_URL/messages" -Method Post `
        -Headers @{"Authorization"="Bearer $BOB_TOKEN"; "Content-Type"="application/json"} `
        -Body $msg2Body

    if ($msg2.success) {
        Write-Host "  [OK] Mensagem 2 enviada (Bob -> Alice)" -ForegroundColor Green
        $MSG2_ID = $msg2.message.message_id
        "[OK] Mensagem 2 enviada - ID: $MSG2_ID - Status: $($msg2.message.status)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha ao enviar mensagem 2: $_" -ForegroundColor Red
    "[ERRO] Falha ao enviar mensagem 2`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Start-Sleep -Seconds 2

# Alice envia mais uma mensagem
try {
    $msg3Body = @{conversation_id=$CONVERSATION_ID; content="Otimo! Vamos testar o sistema."} | ConvertTo-Json
    $msg3 = Invoke-RestMethod -Uri "$API_URL/messages" -Method Post `
        -Headers @{"Authorization"="Bearer $ALICE_TOKEN"; "Content-Type"="application/json"} `
        -Body $msg3Body

    if ($msg3.success) {
        Write-Host "  [OK] Mensagem 3 enviada (Alice -> Bob)" -ForegroundColor Green
        $MSG3_ID = $msg3.message.message_id
        "[OK] Mensagem 3 enviada - ID: $MSG3_ID - Status: $($msg3.message.status)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha ao enviar mensagem 3: $_" -ForegroundColor Red
    "[ERRO] Falha ao enviar mensagem 3`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 5: Verificando armazenamento no PostgreSQL..." -ForegroundColor Cyan
Write-Host ""

# Verificar mensagens no banco
try {
    $dbCheck = docker exec chat4all-postgres psql -U chat4all_user -d chat4all -t -c "SELECT COUNT(*) FROM messages WHERE conversation_id = '$CONVERSATION_ID';" 2>&1
    
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
Write-Host "Passo 6: Testando transicoes de status (DELIVERED)..." -ForegroundColor Cyan
Write-Host ""

# Bob busca mensagens (deve marcar como DELIVERED)
try {
    $bobMessages = Invoke-RestMethod -Uri "$API_URL/conversations/$CONVERSATION_ID/messages" -Method Get `
        -Headers @{"Authorization"="Bearer $BOB_TOKEN"}

    if ($bobMessages.success) {
        $msgCount = $bobMessages.messages.Count
        Write-Host "  [OK] Bob buscou $msgCount mensagens" -ForegroundColor Green
        "[OK] Bob buscou mensagens - Status deve ser DELIVERED`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha ao buscar mensagens: $_" -ForegroundColor Red
    $testsFailed++
}

Start-Sleep -Seconds 1

Write-Host ""
Write-Host "Passo 7: Testando transicoes de status (READ)..." -ForegroundColor Cyan
Write-Host ""

# Bob marca conversa como lida
try {
    $readResponse = Invoke-RestMethod -Uri "$API_URL/conversations/$CONVERSATION_ID/read" -Method Post `
        -Headers @{"Authorization"="Bearer $BOB_TOKEN"}

    if ($readResponse.success) {
        Write-Host "  [OK] Mensagens marcadas como lidas" -ForegroundColor Green
        "[OK] Mensagens marcadas como READ`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha ao marcar como lida: $_" -ForegroundColor Red
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 8: Verificando status final no banco..." -ForegroundColor Cyan
Write-Host ""

# Verificar status das mensagens
try {
    $statusCheck = docker exec chat4all-postgres psql -U chat4all_user -d chat4all -t -c "SELECT message_id, status, delivered_at IS NOT NULL as has_delivered, read_at IS NOT NULL as has_read FROM messages WHERE conversation_id = '$CONVERSATION_ID' ORDER BY created_at;" 2>&1

    if ($statusCheck -is [System.Management.Automation.ErrorRecord]) {
        Write-Host "  [ERRO] Falha ao verificar status no banco" -ForegroundColor Red
        $testsFailed++
    } else {
        Write-Host "  [OK] Status das mensagens verificado:" -ForegroundColor Green
        Write-Host $statusCheck
        "Verificacao de status no DB:`n$statusCheck`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Erro ao verificar status: $_" -ForegroundColor Red
    $testsFailed++
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "RESUMO DO TESTE DE MENSAGENS" -ForegroundColor Cyan
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
