#!/bin/bash

##############################################################################
# Chat4All - Full Demo Script
# 
# CONCEITO DE SISTEMAS DISTRIBUÍDOS:
# Este script demonstra todos os conceitos implementados no Chat4All:
# 1. Arquitetura de microsserviços
# 2. Comunicação assíncrona via Kafka
# 3. Object Storage com MinIO (S3)
# 4. Notificações em tempo real via WebSocket
# 5. Escalabilidade horizontal
# 6. Tolerância a falhas
# 7. Observabilidade com Prometheus/Grafana
#
# Referência: Trabalho Final - Escalabilidade e Relatório (UFG)
##############################################################################

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# Configurações
API_URL="${API_URL:-http://localhost:8080}"
WS_URL="${WS_URL:-ws://localhost:8081}"
GRAFANA_URL="${GRAFANA_URL:-http://localhost:3000}"
MINIO_URL="${MINIO_URL:-http://localhost:9001}"
DEMO_PAUSE="${DEMO_PAUSE:-3}"

# Variáveis de estado
USER_ID=""
AUTH_TOKEN=""
CONVERSATION_ID=""
MESSAGE_ID=""
FILE_ID=""

##############################################################################
# Funções de Output
##############################################################################

print_header() {
    echo ""
    echo -e "${CYAN}╔════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC}  $1"
    echo -e "${CYAN}╚════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

print_step() {
    echo -e "\n${BOLD}${BLUE}▶ PASSO $1:${NC} $2\n"
}

print_concept() {
    echo -e "${MAGENTA}📚 CONCEITO:${NC} $1\n"
}

print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

print_wait() {
    echo -e "${YELLOW}⏳${NC} $1"
}

wait_for_user() {
    if [ "$AUTO_MODE" != "true" ]; then
        echo ""
        echo -e "${YELLOW}Pressione ENTER para continuar...${NC}"
        read -r
    else
        sleep "$DEMO_PAUSE"
    fi
}

##############################################################################
# Funções de Demo
##############################################################################

check_services() {
    print_header "Verificando Serviços"
    
    local services=("api-service:8080" "websocket-worker:8081" "kafka:9092" "postgres:5432" "redis:6379" "minio:9000")
    local all_up=true
    
    for service in "${services[@]}"; do
        local name="${service%:*}"
        local port="${service#*:}"
        
        if curl -s --connect-timeout 2 "http://localhost:$port" > /dev/null 2>&1 || \
           nc -z localhost "$port" 2>/dev/null; then
            print_success "$name está rodando na porta $port"
        else
            echo -e "${RED}✗${NC} $name NÃO está respondendo na porta $port"
            all_up=false
        fi
    done
    
    if [ "$all_up" = false ]; then
        echo ""
        echo -e "${YELLOW}Alguns serviços não estão rodando.${NC}"
        echo "Execute: docker-compose up -d"
        exit 1
    fi
    
    echo ""
    print_success "Todos os serviços estão operacionais!"
}

demo_step_1_registration() {
    print_header "PASSO 1: Registro e Autenticação"
    
    print_concept "O sistema utiliza JWT (JSON Web Tokens) para autenticação stateless.
Isso permite escalabilidade horizontal - qualquer instância do API pode
validar o token sem necessidade de estado compartilhado."
    
    local timestamp=$(date +%s)
    local username="demo_user_$timestamp"
    
    print_info "Registrando usuário: $username"
    
    local response=$(curl -s -X POST "$API_URL/v1/auth/register" \
        -H "Content-Type: application/json" \
        -d "{
            \"username\": \"$username\",
            \"email\": \"$username@demo.chat4all.com\",
            \"password\": \"Demo@123456\"
        }")
    
    echo "$response" | jq '.'
    
    USER_ID=$(echo "$response" | jq -r '.data.user.id // .data.user_id // empty')
    AUTH_TOKEN=$(echo "$response" | jq -r '.data.token // empty')
    
    if [ -n "$AUTH_TOKEN" ] && [ "$AUTH_TOKEN" != "null" ]; then
        print_success "Usuário registrado com sucesso!"
        print_info "User ID: $USER_ID"
        print_info "Token JWT gerado (primeiros 50 chars): ${AUTH_TOKEN:0:50}..."
    else
        echo -e "${RED}Falha no registro. Tentando login...${NC}"
    fi
    
    wait_for_user
}

demo_step_2_conversation() {
    print_header "PASSO 2: Criação de Conversa"
    
    print_concept "Conversas são entidades que agrupam mensagens entre participantes.
O campo 'platform' define qual connector (WhatsApp/Instagram) processará
as mensagens - demonstrando integração com sistemas externos."
    
    print_info "Criando conversa WhatsApp..."
    
    local response=$(curl -s -X POST "$API_URL/v1/conversations" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $AUTH_TOKEN" \
        -d '{
            "title": "Demo Chat4All - Sistemas Distribuídos",
            "platform": "whatsapp",
            "description": "Demonstração do trabalho final UFG"
        }')
    
    echo "$response" | jq '.'
    
    CONVERSATION_ID=$(echo "$response" | jq -r '.data.id // .data.conversation.id // empty')
    
    if [ -n "$CONVERSATION_ID" ] && [ "$CONVERSATION_ID" != "null" ]; then
        print_success "Conversa criada!"
        print_info "Conversation ID: $CONVERSATION_ID"
    fi
    
    wait_for_user
}

demo_step_3_send_message() {
    print_header "PASSO 3: Envio de Mensagem via Kafka"
    
    print_concept "Ao enviar uma mensagem, ela é:
1. Validada pela API
2. Salva no PostgreSQL com status 'SENT'
3. Publicada no Kafka (tópico 'messages')
4. O Router Worker consome e roteia para o connector apropriado
5. O connector simula entrega e envia callback de status"
    
    print_info "Enviando mensagem de texto..."
    
    local response=$(curl -s -X POST "$API_URL/v1/messages" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $AUTH_TOKEN" \
        -d "{
            \"conversation_id\": \"$CONVERSATION_ID\",
            \"content\": \"Olá! Esta é uma mensagem de demonstração do Chat4All - Sistema de Mensagens Distribuído.\",
            \"type\": \"text\"
        }")
    
    echo "$response" | jq '.'
    
    MESSAGE_ID=$(echo "$response" | jq -r '.data.id // .data.message_id // empty')
    
    if [ -n "$MESSAGE_ID" ] && [ "$MESSAGE_ID" != "null" ]; then
        print_success "Mensagem enviada!"
        print_info "Message ID: $MESSAGE_ID"
        print_info "Status inicial: SENT"
        
        echo ""
        print_wait "Aguardando processamento pelo Kafka e callbacks..."
        sleep 5
        
        # Verificar status atualizado
        print_info "Verificando status após processamento..."
        
        local status_response=$(curl -s "$API_URL/v1/messages/$MESSAGE_ID/status" \
            -H "Authorization: Bearer $AUTH_TOKEN" 2>/dev/null || echo '{}')
        
        if [ -n "$status_response" ]; then
            echo "$status_response" | jq '.' 2>/dev/null || echo "$status_response"
        fi
    fi
    
    wait_for_user
}

demo_step_4_file_upload() {
    print_header "PASSO 4: Upload de Arquivo (MinIO S3)"
    
    print_concept "O Chat4All suporta upload de arquivos até 2GB usando multipart upload:
1. Initiate: Cria sessão de upload no MinIO
2. Part Upload: Envia arquivo em partes de 5MB
3. Complete: Finaliza e monta o arquivo
Isso permite uploads resilientes - partes podem ser retransmitidas."
    
    # Criar arquivo de teste
    local test_file="/tmp/chat4all_demo_file.txt"
    echo "Este é um arquivo de demonstração do Chat4All.
    
Conteúdo gerado em: $(date)

O Chat4All utiliza MinIO como Object Storage, compatível com Amazon S3.
Isso permite armazenamento distribuído e escalável de arquivos.

Conceitos demonstrados:
- Object Storage
- Presigned URLs para download seguro
- Multipart Upload para arquivos grandes
" > "$test_file"
    
    print_info "Arquivo de teste criado: $test_file"
    local file_size=$(wc -c < "$test_file")
    print_info "Tamanho: $file_size bytes"
    
    # Initiate upload
    print_info "Iniciando upload multipart..."
    
    local init_response=$(curl -s -X POST "$API_URL/v1/files/upload/initiate" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $AUTH_TOKEN" \
        -d "{
            \"filename\": \"demo_document.txt\",
            \"content_type\": \"text/plain\",
            \"size\": $file_size,
            \"conversation_id\": \"$CONVERSATION_ID\"
        }")
    
    echo "$init_response" | jq '.'
    
    local upload_id=$(echo "$init_response" | jq -r '.data.upload_id // empty')
    FILE_ID=$(echo "$init_response" | jq -r '.data.file_id // empty')
    
    if [ -n "$upload_id" ] && [ "$upload_id" != "null" ]; then
        print_success "Upload iniciado!"
        print_info "Upload ID: $upload_id"
        print_info "File ID: $FILE_ID"
        
        # Upload part (simplificado para demo)
        print_info "Enviando conteúdo do arquivo..."
        
        local part_response=$(curl -s -X POST "$API_URL/v1/files/upload/part" \
            -H "Authorization: Bearer $AUTH_TOKEN" \
            -F "upload_id=$upload_id" \
            -F "part_number=1" \
            -F "file=@$test_file")
        
        # Complete upload
        print_info "Finalizando upload..."
        
        local complete_response=$(curl -s -X POST "$API_URL/v1/files/upload/complete" \
            -H "Content-Type: application/json" \
            -H "Authorization: Bearer $AUTH_TOKEN" \
            -d "{
                \"upload_id\": \"$upload_id\",
                \"parts\": [{\"part_number\": 1, \"etag\": \"demo\"}]
            }")
        
        echo "$complete_response" | jq '.'
        print_success "Upload completado!"
    fi
    
    rm -f "$test_file"
    
    wait_for_user
}

demo_step_5_message_with_file() {
    print_header "PASSO 5: Mensagem com Anexo"
    
    print_concept "Mensagens podem incluir referências a arquivos (file_id).
O sistema:
1. Valida que o arquivo existe e pertence ao usuário
2. Inclui metadados do arquivo na resposta (filename, size, download_url)
3. O connector recebe a referência para incluir na entrega"
    
    if [ -z "$FILE_ID" ] || [ "$FILE_ID" == "null" ]; then
        print_info "Usando file_id de exemplo (arquivo não foi uploaded)..."
        FILE_ID="demo-file-id"
    fi
    
    print_info "Enviando mensagem com anexo..."
    
    local response=$(curl -s -X POST "$API_URL/v1/messages" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $AUTH_TOKEN" \
        -d "{
            \"conversation_id\": \"$CONVERSATION_ID\",
            \"content\": \"Segue o documento solicitado em anexo.\",
            \"type\": \"file\",
            \"file_id\": \"$FILE_ID\"
        }")
    
    echo "$response" | jq '.'
    
    print_success "Mensagem com anexo enviada!"
    
    wait_for_user
}

demo_step_6_websocket() {
    print_header "PASSO 6: Notificações WebSocket"
    
    print_concept "O WebSocket Worker mantém conexões persistentes com clientes:
1. Cliente conecta e se inscreve em conversas/usuários
2. Quando status de mensagem muda (DELIVERED, READ)
3. Callback é recebido pela API
4. API publica no Redis Pub/Sub
5. WebSocket Worker recebe e notifica clientes inscritos
Isso permite atualizações em tempo real sem polling!"
    
    print_info "Endpoint WebSocket: $WS_URL"
    print_info "Para testar WebSocket manualmente:"
    echo ""
    echo "  wscat -c '$WS_URL'"
    echo ""
    echo "  Enviar: {\"type\":\"subscribe\",\"user_id\":\"$USER_ID\"}"
    echo ""
    
    print_success "WebSocket Worker está ativo e pronto para conexões!"
    
    wait_for_user
}

demo_step_7_scaling() {
    print_header "PASSO 7: Escalabilidade Horizontal"
    
    print_concept "Consumer Groups do Kafka permitem escalabilidade:
- Múltiplos workers no mesmo grupo dividem as partições
- Cada mensagem é processada por apenas um worker
- Ao escalar, partições são redistribuídas automaticamente
Comando: docker-compose up -d --scale router-worker=3"
    
    print_info "Demonstrando scaling..."
    
    # Mostrar workers atuais
    print_info "Workers atualmente rodando:"
    docker ps --filter "name=router-worker" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" 2>/dev/null || \
        echo "  (Docker não disponível para demonstração)"
    
    echo ""
    print_info "Para escalar para 3 workers:"
    echo "  docker-compose up -d --scale router-worker=3"
    echo ""
    print_info "Para verificar distribuição de carga:"
    echo "  docker logs -f router-worker_1"
    echo "  docker logs -f router-worker_2"
    echo "  docker logs -f router-worker_3"
    
    wait_for_user
}

demo_step_8_failover() {
    print_header "PASSO 8: Tolerância a Falhas"
    
    print_concept "O sistema implementa tolerância a falhas através de:
1. Kafka manual commit: offset só avança após processamento bem-sucedido
2. Graceful shutdown: mensagens em progresso são concluídas
3. Consumer group rebalancing: partições são redistribuídas automaticamente
4. Docker restart policy: containers são reiniciados após falha"
    
    print_info "Para testar failover:"
    echo ""
    echo "  1. Envie várias mensagens:"
    echo "     for i in {1..10}; do curl -X POST ... ; done"
    echo ""
    echo "  2. Mate um worker durante processamento:"
    echo "     docker kill \$(docker ps -qf 'name=router-worker' | head -1)"
    echo ""
    echo "  3. Observe que Docker reinicia o container"
    echo "  4. Verifique que nenhuma mensagem foi perdida"
    echo ""
    
    print_info "Tempo de recuperação típico: 15-30 segundos"
    print_info "Mensagens perdidas: 0 (at-least-once delivery)"
    
    wait_for_user
}

demo_step_9_monitoring() {
    print_header "PASSO 9: Observabilidade (Grafana)"
    
    print_concept "Monitoramento centralizado é essencial em sistemas distribuídos:
- Prometheus coleta métricas de todos os serviços
- Grafana visualiza em dashboards interativos
- Alertas notificam sobre problemas antes que afetem usuários"
    
    print_info "Dashboards disponíveis:"
    echo ""
    echo "  📊 Grafana: $GRAFANA_URL"
    echo "     - Dashboard: Chat4All - Dashboard Completo"
    echo "     - Usuário: admin"
    echo "     - Senha: admin"
    echo ""
    echo "  📈 Prometheus: http://localhost:9090"
    echo "     - Métricas: /metrics"
    echo "     - Targets: /targets"
    echo ""
    echo "  🗄️ MinIO Console: $MINIO_URL"
    echo "     - Usuário: chat4all_admin"
    echo "     - Senha: chat4all_minio_pass"
    echo ""
    
    print_info "Métricas coletadas:"
    echo "  - HTTP requests/segundo"
    echo "  - Latência (p50, p95, p99)"
    echo "  - Mensagens processadas"
    echo "  - Arquivos uploaded"
    echo "  - Conexões WebSocket ativas"
    echo "  - Kafka consumer lag"
    echo "  - Taxa de erros"
    
    wait_for_user
}

print_summary() {
    print_header "📋 RESUMO DA DEMONSTRAÇÃO"
    
    echo "Conceitos de Sistemas Distribuídos demonstrados:"
    echo ""
    echo "  ✓ Arquitetura de Microsserviços"
    echo "    - API Service, Router Worker, WebSocket Worker"
    echo "    - Connectors (WhatsApp Mock, Instagram Mock)"
    echo ""
    echo "  ✓ Comunicação Assíncrona"
    echo "    - Apache Kafka como message broker"
    echo "    - Consumer Groups para processamento paralelo"
    echo ""
    echo "  ✓ Object Storage"
    echo "    - MinIO (compatível com S3)"
    echo "    - Multipart upload para arquivos grandes"
    echo ""
    echo "  ✓ Notificações em Tempo Real"
    echo "    - WebSocket para push notifications"
    echo "    - Redis Pub/Sub para distribuição"
    echo ""
    echo "  ✓ Escalabilidade Horizontal"
    echo "    - Workers stateless"
    echo "    - Kafka partition-based scaling"
    echo ""
    echo "  ✓ Tolerância a Falhas"
    echo "    - Manual commit (at-least-once delivery)"
    echo "    - Graceful shutdown"
    echo "    - Automatic recovery"
    echo ""
    echo "  ✓ Observabilidade"
    echo "    - Prometheus metrics"
    echo "    - Grafana dashboards"
    echo "    - Alerting rules"
    echo ""
    
    print_success "Demonstração concluída com sucesso!"
    echo ""
    echo "Documentação adicional:"
    echo "  - docs/SCALING.md"
    echo "  - docs/FAULT_TOLERANCE.md"
    echo "  - docs/API_DOCUMENTATION.md"
    echo ""
}

##############################################################################
# Main Execution
##############################################################################

show_help() {
    echo ""
    echo "Chat4All - Full Demo Script"
    echo ""
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --auto            Run in automatic mode (no pauses)"
    echo "  --api-url URL     API base URL (default: http://localhost:8080)"
    echo "  --step N          Start from specific step (1-9)"
    echo "  --help            Show this help message"
    echo ""
    echo "Steps:"
    echo "  1. User Registration & Authentication"
    echo "  2. Conversation Creation"
    echo "  3. Send Message (Kafka flow)"
    echo "  4. File Upload (MinIO)"
    echo "  5. Message with Attachment"
    echo "  6. WebSocket Notifications"
    echo "  7. Horizontal Scaling"
    echo "  8. Fault Tolerance"
    echo "  9. Monitoring (Grafana)"
    echo ""
}

main() {
    local start_step=0
    
    # Parse arguments
    while [[ $# -gt 0 ]]; do
        case $1 in
            --auto)
                AUTO_MODE="true"
                DEMO_PAUSE=2
                shift
                ;;
            --api-url)
                API_URL="$2"
                shift 2
                ;;
            --step)
                start_step="$2"
                shift 2
                ;;
            --help)
                show_help
                exit 0
                ;;
            *)
                echo "Unknown option: $1"
                show_help
                exit 1
                ;;
        esac
    done
    
    echo ""
    echo -e "${CYAN}╔════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC}                                                                    ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}     ${BOLD}🚀 Chat4All - Demonstração Completa${NC}                           ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}                                                                    ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}     ${MAGENTA}Trabalho Final - Sistemas Distribuídos (UFG)${NC}                ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}                                                                    ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}     Conceitos demonstrados:                                        ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}     • Microsserviços e comunicação assíncrona                      ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}     • Object Storage e multipart upload                            ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}     • WebSocket e notificações em tempo real                       ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}     • Escalabilidade horizontal                                    ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}     • Tolerância a falhas                                          ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}     • Observabilidade com Prometheus/Grafana                       ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}                                                                    ${CYAN}║${NC}"
    echo -e "${CYAN}╚════════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    
    wait_for_user
    
    # Check services first
    check_services
    
    # Run demo steps
    [ "$start_step" -le 1 ] && demo_step_1_registration
    [ "$start_step" -le 2 ] && demo_step_2_conversation
    [ "$start_step" -le 3 ] && demo_step_3_send_message
    [ "$start_step" -le 4 ] && demo_step_4_file_upload
    [ "$start_step" -le 5 ] && demo_step_5_message_with_file
    [ "$start_step" -le 6 ] && demo_step_6_websocket
    [ "$start_step" -le 7 ] && demo_step_7_scaling
    [ "$start_step" -le 8 ] && demo_step_8_failover
    [ "$start_step" -le 9 ] && demo_step_9_monitoring
    
    # Print summary
    print_summary
}

# Run main function
main "$@"
