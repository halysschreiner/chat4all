# 🚀 Quick Start - Chat4All v2

Guia rápido para colocar o projeto no ar em 5 minutos!

## ⚡ Setup Rápido

### 1. Clone e entre no diretório
```bash
cd chat4all-v2
```

### 2. Execute o script de setup
```bash
chmod +x scripts/setup.sh
./scripts/setup.sh
```

### 3. Suba os containers
```bash
docker-compose up --build
```

Aguarde aparecer:
```
✓ auth-service rodando
✓ message-service rodando  
✓ conversation-service rodando
✓ api-gateway pronto
✓ frontend pronto
```

### 4. Acesse a aplicação
Abra: **http://localhost:4200**

---

## 🎮 Teste Rápido

### Via Interface Web

1. **Registre-se**
   - Username: `seu_nome`
   - Email: `seu@email.com`
   - Senha: qualquer uma

2. **Ou use usuário pré-existente**
   - Email: `alice@chat4all.com`
   - Senha: `password`

3. **Crie uma conversa**
   - Clique "+ Nova Conversa"
   - Para conversa privada: cole o UUID de outro usuário
   - Para grupo: coloque nome e UUIDs separados por vírgula

4. **Envie mensagens**
   - Selecione a conversa
   - Digite e envie
   - Observe os status: 📤 → ✓ → ✓✓

### Via API (curl)

Execute o script de teste:
```bash
chmod +x scripts/test-api.sh
./scripts/test-api.sh
```

Isso testa todos os endpoints automaticamente!

---

## 📋 Checklist de Funcionalidades

Teste cada item:

- [ ] ✅ Registrar usuário
- [ ] ✅ Login
- [ ] ✅ Criar conversa privada
- [ ] ✅ Criar grupo
- [ ] ✅ Enviar mensagem
- [ ] ✅ Ver status (ENVIADA/ENTREGUE/LIDA)
- [ ] ✅ Marcar como lida
- [ ] ✅ Listar conversas
- [ ] ✅ Listar mensagens

---

## 🐛 Problemas Comuns

### Porta 8080 em uso
```bash
# Mude no docker-compose.yml:
ports:
  - "8081:80"  # Use 8081 ao invés de 8080
```

### Serviços não iniciam
```bash
# Limpe tudo e reconstrua:
docker-compose down -v
docker-compose up --build
```

### Frontend não carrega
```bash
# Verifique se rodou o build:
cd frontend
npm install
npm run build
```

### Erro "protoc not found"
```bash
# Ubuntu/Debian:
sudo apt-get install -y protobuf-compiler

# macOS:
brew install protobuf
```

---

## 📊 Verificar Status

### Ver logs de todos os serviços
```bash
docker-compose logs -f
```

### Ver log específico
```bash
docker-compose logs -f auth-service
docker-compose logs -f message-service
docker-compose logs -f api-gateway
```

### Verificar banco de dados
```bash
# PostgreSQL
docker exec -it chat4all_postgres psql -U chat4all -d chat4all

# Comandos úteis:
\dt                    # Listar tabelas
SELECT * FROM users;   # Ver usuários
SELECT * FROM messages ORDER BY created_at DESC LIMIT 5;  # Últimas mensagens
\q                     # Sair
```

### Verificar Redis
```bash
docker exec -it chat4all_redis redis-cli

# Comandos úteis:
KEYS *                 # Listar todas as chaves
GET session:TOKEN      # Ver sessão
QUIT                   # Sair
```

---

## 🎯 IDs Importantes

Após rodar `test-api.sh`, você terá:

```
Alice ID: [UUID]
Bob ID: [UUID]
Charlie ID: [UUID]
```

**Copie esses IDs para criar conversas no frontend!**

---

## 🛑 Parar Tudo

```bash
# Parar containers
docker-compose down

# Parar e remover dados
docker-compose down -v
```

---

## 📱 Testando Multi-Usuário

1. **Janela 1**: Login como Alice
   - Crie uma conversa privada com Bob (use ID dele)
   - Envie mensagem

2. **Janela 2 (navegador anônimo)**: Login como Bob
   - Veja a conversa aparecer
   - Marque como lida
   - Envie resposta

3. **Janela 1**: Veja o status mudar para ✓✓ (lida)

---

## 🎓 Para o Professor

### Demonstração Completa

1. **Autenticação**: Registrar novo usuário e fazer login
2. **Conversas Privadas**: Criar chat 1-1
3. **Grupos**: Criar grupo com múltiplos membros
4. **Mensagens**: Enviar e receber
5. **Status**: Mostrar evolução ENVIADA → ENTREGUE → LIDA
6. **Persistência**: Fechar e abrir navegador, dados continuam lá
7. **Arquitetura**: Mostrar logs dos serviços gRPC comunicando

### Endpoints para Testar

```bash
# Health check
curl http://localhost:8080/api/health

# Registro
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"username":"test","email":"test@test.com","password":"123"}'

# Login
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","password":"123"}'
```

---

## ✅ Checklist para Entrega

- [ ] README.md completo
- [ ] docker-compose.yml funcional
- [ ] Todos os serviços sobem corretamente
- [ ] Frontend acessível em localhost:4200
- [ ] API acessível em localhost:8080
- [ ] Banco de dados com schema correto
- [ ] 3 usuários de teste pré-criados
- [ ] Logs dos serviços gRPC visíveis
- [ ] Screenshot ou vídeo do sistema funcionando

---

**Boa sorte com a entrega! 🎉**