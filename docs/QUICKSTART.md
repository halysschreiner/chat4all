# 🚀 Guia de Início Rápido - Chat4All

Este guia mostra como executar o Chat4All em **menos de 5 minutos**.

---

## ✅ Pré-requisitos

Você precisa ter instalado:

- **Docker** (versão 20.10 ou superior)
- **Docker Compose** (versão 2.0 ou superior)
- **curl** (para testes)
- **jq** (opcional, para formatação JSON)

### Verificar Instalação

```bash
docker --version
docker-compose --version
curl --version
```

---

## 📦 Passo 1: Clonar/Acessar o Projeto

```bash
cd chat4all
```

---

## 🔧 Passo 2: Dar Permissões aos Scripts

```bash
chmod +x scripts/*.sh
```

---

## 🚀 Passo 3: Iniciar o Sistema

```bash
./scripts/start.sh
```

Este script irá:
1. ✅ Construir as imagens Docker
2. ✅ Iniciar PostgreSQL, Redis, Kafka e Zookeeper
3. ✅ Aguardar serviços ficarem prontos
4. ✅ Iniciar API Service e Router Worker
5. ✅ Exibir informações de acesso

**Aguarde até ver:**

```
================================================
  ✅ Chat4All iniciado com sucesso!
================================================

📋 Serviços disponíveis:

  🌐 API Service:       http://localhost:8080
  🗄️  PostgreSQL:        localhost:5432
  📦 Redis:             localhost:6379
  📨 Kafka:             localhost:9092
```

---

## 🧪 Passo 4: Testar a API

### Opção A: Script Automatizado (Recomendado)

```bash
./scripts/test-api.sh
```

Este script testa automaticamente:
- ✅ Health check
- ✅ Login e autenticação
- ✅ Envio de mensagens
- ✅ Listagem de mensagens
- ✅ Listagem de conversas

### Opção B: Teste Manual

```bash
# 1. Fazer login
curl -X POST http://localhost:8080/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "alice@chat4all.com",
    "password": "password123"
  }'

# 2. Copiar o token da resposta e usar nos próximos comandos
# Substitua <SEU_TOKEN> pelo token recebido

# 3. Enviar mensagem
curl -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer <SEU_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "Olá! Esta é minha primeira mensagem!"
  }'

# 4. Aguardar 2 segundos para processamento
sleep 2

# 5. Listar mensagens
curl -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages" \
  -H "Authorization: Bearer <SEU_TOKEN>"
```

---

## 📚 Passo 5: Explorar a Documentação

### Documentação Completa

- **[API_DOCUMENTATION.md](API_DOCUMENTATION.md)** - Documentação completa da API
- **[EXAMPLES.md](EXAMPLES.md)** - Exemplos práticos de uso

### Endpoints Disponíveis

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | /health | Verificar saúde do serviço |
| POST | /v1/auth/login | Fazer login e obter token JWT |
| POST | /v1/messages | Enviar mensagem |
| GET | /v1/conversations/{id}/messages | Listar mensagens de uma conversa |
| GET | /v1/conversations | Listar conversas do usuário |

### Usuários de Teste

| Email | Senha | Username |
|-------|-------|----------|
| alice@chat4all.com | password123 | alice |
| bob@chat4all.com | password123 | bob |

---

## 🔍 Ver Logs em Tempo Real

```bash
# Todos os serviços
docker-compose logs -f

# Apenas API
docker-compose logs -f api-service

# Apenas Worker
docker-compose logs -f router-worker
```

**Pressione Ctrl+C para sair dos logs**

---

## 🛑 Parar o Sistema

```bash
./scripts/stop.sh
```

Para remover também os dados persistentes (volumes):

```bash
docker-compose down -v
```

---

## 🐛 Problemas Comuns

### Erro: "Cannot connect to the Docker daemon"

**Solução:** Inicie o Docker Desktop ou o serviço Docker:

```bash
# Linux
sudo systemctl start docker

# macOS/Windows
# Abra o Docker Desktop
```

### Erro: "Port already in use"

**Solução:** Alguma porta já está em uso. Verifique:

```bash
# Ver portas em uso
docker ps
lsof -i :8080  # API
lsof -i :5432  # PostgreSQL
lsof -i :9092  # Kafka

# Parar containers anteriores
docker-compose down
```

### Serviços não inicializam corretamente

**Solução:** Reconstruir do zero:

```bash
docker-compose down -v
docker-compose build --no-cache
./scripts/start.sh
```

### Banco de dados não inicializa

**Solução:** Verificar logs do PostgreSQL:

```bash
docker-compose logs postgres
```

---

## 📊 Verificar Status dos Serviços

```bash
# Ver status de todos os containers
docker-compose ps

# Verificar health check
curl http://localhost:8080/health
```

**Output esperado:**

```
NAME                    STATUS              PORTS
chat4all-api            Up (healthy)        0.0.0.0:8080->8080/tcp
chat4all-postgres       Up (healthy)        0.0.0.0:5432->5432/tcp
chat4all-redis          Up (healthy)        0.0.0.0:6379->6379/tcp
chat4all-kafka          Up (healthy)        0.0.0.0:9092->9092/tcp
chat4all-router-worker  Up                  
chat4all-zookeeper      Up (healthy)        2181/tcp
```

---

## 💡 Próximos Passos

Depois de testar a API básica, você pode:

1. **Explorar os Exemplos** em [EXAMPLES.md](EXAMPLES.md)
2. **Ver a Arquitetura Completa** no README.md principal
3. **Modificar o Código** e adicionar novas funcionalidades
4. **Monitorar o Sistema** usando os logs e banco de dados

---

## 📞 Precisa de Ajuda?

1. **Verifique os logs:** `docker-compose logs -f`
2. **Consulte a documentação:** [API_DOCUMENTATION.md](API_DOCUMENTATION.md)
3. **Veja os exemplos:** [EXAMPLES.md](EXAMPLES.md)
4. **Recrie o ambiente:**
   ```bash
   docker-compose down -v
   ./scripts/start.sh
   ```

---

## 🎉 Tudo Pronto!

Agora você tem um sistema de mensagens distribuído completo rodando localmente!

**URLs importantes:**

- 🌐 API: http://localhost:8080
- 📖 Health Check: http://localhost:8080/health

**Comandos úteis:**

```bash
# Iniciar
./scripts/start.sh

# Testar
./scripts/test-api.sh

# Ver logs
docker-compose logs -f

# Parar
./scripts/stop.sh
```

---

**Bom estudo e desenvolvimento! 🚀**
