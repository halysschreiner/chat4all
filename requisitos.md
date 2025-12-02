# Requisitos do Sistema Chat4All

## Visão Geral

Este documento apresenta os requisitos funcionais e não funcionais do sistema Chat4All, um chat distribuído estilo WhatsApp desenvolvido para a disciplina de Sistemas Distribuídos da Universidade Federal de Goiás (UFG).

---

## Requisitos Funcionais

### RF01 - Conexão Cliente-Servidor
- A arquitetura deve seguir o modelo cliente-servidor.
- O servidor deve gerenciar as conexões dos clientes, identificar usuários e rotear mensagens/arquivos.
- O cliente deve permitir ao usuário autenticar-se, iniciar conversas privadas e interagir com grupos.

### RF02 - Autenticação de Usuários
- Implementar autenticação via JWT (JSON Web Token).
- Endpoint `POST /v1/auth/login` para autenticação de usuários.
- Usuários devem ser identificados por um nome único (username).
- O servidor deve registrar quem está conectado e garantir a entrega de mensagens apenas aos destinatários corretos.

### RF03 - Mensagens Privadas
- Permitir que um usuário envie uma mensagem de texto a outro usuário conectado.
- O receptor deve receber em tempo real a mensagem, desde que esteja conectado.
- Endpoint `POST /v1/messages` para envio de mensagens de texto.
- Endpoint `GET /v1/conversations/{id}/messages` para listar mensagens de uma conversa.

### RF04 - Mensagens em Grupo
- O sistema deve suportar a criação de grupos de usuários.
- Mensagens enviadas a um grupo devem ser distribuídas automaticamente a todos os membros.
- Endpoint `GET /v1/conversations` para listar conversas do usuário.

### RF05 - Envio de Arquivos
- O sistema deve permitir o envio de arquivos (texto, imagem, PDF, vídeo, áudio).
- Suporte a arquivos de até 2GB.
- Implementar upload multipart (resumable).
- Endpoints:
  - `POST /v1/files/upload/initiate` - Iniciar upload multipart.
  - `POST /v1/files/upload/part` - Enviar cada parte do arquivo.
  - `POST /v1/files/upload/complete` - Completar upload.
  - `GET /v1/files/{id}/download` - Obter URL temporária de download (presigned URL válida por 1 hora).
- Registrar metadados no banco: file_id, checksum, tamanho (file_size), uploader (user_id), conversation_id.
- API `POST /v1/messages` deve aceitar payload com `type: "file"` e `file_id`.

### RF06 - Controle de Status da Mensagem
- Implementar transições automáticas de status:
  - `SENT` → Mensagem criada e publicada no Kafka (✓ cinza).
  - `DELIVERED` → Processada pelo worker, entregue ao destinatário (✓✓ cinza).
  - `READ` → Lida pelo destinatário ao abrir a conversa (✓✓ azul).
  - `FAILED` → Erro no processamento.
- Atualizar status no banco e notificar via WebSocket ou callback HTTP.
- Endpoint `POST /v1/conversations/{id}/read` para marcar mensagens como lidas.
- Endpoint `GET /v1/conversations/{id}/unread` para obter contagem de mensagens não lidas.

### RF07 - Connectors Mock (Integração Multiplataforma)
- Criar serviços `connector_whatsapp_mock` e `connector_instagram_mock`.
- Cada connector deve:
  - Receber mensagens de um tópico Kafka específico (`whatsapp.messages`, `instagram.messages`).
  - Simular envio com logs (ex: `[WhatsApp] Entregue a usuário X`).
  - Retornar callback simulando entrega/leitura.
- Implementar endpoints nos connectors:
  - `GET /health` - Health check.
  - `POST /send` - Simular envio manual de mensagem.
  - `POST /webhook/incoming` - Receber mensagens simuladas do canal externo.

### RF08 - Notificações em Tempo Real
- Implementar WebSocket Worker para notificações em tempo real.
- Conexão WebSocket na porta 8082.
- Autenticação via token JWT na mensagem inicial.
- Broadcast de atualizações de status para clientes conectados.

### RF09 - Gerenciamento de Conversas
- Suporte a conversas do tipo `private` (chat privado) e `group` (grupo).
- Registro de membros da conversa com papéis: `owner`, `admin`, `member`.
- Rastrear última leitura (`last_read_at`) por usuário em cada conversa.

### RF10 - Auditoria e Logs
- Gerar logs de auditoria para operações do sistema.
- Registrar: evento, tipo de entidade, ID da entidade, usuário, detalhes e timestamp.

---

## Requisitos Não Funcionais

### RNF01 - Tecnologia de Comunicação via Socket
- Implementação utilizando sockets TCP para comunicação entre componentes.
- WebSocket para comunicação em tempo real com clientes.

### RNF02 - Concorrência e Multithreading
- O servidor deve ser multithreaded ou usar mecanismos assíncronos para gerenciar múltiplas conexões simultâneas.
- Suporte a múltiplos clientes conectados simultaneamente (testado com pelo menos 5 clientes).

### RNF03 - Arquitetura de Microsserviços
- Arquitetura baseada em microsserviços com comunicação gRPC.
- Serviços independentes que podem escalar separadamente.
- API Gateway como único ponto de entrada (padrão API Gateway Pattern).

### RNF04 - Message Broker (Apache Kafka)
- Utilizar Apache Kafka para comunicação assíncrona entre serviços.
- Tópicos particionados por `conversation_id` (5 partições).
- Consumer Groups para balanceamento automático de carga.
- Garantia "at-least-once delivery".

### RNF05 - Persistência de Dados (Polyglot Persistence)
- **PostgreSQL**: Banco relacional para dados transacionais (usuários, conversas, mensagens).
- **Redis**: Cache para sessões JWT e conversas recentes.
- **MinIO (S3-compatible)**: Object Storage para armazenamento de arquivos até 2GB.

### RNF06 - Escalabilidade Horizontal
- Executar múltiplas instâncias do router-worker (testado 1-5 instâncias).
- Executar múltiplas instâncias dos connectors (testado 1-3 instâncias por tipo).
- Demonstrar aumento de throughput ao adicionar nós.
- Redistribuição automática de carga ao adicionar/remover workers.

### RNF07 - Tolerância a Falhas
- Manual commit no Kafka (evita perda de mensagens).
- Consumer Group Rebalancing automático quando workers falham.
- Graceful shutdown handlers para encerramento controlado.
- Políticas de restart do Docker (`restart: unless-stopped`).
- Recuperação automática sem intervenção manual.
- Zero perda de mensagens garantida.

### RNF08 - Testes de Carga
- Utilizar ferramentas como k6, Locust ou Gatling para simular múltiplos usuários.
- Gerar métricas: mensagens/segundo, latência média, taxa de erros.
- Thresholds:
  - Latência P95 < 500ms.
  - Latência P99 < 1000ms.
  - Taxa de erro < 5%.
- Armazenar resultados e gráficos.

### RNF09 - Monitoramento e Observabilidade
- Integrar Prometheus para coleta de métricas.
- Integrar Grafana para dashboards e visualização.
- Métricas expostas pelos serviços:
  - `messages_processed_total` - Total de mensagens processadas.
  - `messages_per_second` - Throughput atual.
  - `latency_ms` - Latência (p50, p95, p99).
  - `errors_total` - Total de erros.
  - `cpu_usage_percent` - Uso de CPU.
  - `memory_usage_mb` - Uso de memória.
  - `active_workers` - Workers ativos.
  - `http_requests_total` - Total de requisições HTTP.
  - `kafka_consumer_lag` - Lag do consumer Kafka.
- Dashboards em tempo real com refresh de 5 segundos.

### RNF10 - Containerização
- Todos os serviços executam em containers Docker.
- Docker Compose para orquestração de múltiplos containers.
- Health checks configurados para cada serviço.
- Inicialização automática de todos os serviços com script (`docker-compose up`).

### RNF11 - Interface de Usuário
- Interface web desenvolvida em Angular 17 (SPA).
- Interface de terminal (CLI) também satisfaz os requisitos mínimos.
- Indicadores visuais de status de mensagem (✓, ✓✓, ✓✓ azul).

### RNF12 - Documentação
- README com endpoints, exemplos de uso e instruções de execução.
- Documentação OpenAPI com endpoints de upload e campos das APIs.
- Documentação dos fluxos de entrega e leitura no relatório técnico.

### RNF13 - Stack Tecnológica
| Componente | Tecnologia | Versão |
|------------|------------|--------|
| Backend | PHP | 8.3 |
| Frontend | Angular | 17 |
| RPC | gRPC | - |
| Banco de Dados | PostgreSQL | 16 |
| Cache | Redis | 7 |
| Object Storage | MinIO | Latest |
| Message Broker | Apache Kafka | 7.5.0 |
| Monitoramento | Prometheus | Latest |
| Dashboards | Grafana | Latest |
| Containers | Docker | - |
| Orquestração | Docker Compose | - |
| WebSocket | Ratchet (PHP) | - |

---

## Matriz de Rastreabilidade

| Requisito | Semana | Documento de Origem |
|-----------|--------|---------------------|
| RF01-RF04 | 3-4 | Especificação de Trabalho, Implementação da API Básica |
| RF05 | 5-6 | Upload Connectors Mock |
| RF06 | 5-6 | Upload Connectors Mock |
| RF07 | 5-6 | Upload Connectors Mock |
| RF08 | 3-4 | Implementação da API Básica |
| RF09 | 3-4 | Especificação de Trabalho |
| RF10 | 3-4 | Implementação da API Básica |
| RNF01-RNF02 | 3-4 | Especificação de Trabalho |
| RNF03-RNF05 | 3-4 | Implementação da API Básica |
| RNF06-RNF09 | 7-8 | Trabalho Final - Escalabilidade e Relatório |
| RNF10-RNF12 | 3-4 | Implementação da API Básica |

---

## Referências

- `Especificação de Trabalho - whatsapp socket.md`
- `2 - Implementação da API Básica.md`
- `3 - Upload Connectors Mock.md`
- `Trabalho Final - Escalabilidade e Relatório.md`
