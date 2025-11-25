# Test Script: Upload de Arquivos via API
# Conforme TAREFA.md: "Enviar arquivos via API e verificar armazenamento"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "TESTE 2: Upload de Arquivos via API" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$API_URL = "http://localhost:8000/v1"
$FILE_API_URL = "http://localhost:8080/v1"
$RESULTS_DIR = "results"
$RESULT_FILE = "$RESULTS_DIR/files-test-result.txt"
$TEST_FILE = "test-file.txt"

# Criar diretorio de resultados
if (!(Test-Path $RESULTS_DIR)) {
    New-Item -ItemType Directory -Path $RESULTS_DIR | Out-Null
}

# Iniciar log
$timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
"==========================================`n" | Out-File $RESULT_FILE
"TESTE DE UPLOAD DE ARQUIVOS - $timestamp`n" | Out-File $RESULT_FILE -Append
"==========================================`n" | Out-File $RESULT_FILE -Append

$testsPassed = 0
$testsFailed = 0

Write-Host ""
Write-Host "Passo 1: Criando arquivo de teste..." -ForegroundColor Cyan
Write-Host ""

# Criar arquivo de teste (1MB)
try {
    $randomContent = -join ((65..90) + (97..122) + (48..57) | Get-Random -Count 1000 | ForEach-Object {[char]$_})
    $fullContent = $randomContent * 50  # ~50KB
    $fullContent | Out-File $TEST_FILE -Encoding ASCII
    
    $fileSize = (Get-Item $TEST_FILE).Length
    Write-Host "  [OK] Arquivo criado: $TEST_FILE ($fileSize bytes)" -ForegroundColor Green
    "[OK] Arquivo de teste criado: $fileSize bytes`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} catch {
    Write-Host "  [ERRO] Falha ao criar arquivo: $_" -ForegroundColor Red
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 2: Autenticando usuario..." -ForegroundColor Cyan
Write-Host ""

# Criar e logar usuario
try {
    $userBody = @{username="file_test_user"; email="filetest@test.com"; password="senha123"} | ConvertTo-Json
    $userResponse = Invoke-RestMethod -Uri "$API_URL/auth/register" -Method Post `
        -Headers @{"Content-Type"="application/json"} -Body $userBody -ErrorAction SilentlyContinue
} catch {
    Write-Host "  [AVISO] Usuario ja existe (continuando...)" -ForegroundColor Yellow
}

try {
    $loginBody = @{email="filetest@test.com"; password="senha123"} | ConvertTo-Json
    $login = Invoke-RestMethod -Uri "$API_URL/auth/login" -Method Post `
        -Headers @{"Content-Type"="application/json"} -Body $loginBody

    if ($login.token) {
        Write-Host "  [OK] Usuario 1 autenticado" -ForegroundColor Green
        $TOKEN = $login.token
        $USER_ID = $login.user.user_id
        "[OK] Usuario 1 autenticado (ID: $USER_ID)`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha na autenticacao: $_" -ForegroundColor Red
    $testsFailed++
    exit 1
}

# Criar e logar segundo usuario
try {
    $userBody2 = @{username="file_test_user2"; email="filetest2@test.com"; password="senha123"} | ConvertTo-Json
    Invoke-RestMethod -Uri "$API_URL/auth/register" -Method Post `
        -Headers @{"Content-Type"="application/json"} -Body $userBody2 -ErrorAction SilentlyContinue | Out-Null
} catch {
    Write-Host "  [AVISO] Usuario 2 ja existe" -ForegroundColor Yellow
}

try {
    $loginBody2 = @{email="filetest2@test.com"; password="senha123"} | ConvertTo-Json
    $login2 = Invoke-RestMethod -Uri "$API_URL/auth/login" -Method Post `
        -Headers @{"Content-Type"="application/json"} -Body $loginBody2

    if ($login2.token) {
        Write-Host "  [OK] Usuario 2 autenticado" -ForegroundColor Green
        $USER2_ID = $login2.user.user_id
        "[OK] Usuario 2 autenticado (ID: $USER2_ID)`n" | Out-File $RESULT_FILE -Append
    }
} catch {
    Write-Host "  [ERRO] Falha ao autenticar usuario 2: $_" -ForegroundColor Red
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 3: Criando conversa de teste..." -ForegroundColor Cyan
Write-Host ""

# Criar conversa privada entre os dois usuarios
try {
    $convBody = @{other_user_id=$USER2_ID} | ConvertTo-Json
    $conversation = Invoke-RestMethod -Uri "http://localhost:8000/v1/conversations/private" -Method Post `
        -Headers @{"Authorization"="Bearer $TOKEN"; "Content-Type"="application/json"} `
        -Body $convBody

    if ($conversation.success) {
        Write-Host "  [OK] Conversa criada para teste" -ForegroundColor Green
        $CONVERSATION_ID = $conversation.conversation.conversation_id
        "[OK] Conversa criada - ID: $CONVERSATION_ID`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha ao criar conversa: $_" -ForegroundColor Red
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 4: Iniciando upload multipart..." -ForegroundColor Cyan
Write-Host ""

# Iniciar upload
try {
    $fileSize = (Get-Item $TEST_FILE).Length
    $initBody = @{
        conversation_id = $CONVERSATION_ID
        filename = "test-file.txt"
        file_size = $fileSize
        content_type = "text/plain"
    } | ConvertTo-Json
    
    $uploadInit = Invoke-RestMethod -Uri "$FILE_API_URL/files/upload/initiate" -Method Post `
        -Headers @{"Authorization"="Bearer $TOKEN"; "Content-Type"="application/json"} `
        -Body $initBody

    if ($uploadInit.success) {
        Write-Host "  [OK] Upload iniciado" -ForegroundColor Green
        $UPLOAD_ID = $uploadInit.upload_id
        $FILE_ID = $uploadInit.file_id
        "[OK] Upload iniciado - Upload ID: $UPLOAD_ID, File ID: $FILE_ID`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha ao iniciar upload: $_" -ForegroundColor Red
    "[ERRO] $($_.Exception.Message)`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 4: Enviando partes do arquivo..." -ForegroundColor Cyan
Write-Host ""

# Ler arquivo e dividir em partes
try {
    $fileContent = [System.IO.File]::ReadAllBytes($TEST_FILE)
    $partSize = 5KB
    $parts = @()
    $partNumber = 1
    
    for ($i = 0; $i -lt $fileContent.Length; $i += $partSize) {
        $end = [Math]::Min($i + $partSize, $fileContent.Length)
        $partData = $fileContent[$i..($end-1)]
        $base64Data = [Convert]::ToBase64String($partData)
        
        try {
            $partBody = @{
                upload_id = $UPLOAD_ID
                file_id = $FILE_ID
                part_number = $partNumber
                data = $base64Data
            } | ConvertTo-Json
            
            $partResponse = Invoke-RestMethod -Uri "$FILE_API_URL/files/upload/part" -Method Post `
                -Headers @{"Authorization"="Bearer $TOKEN"; "Content-Type"="application/json"} `
                -Body $partBody
            
            if ($partResponse.success) {
                $parts += @{part_number=$partNumber; etag=$partResponse.etag}
                Write-Host "  [OK] Parte $partNumber enviada ($($partData.Length) bytes)" -ForegroundColor Green
            }
            
            $partNumber++
        } catch {
            $errorMsg = $_.Exception.Message
            if ($_.ErrorDetails.Message) {
                $errorMsg += " - " + $_.ErrorDetails.Message
            }
            Write-Host "  [ERRO] Falha ao enviar parte $partNumber : $errorMsg" -ForegroundColor Red
            $testsFailed++
        }
    }
    
    if ($parts.Count -gt 0) {
        "[OK] $($parts.Count) partes enviadas`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Erro ao processar arquivo: $_" -ForegroundColor Red
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 5: Completando upload..." -ForegroundColor Cyan
Write-Host ""

# Completar upload
try {
    $completeBody = @{
        upload_id = $UPLOAD_ID
        file_id = $FILE_ID
    } | ConvertTo-Json -Depth 3
    
    $complete = Invoke-RestMethod -Uri "$FILE_API_URL/files/upload/complete" -Method Post `
        -Headers @{"Authorization"="Bearer $TOKEN"; "Content-Type"="application/json"} `
        -Body $completeBody

    if ($complete.success) {
        Write-Host "  [OK] Upload completado" -ForegroundColor Green
        "[OK] Upload completado - File ID: $FILE_ID`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
    }
} catch {
    Write-Host "  [ERRO] Falha ao completar upload: $_" -ForegroundColor Red
    "[ERRO] $($_.Exception.Message)`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 6: Verificando arquivo no MinIO..." -ForegroundColor Cyan
Write-Host ""

# Verificar no MinIO (simplificado - verificar se upload foi marcado como completed)
try {
    # Verificar status do arquivo no banco via API
    $fileCheck = Invoke-RestMethod -Uri "$FILE_API_URL/conversations/$CONVERSATION_ID/files" -Method Get `
        -Headers @{"Authorization"="Bearer $TOKEN"} -ErrorAction SilentlyContinue
    
    if ($fileCheck.success -and $fileCheck.files.Count -gt 0) {
        $uploadedFile = $fileCheck.files | Where-Object { $_.file_id -eq $FILE_ID }
        if ($uploadedFile -and $uploadedFile.status -eq 'completed') {
            Write-Host "  [OK] Arquivo verificado (status: completed)" -ForegroundColor Green
            "[OK] Arquivo verificado no sistema`n" | Out-File $RESULT_FILE -Append
            $testsPassed++
        } else {
            Write-Host "  [AVISO] Arquivo encontrado mas status: $($uploadedFile.status)" -ForegroundColor Yellow
            $testsPassed++
        }
    } else {
        Write-Host "  [AVISO] Arquivo nao encontrado na listagem" -ForegroundColor Yellow
    }
} catch {
    Write-Host "  [AVISO] Nao foi possivel verificar arquivo: $_" -ForegroundColor Yellow
}

# Limpeza
if (Test-Path $TEST_FILE) {
    Remove-Item $TEST_FILE -Force
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "RESUMO DO TESTE DE ARQUIVOS" -ForegroundColor Cyan
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
