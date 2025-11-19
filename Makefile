# Chat4All v2 - Makefile
# Comandos úteis para desenvolvimento

.PHONY: help setup build up down logs clean test proto

# Comando padrão
help:
	@echo "Chat4All v2 - Comandos Disponíveis"
	@echo "===================================="
	@echo ""
	@echo "  make setup     - Setup inicial (gerar proto, etc)"
	@echo "  make build     - Build dos containers"
	@echo "  make up        - Subir containers"
	@echo "  make down      - Parar containers"
	@echo "  make restart   - Reiniciar containers"
	@echo "  make logs      - Ver logs de todos os serviços"
	@echo "  make clean     - Limpar containers e volumes"
	@echo "  make test      - Executar testes da API"
	@echo "  make proto     - Regenerar código gRPC"
	@echo "  make db        - Acessar PostgreSQL"
	@echo "  make redis     - Acessar Redis CLI"
	@echo ""

# Setup inicial do projeto
setup:
	@echo "🚀 Executando setup..."
	@chmod +x scripts/setup.sh
	@./scripts/setup.sh

# Gerar código gRPC
proto:
	@echo "🔨 Gerando código gRPC..."
	@mkdir -p shared/generated
	@protoc --proto_path=shared/proto \
		--php_out=shared/generated \
		--grpc_out=shared/generated \
		--plugin=protoc-gen-grpc=$$(which grpc_php_plugin) \
		shared/proto/auth.proto
	@protoc --proto_path=shared/proto \
		--php_out=shared/generated \
		--grpc_out=shared/generated \
		--plugin=protoc-gen-grpc=$$(which grpc_php_plugin) \
		shared/proto/message.proto
	@protoc --proto_path=shared/proto \
		--php_out=shared/generated \
		--grpc_out=shared/generated \
		--plugin=protoc-gen-grpc=$$(which grpc_php_plugin) \
		shared/proto/conversation.proto
	@echo "✓ Código gRPC gerado!"

# Build dos containers
build:
	@echo "🔨 Building containers..."
	@docker-compose build

# Subir containers
up:
	@echo "🚀 Subindo containers..."
	@docker-compose up -d
	@echo ""
	@echo "✅ Chat4All v2 está rodando!"
	@echo ""
	@echo "  Frontend:    http://localhost:4200"
	@echo "  API Gateway: http://localhost:8080"
	@echo ""
	@echo "Use 'make logs' para ver os logs"

# Subir com logs
up-logs:
	@echo "🚀 Subindo containers com logs..."
	@docker-compose up

# Parar containers
down:
	@echo "🛑 Parando containers..."
	@docker-compose down

# Reiniciar containers
restart:
	@echo "🔄 Reiniciando containers..."
	@docker-compose restart

# Ver logs
logs:
	@docker-compose logs -f

# Logs de serviço específico
logs-auth:
	@docker-compose logs -f auth-service

logs-message:
	@docker-compose logs -f message-service

logs-conversation:
	@docker-compose logs -f conversation-service

logs-gateway:
	@docker-compose logs -f api-gateway

logs-frontend:
	@docker-compose logs -f frontend

# Limpar tudo
clean:
	@echo "🧹 Limpando containers e volumes..."
	@docker-compose down -v
	@echo "✓ Limpeza completa!"

# Limpar e reconstruir
rebuild: clean build up

# Executar testes
test:
	@echo "🧪 Executando testes da API..."
	@chmod +x scripts/test-api.sh
	@./scripts/test-api.sh

# Acessar PostgreSQL
db:
	@echo "🗄️  Acessando PostgreSQL..."
	@docker exec -it chat4all_postgres psql -U chat4all -d chat4all

# Acessar Redis
redis:
	@echo "📦 Acessando Redis..."
	@docker exec -it chat4all_redis redis-cli

# Status dos containers
status:
	@docker-compose ps

# Ver uso de recursos
stats:
	@docker stats --no-stream

# Backup do banco
backup-db:
	@echo "💾 Fazendo backup do banco..."
	@docker exec chat4all_postgres pg_dump -U chat4all chat4all > backup_$$(date +%Y%m%d_%H%M%S).sql
	@echo "✓ Backup criado!"

# Restaurar banco
restore-db:
	@echo "⚠️  Restaurando banco..."
	@echo "Use: make restore-db FILE=backup_XXXXXXXX_XXXXXX.sql"
	@if [ -z "$(FILE)" ]; then \
		echo "Erro: especifique FILE=nome_do_arquivo.sql"; \
		exit 1; \
	fi
	@docker exec -i chat4all_postgres psql -U chat4all chat4all < $(FILE)
	@echo "✓ Banco restaurado!"

# Instalar dependências PHP
install-php:
	@echo "📦 Instalando dependências PHP..."
	@docker-compose run --rm auth-service composer install
	@docker-compose run --rm message-service composer install
	@docker-compose run --rm conversation-service composer install
	@docker-compose run --rm api-gateway composer install
	@echo "✓ Dependências instaladas!"

# Instalar dependências Node
install-node:
	@echo "📦 Instalando dependências Node..."
	@cd frontend && npm install
	@echo "✓ Dependências instaladas!"

# Health check
health:
	@echo "🏥 Verificando saúde dos serviços..."
	@echo ""
	@echo "Frontend:"
	@curl -s http://localhost:4200 > /dev/null && echo "  ✓ OK" || echo "  ✗ FALHOU"
	@echo ""
	@echo "API Gateway:"
	@curl -s http://localhost:8080 > /dev/null && echo "  ✓ OK" || echo "  ✗ FALHOU"
	@echo ""
	@echo "PostgreSQL:"
	@docker exec chat4all_postgres pg_isready -U chat4all > /dev/null && echo "  ✓ OK" || echo "  ✗ FALHOU"
	@echo ""
	@echo "Redis:"
	@docker exec chat4all_redis redis-cli ping > /dev/null && echo "  ✓ OK" || echo "  ✗ FALHOU"
	@echo ""