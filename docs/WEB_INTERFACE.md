# 💬 Interface Web Chat4All

## ✨ Funcionalidades

A interface web permite:
- **Login** com usuários Alice ou Bob
- **Envio de mensagens** em tempo real
- **Recebimento automático** de mensagens (polling a cada 3 segundos)
- **Status das mensagens** (Enviado, Entregue)
- **UI moderna e responsiva** com gradientes e animações

## 🚀 Como Acessar

### 1. Certifique-se que todos os serviços estão rodando

```bash
cd /home/halys/projects/ufg/sd/chat4all
docker-compose ps
```

Você deve ver 7 containers:
- `chat4all-postgres` (Banco de dados)
- `chat4all-redis` (Cache)
- `chat4all-zookeeper` (Coordenação Kafka)
- `chat4all-kafka` (Message broker)
- `chat4all-api` (API REST)
- `chat4all-router-worker` (Worker Kafka)
- `chat4all-web` (Interface web)

### 2. Acesse a interface web

Abra seu navegador em:

```
http://localhost:9000
```

## 👥 Testando com 2 Usuários

### Simulando Alice e Bob conversando

#### Opção 1: Duas abas no mesmo navegador

1. Abra `http://localhost:9000` em uma aba
2. Faça login como **Alice** (alice@chat4all.com / password123)
3. Abra uma **nova aba anônima/privada** (Ctrl+Shift+N no Chrome)
4. Acesse `http://localhost:9000` na aba anônima
5. Faça login como **Bob** (bob@chat4all.com / password123)
6. Agora você pode trocar mensagens entre as duas abas!

#### Opção 2: Dois navegadores diferentes

1. Abra o **Chrome** e acesse `http://localhost:9000`
2. Faça login como **Alice**
3. Abra o **Firefox** (ou outro navegador) e acesse `http://localhost:9000`
4. Faça login como **Bob**
5. Troque mensagens entre os navegadores!

#### Opção 3: Você e seu amigo (melhor opção!)

**No seu computador:**
1. Descubra seu IP local:
   ```bash
   hostname -I | awk '{print $1}'
   ```
   Exemplo: `192.168.1.100`

2. Compartilhe com seu amigo o endereço:
   ```
   http://192.168.1.100:9000
   ```

**Seu amigo:**
1. Acessa o endereço que você compartilhou
2. Faz login como **Bob**

**Você:**
1. Acessa `http://localhost:9000`
2. Faz login como **Alice**

Agora vocês podem trocar mensagens em tempo real! 🎉

## 🎮 Como Usar

### Tela de Login

1. **Selecione o usuário**: Alice ou Bob
2. **Digite a senha**: `password123` (já preenchida)
3. Clique em **Entrar no Chat**

### Tela de Chat

- **Digite a mensagem** no campo de texto na parte inferior
- **Pressione Enter** ou clique em **Enviar**
- As mensagens aparecem com:
  - Suas mensagens: À direita com fundo roxo/azul
  - Mensagens do outro usuário: À esquerda com fundo branco
  - **Horário** e **status** de cada mensagem

### Atualização Automática

- A interface **atualiza automaticamente** a cada 3 segundos
- Você **não precisa recarregar a página** para ver novas mensagens
- Um **indicador verde pulsante** mostra que você está online

### Sair

- Clique no botão **Sair** no canto superior direito
- Você volta para a tela de login

## 🔧 Credenciais de Teste

| Usuário | Email                | Senha        |
|---------|---------------------|--------------|
| Alice   | alice@chat4all.com  | password123  |
| Bob     | bob@chat4all.com    | password123  |

## 📊 Arquitetura

```
┌─────────────────┐
│  Navegador Web  │
│  (localhost:9000)│
└────────┬────────┘
         │ HTTP/JSON
         ▼
┌─────────────────┐
│   API REST      │ ◄───── CORS habilitado
│  (localhost:8080)│
└────────┬────────┘
         │
         ├─────► PostgreSQL (dados persistentes)
         ├─────► Kafka (eventos assíncronos)
         └─────► Redis (cache)
```

## 🎨 Recursos da Interface

- ✅ Design moderno com gradientes
- ✅ Animações suaves nas mensagens
- ✅ Responsivo (funciona em mobile)
- ✅ Indicador de status online
- ✅ Auto-scroll ao receber mensagens
- ✅ Formatação de horário (HH:MM)
- ✅ Status das mensagens (Enviado/Entregue)
- ✅ Escape de HTML (segurança)

## 🐛 Troubleshooting

### A interface não carrega

```bash
# Verificar se o container está rodando
docker-compose ps | grep web

# Ver logs
docker-compose logs web

# Reiniciar
docker-compose restart web
```

### API não responde

```bash
# Verificar API
curl http://localhost:8080/health

# Ver logs da API
docker-compose logs api-service

# Reiniciar API
docker-compose restart api-service
```

### Mensagens não aparecem

1. Verifique se o **Router Worker** está processando:
   ```bash
   docker-compose logs router-worker
   ```

2. Verifique se o **Kafka** está saudável:
   ```bash
   docker-compose ps kafka
   ```

3. Aguarde alguns segundos (polling é a cada 3 segundos)

## 🎯 Próximos Passos

Para melhorias futuras (não implementadas na versão básica):

- **WebSocket** para atualizações em tempo real (sem polling)
- **Notificações** de novas mensagens
- **Indicador de digitação** ("Bob está digitando...")
- **Upload de arquivos** e imagens
- **Emojis** e formatação de texto
- **Histórico infinito** com scroll infinito
- **Busca** de mensagens antigas

## 📝 Notas Técnicas

- **Polling Interval**: 3 segundos (configurável em `index.html`)
- **CORS**: Habilitado na API para permitir acesso do navegador
- **JWT Token**: Expira em 1 hora
- **Auto-logout**: Token expirado requer novo login
- **Conversation ID**: Hardcoded (`33333333-3333-3333-3333-333333333333`)

---

**Divirta-se testando o Chat4All!** 💬✨
