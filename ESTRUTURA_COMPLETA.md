# 📁 Estrutura Completa do Projeto Chat4All v2

## Checklist de Arquivos

Use este documento para verificar se todos os arquivos foram criados corretamente.

### ✅ Raiz do Projeto

```
chat4all-v2/
├── [ ] docker-compose.yml
├── [ ] Makefile
├── [ ] .gitignore
├── [ ] README.md
├── [ ] QUICKSTART.md
└── [ ] ESTRUTURA_COMPLETA.md (este arquivo)
```

### ✅ Scripts

```
scripts/
├── [ ] init-db.sql
├── [ ] setup.sh (chmod +x)
└── [ ] test-api.sh (chmod +x)
```

### ✅ Shared (Protobuf)

```
shared/
└── proto/
    ├── [ ] auth.proto
    ├── [ ] conversation.proto
    └── [ ] message.proto
```

**Nota**: A pasta `shared/generated/` será criada automaticamente pelo `setup.sh`

### ✅ Auth Service

```
services/auth-service/
├── [ ] Dockerfile
├── [ ] composer.json
└── src/
    └── [ ] Server.php
```

### ✅ Message Service

```
services/message-service/
├── [ ] Dockerfile
├── [ ] composer.json
└── src/
    └── [ ] Server.php
```

### ✅ Conversation Service

```
services/conversation-service/
├── [ ] Dockerfile
├── [ ] composer.json
└── src/
    └── [ ] Server.php
```

### ✅ API Gateway

```
api-gateway/
├── [ ] Dockerfile
├── [ ] composer.json
└── public/
    └── [ ] index.php
```

### ✅ Frontend

```
frontend/
├── [ ] Dockerfile
├── [ ] package.json
├── [ ] angular.json
├── [ ] tsconfig.json
├── src/
│   ├── [ ] index.html
│   ├── [ ] main.ts
│   └── app/
│       ├── [ ] app.module.ts
│       ├── [ ] app.component.ts
│       ├── [ ] app.component.html
│       └── [ ] app.component.css
```

---

## 🚀 Ordem de Execução

Siga esta ordem para configurar o projeto:

### 1️⃣ Criar Estrutura de Pastas

```bash
mkdir -p chat4all-v2/{scripts,shared/proto,api-gateway/public,frontend/src/app}
mkdir -p chat4all-v2/services/{auth-service/src,message-service/src,conversation-service/src}
cd chat4all-v2
```

### 2️⃣ Criar Arquivos da Raiz

Crie os seguintes arquivos na raiz:
- `docker-compose.yml` ✅
- `Makefile` ✅
- `.gitignore` ✅
- `README.md` ✅
- `QUICKSTART.md` ✅

### 3️⃣ Criar Scripts

Na pasta `scripts/`:
- `init-db.sql` ✅
- `setup.sh` ✅ (dar permissão: `chmod +x`)
- `test-api.sh` ✅ (dar permissão: `chmod +x`)

### 4️⃣ Criar Arquivos Protobuf

Na pasta `shared/proto/`:
- `auth.proto` ✅
- `conversation.proto` ✅
- `message.proto` ✅

### 5️⃣ Criar Serviços gRPC

Para cada serviço em `services/`:

**Auth Service:**
- `Dockerfile` ✅
- `composer.json` ✅
- `src/Server.php` ✅

**Message Service:**
- `Dockerfile` ✅
- `composer.json` ✅
- `src/Server.php` ✅

**Conversation Service:**
- `Dockerfile` ✅
- `composer.json` ✅
- `src/Server.php` ✅

### 6️⃣ Criar API Gateway

Na pasta `api-gateway/`:
- `Dockerfile` ✅
- `composer.json` ✅
- `public/index.php` ✅

### 7️⃣ Criar Frontend

Na pasta `frontend/`:
- `Dockerfile` ✅
- `package.json` ✅
- `angular.json` ✅
- `tsconfig.json` ✅

Na pasta `frontend/src/`:
- `index.html` ✅
- `main.ts` ✅

Na pasta `frontend/src/app/`:
- `app.module.ts` ✅
- `app.component.ts` ✅
- `app.component.html` ✅
- `app.component.css` ✅

### 8️⃣ Executar Setup

```bash
# Dar permissões aos scripts
chmod +x scripts/*.sh

# Executar setup (gera código gRPC)
./scripts/setup.sh

# OU usando Makefile
make setup
```

### 9️⃣ Subir Containers

```bash
# Usando docker-compose
docker-compose up --build

# OU usando Makefile
make up-logs
```

### 🔟 Testar

```bash
# Testar API
./scripts/test-api.sh

# OU
make test

# Acessar frontend
# Abra http://localhost:4200 no navegador
```

---

## ⚠️ Problemas Comuns e Soluções

### Problema 1: "protoc: command not found"

**Solução:**
```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install -y protobuf-compiler

# macOS
brew install protobuf

# Verificar instalação
protoc --version
```

### Problema 2: "grpc_php_plugin not found"

**Solução:**
```bash
# Instalar extensão gRPC
pecl install grpc

# Verificar
which grpc_php_plugin
```

### Problema 3: "Port 8080 already in use"

**Solução:**
Edite `docker-compose.yml` e mude a porta:
```yaml
api-gateway:
  ports:
    - "8081:80"  # Use 8081 ao invés de 8080
```

### Problema 4: Frontend não compila

**Solução:**
```bash
cd frontend
npm install
npm run build
```

### Problema 5: Serviços gRPC não iniciam

**Solução:**
```bash
# Ver logs
docker-compose logs auth-service

# Reconstruir
docker-compose down
docker-compose up --build
```

### Problema 6: Banco de dados não inicializa

**Solução:**
```bash
# Aguarde 30 segundos após `docker-compose up`
# Verifique logs
docker-compose logs postgres

# Se necessário, reinicie
docker-compose restart postgres
```

---

## 🧪 Testes Manuais

### Teste 1: Serviços Rodando

```bash
# Verificar todos os containers
docker-compose ps

# Deve mostrar:
# - postgres (healthy)
# - redis (healthy)
# - auth-service (running)
# - message-service (running)
# - conversation-service (running)
# - api-gateway (running)
# - frontend (running)
```

### Teste 2: Banco de Dados

```bash
# Acessar PostgreSQL
docker exec -it chat4all_postgres psql -U chat4all -d chat4all

# Comandos de teste:
\dt                           # Listar tabelas
SELECT COUNT(*) FROM users;   # Deve retornar 3 (alice, bob, charlie)
\q                            # Sair
```

### Teste 3: API Gateway

```bash
# Teste simples
curl http://localhost:8080

# Deve retornar HTML ou erro 404 (significa que está rodando)
```

### Teste 4: Frontend

```bash
# Acesse no navegador
http://localhost:4200

# Deve ver a tela de login
```

### Teste 5: Fluxo Completo

1. Registrar usuário
2. Fazer login
3. Criar conversa privada
4. Enviar mensagem
5. Verificar status da mensagem

Use o script:
```bash
make test
```

---

## 📊 Verificação de Saúde

Execute periodicamente:

```bash
# Usando Makefile
make health

# Deve mostrar:
# Frontend:    ✓ OK
# API Gateway: ✓ OK
# PostgreSQL:  ✓ OK
# Redis:       ✓ OK
```

---

## 🎓 Para Apresentação

### Pontos a Demonstrar

1. **Arquitetura**
   - Mostrar docker-compose.yml
   - Explicar microserviços
   - Mostrar comunicação gRPC

2. **Banco de Dados**
   - Mostrar schema PostgreSQL
   - Explicar relacionamentos

3. **API**
   - Rodar script de teste
   - Mostrar requests/responses

4. **Frontend**
   - Mostrar interface
   - Fazer demo ao vivo

5. **Status de Mensagens**
   - Demonstrar transição: Enviada → Entregue → Lida
   - Mostrar em tempo real (dois navegadores)

### Comandos Úteis Durante Apresentação

```bash
# Ver logs em tempo real
make logs

# Verificar saúde
make health

# Acessar banco
make db

# Rodar testes
make test
```

---

## ✅ Checklist Final

Antes de entregar, verificar:

- [ ] Todos os arquivos criados
- [ ] `docker-compose up` funciona
- [ ] Todos os 7 containers sobem
- [ ] Frontend acessível (localhost:4200)
- [ ] API acessível (localhost:8080)
- [ ] Banco populado (3 usuários de teste)
- [ ] Script de teste funciona (`make test`)
- [ ] README.md completo
- [ ] QUICKSTART.md criado
- [ ] Screenshots/vídeo do sistema funcionando

---

## 📚 Arquivos de Documentação

- `README.md` - Documentação completa
- `QUICKSTART.md` - Guia rápido de início
- `ESTRUTURA_COMPLETA.md` - Este arquivo (checklist)

---

## 🎉 Conclusão

Se todos os itens acima estiverem marcados, seu projeto está pronto para entrega!

**Comandos finais:**

```bash
# 1. Setup
make setup

# 2. Subir tudo
make up-logs

# 3. Testar
make test

# 4. Verificar saúde
make health
```

**Boa sorte! 🚀**