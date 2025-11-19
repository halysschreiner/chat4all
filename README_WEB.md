# 💬 Chat4All - Interface Web

## 🌐 Acesse a Interface Web

**URL:** http://localhost:9000

## ✨ Características

- 🎨 Interface moderna e responsiva
- 💬 Chat em tempo real com polling automático
- 👥 Suporta múltiplos usuários simultâneos
- 🔄 Atualização automática de mensagens (3 segundos)
- 🎯 Status de mensagens (Enviado/Entregue)
- 🔐 Autenticação JWT
- 📱 Design responsivo para mobile

## 🚀 Início Rápido

### 1. Iniciar os Serviços

```bash
cd /home/halys/projects/ufg/sd/chat4all-gemini
./scripts/start.sh
```

### 2. Acessar a Interface

Abra seu navegador em: **http://localhost:9000**

### 3. Fazer Login

**Credenciais disponíveis:**
- **Alice**: alice@chat4all.com / password123
- **Bob**: bob@chat4all.com / password123

## 👥 Testando com 2 Pessoas

### Você e um Amigo na Mesma Rede

1. **Descubra seu IP local:**
   ```bash
   hostname -I | awk '{print $1}'
   ```
   Exemplo: `192.168.1.100`

2. **Você acessa:**
   ```
   http://localhost:9000
   ```
   E faz login como **Alice**

3. **Seu amigo acessa:**
   ```
   http://192.168.1.100:9000
   ```
   E faz login como **Bob**

4. **Troquem mensagens em tempo real!** 🎉

### Testando Sozinho (2 navegadores)

- **Chrome**: `http://localhost:9000` → Login como Alice
- **Firefox**: `http://localhost:9000` → Login como Bob
- Ou use **aba anônima** no mesmo navegador

## 📚 Documentação Completa

Para mais detalhes, consulte:
- **[Guia da Interface Web](docs/WEB_INTERFACE.md)** - Tutorial completo
- **[Documentação da API](docs/API_DOCUMENTATION.md)** - Endpoints REST
- **[Quick Start](docs/QUICKSTART.md)** - Primeiros passos

## 🏗️ Arquitetura

```
┌──────────────┐
│   Navegador  │ ← Interface Web (HTML/CSS/JS)
│ localhost:9000│
└──────┬───────┘
       │ HTTP + CORS
       ▼
┌──────────────┐
│   API REST   │ ← Slim Framework PHP
│ localhost:8080│
└──────┬───────┘
       │
       ├─────► PostgreSQL (Dados)
       ├─────► Kafka (Eventos)
       └─────► Redis (Cache)
```

## 🎯 Funcionalidades Implementadas

- ✅ Autenticação JWT
- ✅ Envio de mensagens
- ✅ Listagem de mensagens
- ✅ Atualização em tempo real (polling)
- ✅ Status de mensagens (SENT → DELIVERED)
- ✅ Interface web responsiva
- ✅ CORS habilitado
- ✅ Dockerizado (7 containers)

## 🛠️ Stack Tecnológica

### Backend
- **PHP 8.3** - Linguagem principal
- **Slim Framework 4** - REST API
- **PostgreSQL 16** - Banco de dados
- **Apache Kafka** - Event streaming
- **Redis 7** - Cache

### Frontend
- **HTML5** - Estrutura
- **CSS3** - Estilização moderna
- **JavaScript (Vanilla)** - Lógica da aplicação
- **Nginx** - Servidor web

### Infraestrutura
- **Docker & Docker Compose** - Containerização
- **Zookeeper** - Coordenação do Kafka

## 📦 Serviços Docker

```bash
docker-compose ps
```

| Container | Porta | Descrição |
|-----------|-------|-----------|
| chat4all-web | 9000 | Interface web |
| chat4all-api | 8080 | API REST |
| chat4all-router-worker | - | Worker Kafka |
| chat4all-postgres | 5432 | Banco de dados |
| chat4all-redis | 6379 | Cache |
| chat4all-kafka | 9092/9093 | Message broker |
| chat4all-zookeeper | 2181 | Coordenação |

## 🔧 Scripts Úteis

```bash
# Iniciar tudo
./scripts/start.sh

# Parar tudo
./scripts/stop.sh

# Testar API
./scripts/test-api.sh

# Ver logs
docker-compose logs -f api-service
docker-compose logs -f router-worker
docker-compose logs -f web
```

## 🐛 Troubleshooting

### Interface não carrega
```bash
docker-compose restart web
docker-compose logs web
```

### API não responde
```bash
curl http://localhost:8080/health
docker-compose restart api-service
```

### Mensagens não aparecem
```bash
# Verificar worker
docker-compose logs router-worker

# Verificar Kafka
docker-compose ps kafka
```

## 📝 Licença

Projeto acadêmico - Universidade Federal de Goiás
Disciplina: Sistemas Distribuídos
