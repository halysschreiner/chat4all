# Cores para output
$Green = "Green"
$Blue = "Cyan"
$Yellow = "Yellow"
$Red = "Red"

Write-Host "========================================" -ForegroundColor $Blue
Write-Host "  Chat4All - Teste de Conectores Mock" -ForegroundColor $Blue
Write-Host "========================================" -ForegroundColor $Blue
Write-Host ""

# Verificar se os serviços estão rodando
Write-Host "📋 Verificando conectores..." -ForegroundColor $Yellow
Write-Host ""

# WhatsApp Health Check
Write-Host "🟢 WhatsApp Connector: " -NoNewline
try {
    $whatsappStatus = Invoke-WebRequest -Uri "http://localhost:8081/health" -Method GET -ErrorAction Stop
    if ($whatsappStatus.StatusCode -eq 200) {
        Write-Host "✅ Online" -ForegroundColor $Green
    }
} catch {
    Write-Host "❌ Offline" -ForegroundColor $Red
}

# Instagram Health Check
Write-Host "🟣 Instagram Connector: " -NoNewline
try {
    $instagramStatus = Invoke-WebRequest -Uri "http://localhost:8082/health" -Method GET -ErrorAction Stop
    if ($instagramStatus.StatusCode -eq 200) {
        Write-Host "✅ Online" -ForegroundColor $Green
    }
} catch {
    Write-Host "❌ Offline" -ForegroundColor $Red
}

Write-Host ""
Write-Host "========================================" -ForegroundColor $Blue
Write-Host "🧪 Testando WhatsApp Connector" -ForegroundColor $Yellow
Write-Host "========================================" -ForegroundColor $Blue
Write-Host ""

Write-Host "📤 Enviando mensagem de teste..." -ForegroundColor $Yellow
$whatsappBody = @{
    to = "+5511999999999"
    text = "Olá! Esta é uma mensagem de teste do WhatsApp Mock."
} | ConvertTo-Json

try {
    $whatsappResponse = Invoke-RestMethod -Uri "http://localhost:8081/send" -Method POST -Body $whatsappBody -ContentType "application/json"
    Write-Host "Resposta:" -ForegroundColor $Green
    $whatsappResponse | ConvertTo-Json | Write-Host
} catch {
    Write-Host "Erro: $_" -ForegroundColor $Red
}

Write-Host ""
Write-Host "📥 Simulando recebimento de mensagem..." -ForegroundColor $Yellow
$whatsappWebhookBody = @{
    from = "+5511888888888"
    text = "Olá, preciso de ajuda com meu pedido!"
} | ConvertTo-Json

try {
    $whatsappWebhook = Invoke-RestMethod -Uri "http://localhost:8081/webhook/incoming" -Method POST -Body $whatsappWebhookBody -ContentType "application/json"
    Write-Host "Resposta:" -ForegroundColor $Green
    $whatsappWebhook | ConvertTo-Json | Write-Host
} catch {
    Write-Host "Erro: $_" -ForegroundColor $Red
}

Write-Host ""
Write-Host "========================================" -ForegroundColor $Blue
Write-Host "🧪 Testando Instagram Connector" -ForegroundColor $Yellow
Write-Host "========================================" -ForegroundColor $Blue
Write-Host ""

Write-Host "📤 Enviando mensagem de teste..." -ForegroundColor $Yellow
$instagramBody = @{
    to = "@usuario_teste"
    text = "Olá! Esta é uma mensagem de teste do Instagram Mock."
} | ConvertTo-Json

try {
    $instagramResponse = Invoke-RestMethod -Uri "http://localhost:8082/send" -Method POST -Body $instagramBody -ContentType "application/json"
    Write-Host "Resposta:" -ForegroundColor $Green
    $instagramResponse | ConvertTo-Json | Write-Host
} catch {
    Write-Host "Erro: $_" -ForegroundColor $Red
}

Write-Host ""
Write-Host "📥 Simulando recebimento de mensagem..." -ForegroundColor $Yellow
$instagramWebhookBody = @{
    from = "@cliente_instagram"
    text = "Quero saber mais sobre os produtos!"
} | ConvertTo-Json

try {
    $instagramWebhook = Invoke-RestMethod -Uri "http://localhost:8082/webhook/incoming" -Method POST -Body $instagramWebhookBody -ContentType "application/json"
    Write-Host "Resposta:" -ForegroundColor $Green
    $instagramWebhook | ConvertTo-Json | Write-Host
} catch {
    Write-Host "Erro: $_" -ForegroundColor $Red
}

Write-Host ""
Write-Host "========================================" -ForegroundColor $Blue
Write-Host "Testes concluidos!" -ForegroundColor $Green
Write-Host "========================================" -ForegroundColor $Blue
Write-Host ""
Write-Host "Dica: Acompanhe os logs em tempo real com:" -ForegroundColor $Yellow
Write-Host "   docker-compose logs -f connector-whatsapp"
Write-Host "   docker-compose logs -f connector-instagram"
Write-Host ""
