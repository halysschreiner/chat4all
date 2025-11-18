# Chat4All v2 - Sistema de Mensageria Multi-Canal

Sistema de mensageria distribuído construído com arquitetura de microserviços, gRPC e PHP 8.4.

## 🚀 Funcionalidades Implementadas

### ✅ Primeira Entrega
- **Autenticação**
  - Registro de usuários
  - Login com JWT
  - Validação de tokens

- **Conversas**
  - Criar conversa privada (1-1)
  - Criar grupos
  - Listar conversas do usuário
  - Adicionar membros a grupos

- **Mensagens**
  - Enviar mensagens de texto
  - Listar mensagens de uma conversa
  - Visualização de status (ENVIADA, ENTREGUE, LIDA)
  - Marcar mensagens como lidas
  - Rastreamento de leitura por usuário

- **Frontend**
  - Interface web básica em Angular
  - Login/Registro
  - Lista de conversas
  - Chat em tempo real (polling)
  - Visualização de status das mensagens

## 🏗️ Arquitetura

```
┌─────────────┐
│   Frontend  │ (Angular)
│   :4200     │
└──────┬──────┘
       │ HTTP REST
       ↓
┌─────────────┐
│ API Gateway │ (PHP + Nginx)
│   :8080     │
└──────┬──────┘
       │ gRPC
       ↓
┌──────────────────────────────────┐
│     Microserviços (gRPC)         │
├──────────────────────────────────┤
│ - Auth Service      (:50051)     │
│ - Message Service   (:50052)     │
│ - Conversation Svc  (:50053)     │
└──────┬──────────────┬────────────┘
       │              │
       ↓              ↓
┌──────────┐   ┌─────────┐
│PostgreSQL│   │  Redis  │
│  :5432   │   │  :6379  │
└──────────┘   └─────────┘
```

## 📋 Pré-requisitos

- Docker 20.10+
- Docker Compose 2.0+
- 4GB RAM disponível
- Portas livres: 4200, 8080, 5432, 6379, 50051-50053

## 🔧 Instalação

### 1. Clonar o repositório
```bash
git clone <seu-repositorio>
cd chat4all-v2
```

### 2. Estrutura de pastas
Certifique-se de que a estrutura está assim:
```
chat4all-v2/
├── docker-compose.yml
├── scripts/
│   └── init-db.sql
├── api-gateway/
│   ├── Dockerfile
│   ├── composer.json
│   └── public/
│       └── index.php
├── services/
│   ├── auth-service/
│   │   ├── Dockerfile
│   │   ├── composer.json
│   │   └── src/
│   │       └── Server.php
│   ├── message-service/
│   │   ├── Dockerfile
│   │   ├── composer.json
│   │   └── src/
│   │       └── Server.php
│   └── conversation-service/
│       ├── Dockerfile
│       ├── composer.json
│       └── src/
│           └── Server.php
├── frontend/
│   ├── Dockerfile
│   └── src/
│       └── app/
│           ├── app.component.ts
│           ├── app.component.html
│           └── app.component.css
└── shared/
    └── proto/
        ├── auth.proto
        ├── conversation.proto
        └── message.proto
```

### 3. Gerar código gRPC (IMPORTANTE)

Antes de subir os containers, você precisa gerar o código PHP a partir dos arquivos `.proto`.

**Instalar protoc e grpc_php_plugin:**
```bash
# Ubuntu/Debian
sudo apt-get install -y protobuf-compiler
pecl install grpc protobuf

# macOS
brew install protobuf grpc
```

**Gerar código:**
```bash
# Para cada serviço, executar:
cd services/auth-service
protoc --proto_path=../../shared/proto \
       --php_out=generated \
       --grpc_out=generated \
       --plugin=protoc-gen-grpc=/usr/local/bin/grpc_php_plugin \
       ../../shared/proto/auth.proto

# Repetir para message.proto e conversation.proto
```

### 4. Subir os containers
```bash
docker-compose up --build
```

Aguarde até ver as mensagens:
```
auth_service          | Auth Service rodando na porta 50051
message_service       | Message Service rodando na porta 50052
conversation_service  | Conversation Service rodando na porta 50053
api_gateway           | API Gateway pronto
frontend              | Frontend servindo na porta 80
```

## 🎮 Como Usar

### 1. Acessar a aplicação
Abra o navegador em: **http://localhost:4200**

### 2. Registrar usuários
1. Clique em "Registre-se"
2. Preencha: username, email, senha
3. Clique em "Registrar"
4. Faça login com as credenciais

### 3. Criar conversas

**Para testar, você precisa de pelo menos 2 usuários:**

**Usuário 1 (Alice):**
- Email: `alice@chat4all.com`
- Senha: `password`
- User ID: (copie do console após login)

**Usuário 2 (Bob):**
- Email: `bob@chat4all.com`
- Senha: `password`
- User ID: (copie do console após login)

**Criar conversa privada:**
1. Login como Alice
2. Clique em "+ Nova Conversa"
3. Cole o User ID do Bob
4. Clique em "Criar"

**Criar grupo:**
1. Clique em "+ Nova Conversa"
2. Preencha o nome do grupo
3. Cole os User IDs separados por vírgula (ex: `id1,id2,id3`)
4. Clique em "Criar Grupo"

### 4. Enviar mensagens
1. Selecione uma conversa na lista
2. Digite a mensagem no campo inferior
3. Pressione Enter ou clique em "Enviar"
4. Observe o status da mensagem:
   - 📤 **Enviada**: Mensagem foi enviada
   - ✓ **Entregue**: Mensagem chegou ao servidor
   - ✓✓ **Lida**: Destinatário leu a mensagem

### 5. Marcar como lida
1. Quando receber uma mensagem (login como outro usuário)
2. Clique em "Marcar como lida"
3. O remetente verá que você leu (✓✓)

## 🧪 Testando a API diretamente

### Registrar usuário
```bash
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "username": "testuser",
    "email": "test@example.com",
    "password": "senha123"
  }'
```

### Login
```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "senha123"
  }'
```
**Copie o token retornado!**

### Criar conversa privada
```bash
curl -X POST http://localhost:8080/api/conversations/private \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer SEU_TOKEN_AQUI" \
  -d '{
    "other_user_id": "UUID_DO_OUTRO_USUARIO"
  }'
```

### Enviar mensagem
```bash
curl -X POST http://localhost:8080/api/messages/send \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer SEU_TOKEN_AQUI" \
  -d '{
    "conversation_id": "UUID_DA_CONVERSA",
    "message_type": "text",
    "content": "Olá, mundo!"
  }'
```

### Listar mensagens
```bash
curl -X GET "http://localhost:8080/api/conversations/UUID_DA_CONVERSA/messages?limit=50" \
  -H "Authorization: Bearer SEU_TOKEN_AQUI"
```

## 🔍 Verificar logs

```bash
# Todos os serviços
docker-compose logs -f

# Serviço específico
docker-compose logs -f auth-service
docker-compose logs -f message-service
docker-compose logs -f api-gateway
```

## 🗃️ Acessar banco de dados

```bash
# PostgreSQL
docker exec -it chat4all_postgres psql -U chat4all -d chat4all

# Queries úteis
SELECT * FROM users;
SELECT * FROM conversations;
SELECT * FROM messages ORDER BY created_at DESC LIMIT 10;
SELECT * FROM message_read_status;

# Redis
docker exec -it chat4all_redis redis-cli
KEYS *
GET session:TOKEN
```

## 🛑 Parar a aplicação

```bash
docker-compose down

# Remover volumes (apaga dados)
docker-compose down -v
```

## 🐛 Troubleshooting

### Erro: "Port already in use"
```bash
# Verificar portas em uso
sudo lsof -i :8080
sudo lsof -i :5432

# Parar processo ou mudar porta no docker-compose.yml
```

### Erro: "Connection refused" nos serviços gRPC
```bash
# Verificar se serviços estão rodando
docker-compose ps

# Reiniciar serviço específico
docker-compose restart auth-service
```

### Erro: "could not translate host name"
- Aguarde ~30 segundos para PostgreSQL inicializar
- Verifique logs: `docker-compose logs postgres`

### Frontend não carrega
```bash
# Verificar se está rodando
docker-compose logs frontend

# Rebuild
docker-compose up --build frontend
```

## 📚 Próximos Passos

Para futuras entregas, implementar:
- [ ] Upload de arquivos (MinIO)
- [ ] Event Sourcing com Kafka
- [ ] Connectors para WhatsApp/Telegram
- [ ] WebSockets para real-time
- [ ] Testes unitários e integração
- [ ] Monitoramento (Prometheus/Grafana)

## 📝 Notas Importantes

1. **Segurança**: Esta é uma implementação básica. Em produção:
   - Use HTTPS
   - Secrets management adequado
   - Validação robusta de inputs
   - Rate limiting

2. **Performance**: Implementação simplificada:
   - Polling ao invés de WebSockets
   - Sem paginação otimizada
   - Cache básico

3. **Dados de teste**: 3 usuários pré-criados:
   - alice@chat4all.com (senha: password)
   - bob@chat4all.com (senha: password)
   - charlie@chat4all.com (senha: password)

## 🤝 Contribuindo

Para contribuir com o projeto:
1. Fork o repositório
2. Crie uma branch (`git checkout -b feature/nova-funcionalidade`)
3. Commit suas mudanças (`git commit -am 'Adiciona nova funcionalidade'`)
4. Push para a branch (`git push origin feature/nova-funcionalidade`)
5. Abra um Pull Request

## 📄 Licença

Este projeto é um trabalho acadêmico da disciplina de Sistemas Distribuídos.

## 👥 Autor

Desenvolvido como projeto final da disciplina de Sistemas Distribuídos.

---

**Boa sorte com a primeira entrega! 🚀**