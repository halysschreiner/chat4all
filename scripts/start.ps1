# Script de inicialização do Chat4All
# Sobe todos os serviços necessários usando Docker Compose

Write-Host "================================================" -ForegroundColor Cyan
Write-Host "  Chat4All - Sistema de Mensagens Distribuido" -ForegroundColor Cyan
Write-Host "  Inicializando servicos..." -ForegroundColor Cyan
Write-Host "================================================" -ForegroundColor Cyan
Write-Host ""

# Verificar se Docker está instalado
Write-Host "Verificando dependencias..." -ForegroundColor Yellow
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Host "ERRO: Docker nao esta instalado. Por favor, instale o Docker Desktop primeiro." -ForegroundColor Red
    Write-Host "Download: https://www.docker.com/products/docker-desktop" -ForegroundColor Yellow
    exit 1
}

# Verificar se Docker Compose está disponível
$dockerComposeCmd = if (Get-Command docker-compose -ErrorAction SilentlyContinue) { "docker-compose" } else { "docker compose" }
Write-Host "Usando comando: $dockerComposeCmd" -ForegroundColor Green

# Verificar se o Docker está rodando
try {
    docker info 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERRO: Docker nao esta rodando. Por favor, inicie o Docker Desktop." -ForegroundColor Red
        exit 1
    }
} catch {
    Write-Host "ERRO: Docker nao esta rodando. Por favor, inicie o Docker Desktop." -ForegroundColor Red
    exit 1
}

Write-Host "Docker esta ativo!" -ForegroundColor Green
Write-Host ""

# Navegar para o diretório raiz do projeto
$scriptPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent $scriptPath
Set-Location $projectRoot

# Parar containers anteriores se existirem
Write-Host "Limpando containers anteriores..." -ForegroundColor Yellow
& $dockerComposeCmd down 2>&1 | Out-Null

Write-Host ""
Write-Host "Construindo imagens Docker..." -ForegroundColor Yellow
Write-Host "Isso pode levar alguns minutos na primeira execucao..." -ForegroundColor Gray
& $dockerComposeCmd build

if ($LASTEXITCODE -ne 0) {
    Write-Host "ERRO: Falha ao construir as imagens Docker." -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Iniciando servicos de infraestrutura..." -ForegroundColor Yellow
Write-Host "  - PostgreSQL (Banco de Dados)" -ForegroundColor Gray
Write-Host "  - Redis (Cache e Sessoes)" -ForegroundColor Gray
Write-Host "  - Zookeeper (Coordenacao Kafka)" -ForegroundColor Gray
Write-Host "  - Kafka (Message Broker)" -ForegroundColor Gray
Write-Host "  - MinIO (Object Storage)" -ForegroundColor Gray
& $dockerComposeCmd up -d postgres redis zookeeper kafka minio

Write-Host ""
Write-Host "Aguardando inicializacao dos servicos de infraestrutura..." -ForegroundColor Yellow
Write-Host "Isso pode levar ate 30 segundos..." -ForegroundColor Gray
Start-Sleep -Seconds 20

# Verificar saúde do PostgreSQL
Write-Host ""
Write-Host "Verificando PostgreSQL..." -ForegroundColor Yellow
$pgReady = $false
for ($i = 1; $i -le 10; $i++) {
    & $dockerComposeCmd exec -T postgres pg_isready -U chat4all_user -d chat4all 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) {
        Write-Host "PostgreSQL esta pronto!" -ForegroundColor Green
        $pgReady = $true
        break
    }
    Write-Host "Aguardando PostgreSQL... (tentativa $i/10)" -ForegroundColor Gray
    Start-Sleep -Seconds 3
}

if (-not $pgReady) {
    Write-Host "AVISO: PostgreSQL pode nao estar completamente pronto." -ForegroundColor Yellow
}

# Verificar saúde do Redis
Write-Host ""
Write-Host "Verificando Redis..." -ForegroundColor Yellow
& $dockerComposeCmd exec -T redis redis-cli ping 2>&1 | Out-Null
if ($LASTEXITCODE -eq 0) {
    Write-Host "Redis esta pronto!" -ForegroundColor Green
} else {
    Write-Host "AVISO: Redis pode nao estar completamente pronto." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Iniciando servicos da aplicacao..." -ForegroundColor Yellow
Write-Host "  - API Service (Core gRPC Service + HTTP REST with CORS)" -ForegroundColor Gray
Write-Host "    > gRPC: port 50051" -ForegroundColor DarkGray
Write-Host "    > HTTP: port 8080 (file uploads enabled)" -ForegroundColor DarkGray
Write-Host "  - API Gateway (REST to gRPC)" -ForegroundColor Gray
Write-Host "  - Router Worker (Kafka Consumer)" -ForegroundColor Gray
Write-Host "  - Frontend Web (Angular)" -ForegroundColor Gray
& $dockerComposeCmd up -d api-service api-gateway router-worker web

Write-Host ""
Write-Host "Aguardando servicos da aplicacao ficarem prontos..." -ForegroundColor Yellow
Start-Sleep -Seconds 15

Write-Host ""
Write-Host "Verificando status dos containers..." -ForegroundColor Yellow
& $dockerComposeCmd ps

Write-Host ""
Write-Host "================================================" -ForegroundColor Green
Write-Host "  Chat4All iniciado com sucesso!" -ForegroundColor Green
Write-Host "================================================" -ForegroundColor Green
Write-Host ""
Write-Host "Servicos disponiveis:" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Interface Web:     " -NoNewline -ForegroundColor White
Write-Host "http://localhost:9000" -ForegroundColor Yellow
Write-Host "  API Gateway:       " -NoNewline -ForegroundColor White
Write-Host "http://localhost:8000" -ForegroundColor Yellow
Write-Host "  API Service gRPC:  " -NoNewline -ForegroundColor White
Write-Host "localhost:50051" -ForegroundColor Yellow
Write-Host "  API Service HTTP:  " -NoNewline -ForegroundColor White
Write-Host "http://localhost:8080" -ForegroundColor Yellow
Write-Host ""
Write-Host "Infraestrutura:" -ForegroundColor Cyan
Write-Host "  PostgreSQL:        " -NoNewline -ForegroundColor White
Write-Host "localhost:5432 (user: chat4all_user, db: chat4all)" -ForegroundColor Gray
Write-Host "  Redis:             " -NoNewline -ForegroundColor White
Write-Host "localhost:6379" -ForegroundColor Gray
Write-Host "  Kafka:             " -NoNewline -ForegroundColor White
Write-Host "localhost:9092" -ForegroundColor Gray
Write-Host "  MinIO API:         " -NoNewline -ForegroundColor White
Write-Host "http://localhost:9002" -ForegroundColor Gray
Write-Host "  MinIO Console:     " -NoNewline -ForegroundColor White
Write-Host "http://localhost:9003 (user: minio, senha: minio123)" -ForegroundColor Gray
Write-Host ""
Write-Host "Comandos uteis:" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Ver logs (todos):        " -NoNewline -ForegroundColor White
Write-Host "$dockerComposeCmd logs -f" -ForegroundColor Yellow
Write-Host "  Ver logs (especifico):   " -NoNewline -ForegroundColor White
Write-Host "$dockerComposeCmd logs -f api-service" -ForegroundColor Yellow
Write-Host "  Parar servicos:          " -NoNewline -ForegroundColor White
Write-Host "$dockerComposeCmd down" -ForegroundColor Yellow
Write-Host "  Parar e limpar volumes:  " -NoNewline -ForegroundColor White
Write-Host "$dockerComposeCmd down -v" -ForegroundColor Yellow
Write-Host "  Reiniciar servico:       " -NoNewline -ForegroundColor White
Write-Host "$dockerComposeCmd restart <nome-servico>" -ForegroundColor Yellow
Write-Host ""
Write-Host "Para testar a API, execute:" -ForegroundColor Cyan
Write-Host "  .\scripts\test-api.sh" -ForegroundColor Yellow
Write-Host ""
Write-Host "================================================" -ForegroundColor Green
Write-Host ""
Write-Host "Acesse o frontend em: " -NoNewline -ForegroundColor White
Write-Host "http://localhost:9000" -ForegroundColor Green -BackgroundColor Black
Write-Host ""
