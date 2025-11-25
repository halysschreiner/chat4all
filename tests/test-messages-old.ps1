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

# Funcao para testar
function Test-Step {
    param($description, $command)
    Write-Host "[>>] $description" -ForegroundColor Yellow
    try {
        $result = Invoke-Expression $command
        if ($result) {
            Write-Host "  [OK] PASSOU" -ForegroundColor Green
            "[OK] $description`n" | Out-File $RESULT_FILE -Append
            $script:testsPassed++
            return $result
        }
    } catch {
        Write-Host "  [ERRO] FALHOU: $_" -ForegroundColor Red
        "[ERRO] $description - ERRO: $_`n" | Out-File $RESULT_FILE -Append
        $script:testsFailed++
    }
}

Write-Host ""
Write-Host "Passo 1: Criando usuarios de teste..." -ForegroundColor Cyan
Write-Host ""

# Criar usuario 1 (Alice)
$user1Response = Test-Step "Criar usuario Alice" @"
curl -s -X POST '$API_URL/auth/register' ``
  -H 'Content-Type: application/json' ``
  -d '{`"username`":`"alice_test`",`"email`":`"alice_test@test.com`",`"password`":`"senha123`"}'
"@

# Criar usuario 2 (Bob)
$user2Response = Test-Step "Criar usuario Bob" @"
curl -s -X POST '$API_URL/auth/register' ``
  -H 'Content-Type: application/json' ``
  -d '{`"username`":`"bob_test`",`"email`":`"bob_test@test.com`",`"password`":`"senha123`"}'
"@

Write-Host ""
Write-Host "Passo 2: Fazendo login..." -ForegroundColor Cyan
Write-Host ""

# Login Alice
$alice_login = Invoke-RestMethod -Uri "$API_URL/auth/login" -Method Post `
  -Headers @{"Content-Type"="application/json"} `
  -Body '{"email":"alice_test@test.com","password":"senha123"}'

if ($alice_login.token) {
    Write-Host "  [OK] Alice autenticada" -ForegroundColor Green
    $ALICE_TOKEN = $alice_login.token
    $ALICE_ID = $alice_login.user.user_id
    "[OK] Alice autenticada (ID: $ALICE_ID)`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha no login de Alice" -ForegroundColor Red
    "[ERRO] Falha no login de Alice`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
    exit 1
}

# Login Bob
$bob_login = Invoke-RestMethod -Uri "$API_URL/auth/login" -Method Post `
  -Headers @{"Content-Type"="application/json"} `
  -Body '{"email":"bob_test@test.com","password":"senha123"}'

if ($bob_login.token) {
    Write-Host "  [OK] Bob autenticado" -ForegroundColor Green
    $BOB_TOKEN = $bob_login.token
    $BOB_ID = $bob_login.user.user_id
    "[OK] Bob autenticado (ID: $BOB_ID)`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha no login de Bob" -ForegroundColor Red
    "[ERRO] Falha no login de Bob`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 3: Criando conversa privada..." -ForegroundColor Cyan
Write-Host ""

# Alice cria conversa com Bob
$conversation = Invoke-RestMethod -Uri "$API_URL/conversations/private" -Method Post `
  -Headers @{"Authorization"="Bearer $ALICE_TOKEN"; "Content-Type"="application/json"} `
  -Body "{`"other_user_id`":`"$BOB_ID`"}"

if ($conversation.success) {
    Write-Host "  [OK] Conversa criada" -ForegroundColor Green
    $CONVERSATION_ID = $conversation.conversation.conversation_id
    "[OK] Conversa criada (ID: $CONVERSATION_ID)`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha ao criar conversa" -ForegroundColor Red
    "[ERRO] Falha ao criar conversa`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 4: Enviando mensagens..." -ForegroundColor Cyan
Write-Host ""

# Alice envia mensagem para Bob
$msg1 = Invoke-RestMethod -Uri "$API_URL/messages" -Method Post `
  -Headers @{"Authorization"="Bearer $ALICE_TOKEN"; "Content-Type"="application/json"} `
  -Body "{`"conversation_id`":`"$CONVERSATION_ID`",`"content`":`"Ola Bob! Esta e uma mensagem de teste.`"}"

if ($msg1.success) {
    Write-Host "  [OK] Mensagem 1 enviada (Alice -> Bob)" -ForegroundColor Green
    $MSG1_ID = $msg1.message.message_id
    "[OK] Mensagem 1 enviada - ID: $MSG1_ID - Status: $($msg1.message.status)`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha ao enviar mensagem 1" -ForegroundColor Red
    "[ERRO] Falha ao enviar mensagem 1`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Start-Sleep -Seconds 2

# Bob responde para Alice
$msg2 = curl -s -X POST "$API_URL/messages" `
  -H "Authorization: Bearer $BOB_TOKEN" `
  -H 'Content-Type: application/json' `
  -d "{`"conversation_id`":`"$CONVERSATION_ID`",`"content`":`"Oi Alice! Recebi sua mensagem.`"}" | ConvertFrom-Json

if ($msg2.success) {
    Write-Host "  [OK] Mensagem 2 enviada (Bob -> Alice)" -ForegroundColor Green
    $MSG2_ID = $msg2.message.message_id
    "[OK] Mensagem 2 enviada - ID: $MSG2_ID - Status: $($msg2.message.status)`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha ao enviar mensagem 2" -ForegroundColor Red
    "[ERRO] Falha ao enviar mensagem 2`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Start-Sleep -Seconds 2

# Alice envia outra mensagem
$msg3 = curl -s -X POST "$API_URL/messages" `
  -H "Authorization: Bearer $ALICE_TOKEN" `
  -H 'Content-Type: application/json' `
  -d "{`"conversation_id`":`"$CONVERSATION_ID`",`"content`":`"Perfeito! Sistema funcionando.`"}" | ConvertFrom-Json

if ($msg3.success) {
    Write-Host "  [OK] Mensagem 3 enviada (Alice -> Bob)" -ForegroundColor Green
    $MSG3_ID = $msg3.message.message_id
    "[OK] Mensagem 3 enviada - ID: $MSG3_ID - Status: $($msg3.message.status)`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha ao enviar mensagem 3" -ForegroundColor Red
    "[ERRO] Falha ao enviar mensagem 3`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 5: Verificando armazenamento no PostgreSQL..." -ForegroundColor Cyan
Write-Host ""

# Verificar mensagens no banco
Start-Sleep -Seconds 1
$dbCheck = docker exec chat4all-postgres psql -U chat4all_user -d chat4all -t -c "SELECT COUNT(*) FROM messages WHERE conversation_id = '$CONVERSATION_ID';"

if ($dbCheck) {
    $msgCount = $dbCheck.Trim()
    if ([int]$msgCount -ge 3) {
        Write-Host "  [OK] $msgCount mensagens armazenadas no PostgreSQL" -ForegroundColor Green
        "[OK] Verificacao DB: $msgCount mensagens armazenadas`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    } else {
        Write-Host "  [ERRO] Esperado 3+ mensagens, encontrado: $msgCount" -ForegroundColor Red
        "[ERRO] Verificacao DB: Esperado 3+, encontrado: $msgCount`n" | Out-File $RESULT_FILE -Append
        $testsFailed++
    }
}

Write-Host ""
Write-Host "Passo 6: Testando status de mensagens (SENT -> DELIVERED -> READ)..." -ForegroundColor Cyan
Write-Host ""

# Bob busca mensagens (deve marcar como DELIVERED)
$bobMessages = curl -s -X GET "$API_URL/conversations/$CONVERSATION_ID/messages" `
  -H "Authorization: Bearer $BOB_TOKEN" | ConvertFrom-Json

if ($bobMessages.success) {
    Write-Host "  [OK] Bob buscou mensagens" -ForegroundColor Green
    "[OK] Bob buscou mensagens (total: $($bobMessages.messages.Count))`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha ao buscar mensagens de Bob" -ForegroundColor Red
    "[ERRO] Falha ao buscar mensagens de Bob`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Start-Sleep -Seconds 2

# Bob marca como lida
$readResponse = curl -s -X POST "$API_URL/conversations/$CONVERSATION_ID/read" `
  -H "Authorization: Bearer $BOB_TOKEN" | ConvertFrom-Json

if ($readResponse.success) {
    Write-Host "  [OK] Bob marcou mensagens como lidas ($($readResponse.messages_marked) mensagens)" -ForegroundColor Green
    "[OK] Bob marcou $($readResponse.messages_marked) mensagens como READ`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha ao marcar como lida" -ForegroundColor Red
    "[ERRO] Falha ao marcar como lida`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 7: Verificando transicoes de status no banco..." -ForegroundColor Cyan
Write-Host ""

# Verificar status das mensagens
$statusCheck = docker exec chat4all-postgres psql -U chat4all_user -d chat4all -t -c "SELECT message_id, status, delivered_at IS NOT NULL as has_delivered, read_at IS NOT NULL as has_read FROM messages WHERE conversation_id = '$CONVERSATION_ID' ORDER BY created_at;"

if ($statusCheck) {
    Write-Host "  Status das mensagens:" -ForegroundColor White
    Write-Host $statusCheck
    "Verificacao de status no DB:`n$statusCheck`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
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
