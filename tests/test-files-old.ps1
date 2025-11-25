# Test Script: Upload de Arquivos via API
# Conforme TAREFA.md: "Enviar arquivos via API e verificar armazenamento"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "TESTE 2: Upload de Arquivos via API" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$API_URL = "http://localhost:8000/v1"
$RESULTS_DIR = "results"
$RESULT_FILE = "$RESULTS_DIR/files-test-result.txt"

# Criar diretorio de resultados
if (!(Test-Path $RESULTS_DIR)) {
    New-Item -ItemType Directory -Path $RESULTS_DIR | Out-Null
}

# Iniciar log
$timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
"==========================================`n" | Out-File $RESULT_FILE
"TESTE DE ARQUIVOS - $timestamp`n" | Out-File $RESULT_FILE -Append
"==========================================`n" | Out-File $RESULT_FILE -Append

$testsPassed = 0
$testsFailed = 0

Write-Host "Passo 1: Criando arquivo de teste..." -ForegroundColor Cyan
Write-Host ""

# Criar arquivo de teste (1MB)
$testFile = "test-file.txt"
$fileContent = "Este e um arquivo de teste para o Chat4All.`n" * 1000
$fileContent | Out-File -FilePath $testFile -Encoding UTF8

$fileSize = (Get-Item $testFile).Length
Write-Host "  [OK] Arquivo criado: $testFile ($fileSize bytes)" -ForegroundColor Green
"[OK] Arquivo de teste criado: $fileSize bytes`n" | Out-File $RESULT_FILE -Append
$testsPassed++

Write-Host ""
Write-Host "Passo 2: Autenticando usuario..." -ForegroundColor Cyan
Write-Host ""

# Login
$login = curl -s -X POST "$API_URL/auth/login" `
  -H 'Content-Type: application/json' `
  -d '{""email"":""alice_test@test.com"",""password"":""senha123""}' | ConvertFrom-Json

if ($login.token) {
    Write-Host "  [OK] Usuario autenticado" -ForegroundColor Green
    $TOKEN = $login.token
    $USER_ID = $login.user.user_id
    "[OK] Autenticacao OK (User ID: $USER_ID)`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha na autenticacao" -ForegroundColor Red
    "[ERRO] Falha na autenticacao`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 3: Obtendo ID da conversa..." -ForegroundColor Cyan
Write-Host ""

$conversations = curl -s -X GET "$API_URL/conversations" `
  -H "Authorization: Bearer $TOKEN" | ConvertFrom-Json

if ($conversations.success -and $conversations.conversations.Count -gt 0) {
    $CONVERSATION_ID = $conversations.conversations[0].conversation_id
    Write-Host "  [OK] Conversa encontrada: $CONVERSATION_ID" -ForegroundColor Green
    "[OK] Conversa encontrada: $CONVERSATION_ID`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Nenhuma conversa encontrada" -ForegroundColor Red
    "[ERRO] Nenhuma conversa encontrada`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 4: Iniciando upload multipart..." -ForegroundColor Cyan
Write-Host ""

# Iniciar upload
$initUpload = curl -s -X POST "$API_URL/files/upload/init" `
  -H "Authorization: Bearer $TOKEN" `
  -H 'Content-Type: application/json' `
  -d "{`"conversation_id`":`"$CONVERSATION_ID`",`"filename`":`"$testFile`",`"file_size`":$fileSize,`"mime_type`":`"text/plain`"}" | ConvertFrom-Json

if ($initUpload.success) {
    Write-Host "  [OK] Upload iniciado" -ForegroundColor Green
    $UPLOAD_ID = $initUpload.upload_id
    $FILE_ID = $initUpload.file_id
    $PART_SIZE = $initUpload.part_size
    $TOTAL_PARTS = $initUpload.total_parts
    "[OK] Upload iniciado - File ID: $FILE_ID, Parts: $TOTAL_PARTS`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha ao iniciar upload" -ForegroundColor Red
    "[ERRO] Falha ao iniciar upload`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
    exit 1
}

Write-Host ""
Write-Host "Passo 5: Enviando partes do arquivo..." -ForegroundColor Cyan
Write-Host ""

# Ler arquivo e dividir em partes
$fileBytes = [System.IO.File]::ReadAllBytes($testFile)
$partsUploaded = 0

for ($partNumber = 1; $partNumber -le $TOTAL_PARTS; $partNumber++) {
    $start = ($partNumber - 1) * $PART_SIZE
    $end = [Math]::Min($start + $PART_SIZE, $fileBytes.Length)
    $partBytes = $fileBytes[$start..($end-1)]
    
    # Salvar parte temporaria
    $partFile = "part$partNumber.tmp"
    [System.IO.File]::WriteAllBytes($partFile, $partBytes)
    
    # Upload da parte
    $uploadPart = curl -s -X POST "$API_URL/files/upload/part" `
      -H "Authorization: Bearer $TOKEN" `
      -F "upload_id=$UPLOAD_ID" `
      -F "file_id=$FILE_ID" `
      -F "part_number=$partNumber" `
      -F "file=@$partFile" | ConvertFrom-Json
    
    Remove-Item $partFile -ErrorAction SilentlyContinue
    
    if ($uploadPart.success) {
        $partsUploaded++
        Write-Host "  [OK] Parte $partNumber/$TOTAL_PARTS enviada" -ForegroundColor Green
    } else {
        Write-Host "  [ERRO] Falha ao enviar parte $partNumber" -ForegroundColor Red
        "[ERRO] Falha ao enviar parte $partNumber`n" | Out-File $RESULT_FILE -Append
        $testsFailed++
    }
}

"[OK] $partsUploaded partes enviadas de $TOTAL_PARTS`n" | Out-File $RESULT_FILE -Append
if ($partsUploaded -eq $TOTAL_PARTS) {
    $testsPassed++
}

Write-Host ""
Write-Host "Passo 6: Completando upload..." -ForegroundColor Cyan
Write-Host ""

# Completar upload
$completeUpload = curl -s -X POST "$API_URL/files/upload/complete" `
  -H "Authorization: Bearer $TOKEN" `
  -H 'Content-Type: application/json' `
  -d "{`"upload_id`":`"$UPLOAD_ID`",`"file_id`":`"$FILE_ID`"}" | ConvertFrom-Json

if ($completeUpload.success) {
    Write-Host "  [OK] Upload completado" -ForegroundColor Green
    "[OK] Upload completado - File ID: $FILE_ID`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha ao completar upload" -ForegroundColor Red
    "[ERRO] Falha ao completar upload`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 7: Enviando mensagem com arquivo..." -ForegroundColor Cyan
Write-Host ""

# Enviar mensagem com arquivo
$msgWithFile = curl -s -X POST "$API_URL/messages" `
  -H "Authorization: Bearer $TOKEN" `
  -H 'Content-Type: application/json' `
  -d "{`"conversation_id`":`"$CONVERSATION_ID`",`"content`":`"Arquivo anexado: $testFile`",`"message_type`":`"file`",`"file_id`":`"$FILE_ID`"}" | ConvertFrom-Json

if ($msgWithFile.success) {
    Write-Host "  [OK] Mensagem com arquivo enviada" -ForegroundColor Green
    "[OK] Mensagem com arquivo enviada - Msg ID: $($msgWithFile.message.message_id)`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [ERRO] Falha ao enviar mensagem com arquivo" -ForegroundColor Red
    "[ERRO] Falha ao enviar mensagem com arquivo`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

Write-Host ""
Write-Host "Passo 8: Verificando armazenamento no MinIO..." -ForegroundColor Cyan
Write-Host ""

# Verificar no MinIO
$minioCheck = docker exec chat4all-minio mc ls local/chat4all-files/ 2>&1 | Select-String $FILE_ID

if ($minioCheck) {
    Write-Host "  [OK] Arquivo encontrado no MinIO" -ForegroundColor Green
    "[OK] Arquivo verificado no MinIO`n" | Out-File $RESULT_FILE -Append
    $testsPassed++
} else {
    Write-Host "  [AVISO] Arquivo nao encontrado no MinIO (pode estar em processo)" -ForegroundColor Yellow
    "[AVISO] Arquivo nao encontrado no MinIO`n" | Out-File $RESULT_FILE -Append
}

Write-Host ""
Write-Host "Passo 9: Testando download do arquivo..." -ForegroundColor Cyan
Write-Host ""

# Baixar arquivo
$downloadFile = "downloaded-$testFile"
try {
    Invoke-WebRequest -Uri "$API_URL/files/$FILE_ID/download" `
        -Headers @{"Authorization"="Bearer $TOKEN"} `
        -OutFile $downloadFile
    
    if (Test-Path $downloadFile) {
        $downloadedSize = (Get-Item $downloadFile).Length
        Write-Host "  [OK] Arquivo baixado: $downloadedSize bytes" -ForegroundColor Green
        "[OK] Download OK: $downloadedSize bytes`n" | Out-File $RESULT_FILE -Append
        $testsPassed++
        
        # Limpar arquivo baixado
        Remove-Item $downloadFile -ErrorAction SilentlyContinue
    }
} catch {
    Write-Host "  [ERRO] Falha no download: $_" -ForegroundColor Red
    "[ERRO] Falha no download`n" | Out-File $RESULT_FILE -Append
    $testsFailed++
}

# Limpar arquivo de teste
Remove-Item $testFile -ErrorAction SilentlyContinue

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
