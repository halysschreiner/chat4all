# Feature Specification: Trabalho Final - Escalabilidade e Relatório

**Feature Branch**: `001-final-scalability-report`  
**Created**: 2025-11-29  
**Status**: Draft  
**Input**: User description: "Trabalho Final - Escalabilidade e Relatório: Implementar Object Storage, Connectors Mock (WhatsApp/Instagram), Testes de Carga, Monitoramento e Relatório Final para o sistema Chat4All de Sistemas Distribuídos"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Upload e Download de Arquivos Grandes (Priority: P1)

Como usuário do Chat4All, quero enviar arquivos de até 2GB em minhas conversas para compartilhar documentos, imagens e vídeos com outros participantes, podendo fazer download posteriormente via link temporário.

**Why this priority**: Upload de arquivos é funcionalidade core que habilita comunicação rica. Sem isso, o sistema fica limitado a texto. Demonstra conceito de Object Storage distribuído.

**Independent Test**: Enviar arquivo de 100MB via API, verificar armazenamento no MinIO, obter presigned URL e fazer download bem-sucedido.

**Acceptance Scenarios**:

1. **Given** um usuário autenticado e uma conversa existente, **When** o usuário envia arquivo de 500MB via upload multipart, **Then** o arquivo é armazenado no Object Storage com metadados registrados (file_id, checksum, tamanho, uploader, conversation_id)
2. **Given** um arquivo já enviado, **When** o usuário solicita download, **Then** recebe URL temporária (presigned URL) válida por tempo limitado
3. **Given** upload em progresso, **When** conexão é interrompida, **Then** upload pode ser retomado (resumable) sem perder dados já transferidos

---

### User Story 2 - Mensagens com Anexos (Priority: P1)

Como usuário, quero enviar mensagens que incluam arquivos anexados para contextualizar minhas comunicações com documentos e mídias relevantes.

**Why this priority**: Integra Object Storage com sistema de mensagens existente. Completa fluxo de comunicação rica.

**Independent Test**: Criar mensagem com type:"file" e file_id válido, verificar persistência e associação correta.

**Acceptance Scenarios**:

1. **Given** arquivo já enviado com file_id conhecido, **When** usuário cria mensagem POST /v1/messages com type:"file" e file_id, **Then** mensagem é criada e vinculada ao arquivo
2. **Given** mensagem com anexo criada, **When** destinatário consulta conversa, **Then** vê mensagem com referência ao arquivo e pode obter URL de download

---

### User Story 3 - Entrega de Mensagens via Connectors Mock (Priority: P1)

Como operador do sistema, quero que mensagens sejam entregues a plataformas externas simuladas (WhatsApp e Instagram) para demonstrar integração multiplataforma.

**Why this priority**: Demonstra padrão de arquitetura de sistemas distribuídos com connectors desacoplados via Kafka. Fundamental para avaliação acadêmica.

**Independent Test**: Enviar mensagem destinada a WhatsApp, verificar logs do connector_whatsapp_mock mostrando "[WhatsApp] Entregue a usuário X".

**Acceptance Scenarios**:

1. **Given** mensagem criada para destinatário WhatsApp, **When** router-worker processa e publica em tópico Kafka do WhatsApp, **Then** connector_whatsapp_mock consome e loga entrega simulada
2. **Given** mensagem criada para destinatário Instagram, **When** router-worker processa e publica em tópico Kafka do Instagram, **Then** connector_instagram_mock consome e loga entrega simulada
3. **Given** connector processa mensagem, **When** entrega é simulada, **Then** callback é enviado com status atualizado (DELIVERED)

---

### User Story 4 - Controle de Status de Mensagem (Priority: P2)

Como usuário, quero ver o status de entrega das minhas mensagens (enviada, entregue, lida) para saber se o destinatário recebeu e visualizou minha comunicação.

**Why this priority**: Completa fluxo de entrega com feedback ao usuário. Demonstra callbacks e webhooks em sistemas distribuídos.

**Independent Test**: Enviar mensagem, aguardar transições automáticas SENT→DELIVERED→READ, verificar notificações recebidas.

**Acceptance Scenarios**:

1. **Given** mensagem enviada com status SENT, **When** connector confirma entrega, **Then** status atualiza para DELIVERED e notificação é enviada
2. **Given** mensagem com status DELIVERED, **When** callback de leitura é recebido, **Then** status atualiza para READ e notificação é enviada
3. **Given** mudança de status, **When** atualização ocorre, **Then** banco de dados é atualizado e websocket/webhook notifica cliente

---

### User Story 5 - Escalabilidade Horizontal de Workers (Priority: P2)

Como administrador do sistema, quero escalar horizontalmente os router-workers e connectors para aumentar a capacidade de processamento de mensagens conforme demanda.

**Why this priority**: Demonstra conceito fundamental de escalabilidade horizontal em sistemas distribuídos. Essencial para avaliação.

**Independent Test**: Executar com 1 worker, medir throughput. Escalar para 3 workers, verificar aumento proporcional de throughput.

**Acceptance Scenarios**:

1. **Given** sistema rodando com 1 router-worker, **When** administrador executa `docker-compose --scale router-worker=3`, **Then** 3 workers passam a consumir mensagens em paralelo
2. **Given** múltiplos workers ativos, **When** carga de mensagens aumenta, **Then** throughput aumenta proporcionalmente ao número de workers
3. **Given** 3 workers ativos, **When** 1 worker falha, **Then** Kafka redistribui partições automaticamente entre workers restantes

---

### User Story 6 - Tolerância a Falhas (Priority: P2)

Como administrador, quero que o sistema se recupere automaticamente de falhas de componentes sem perda de mensagens para garantir disponibilidade contínua.

**Why this priority**: Demonstra resiliência de sistemas distribuídos. Conceito crítico da disciplina.

**Independent Test**: Derrubar 1 worker durante processamento, verificar que mensagens não são perdidas e processamento continua.

**Acceptance Scenarios**:

1. **Given** workers processando mensagens, **When** um worker é derrubado (docker stop), **Then** Kafka rebalanceia partições e processamento continua sem perda
2. **Given** mensagem em processamento durante falha, **When** worker reinicia, **Then** mensagem não commitada é reprocessada
3. **Given** sistema sob carga, **When** falha de worker ocorre, **Then** tempo de recuperação é menor que 30 segundos

---

### User Story 7 - Testes de Carga e Métricas (Priority: P2)

Como avaliador do sistema, quero executar testes de carga e visualizar métricas de performance para validar capacidade e comportamento sob stress.

**Why this priority**: Validação quantitativa de escalabilidade. Requisito explícito do trabalho final.

**Independent Test**: Executar k6 com 100 usuários virtuais, coletar métricas de throughput e latência.

**Acceptance Scenarios**:

1. **Given** sistema em execução, **When** teste k6 é executado com múltiplos usuários virtuais, **Then** métricas são coletadas: mensagens/segundo, latência média, taxa de erros
2. **Given** teste de carga finalizado, **When** resultados são analisados, **Then** gráficos mostram comportamento do sistema sob diferentes níveis de carga
3. **Given** múltiplas configurações de escala, **When** testes são executados, **Then** comparativo demonstra ganho de throughput com mais workers

---

### User Story 8 - Monitoramento em Tempo Real (Priority: P3)

Como operador, quero visualizar dashboards com métricas em tempo real para acompanhar saúde do sistema durante demonstrações.

**Why this priority**: Observabilidade é importante mas não bloqueia funcionalidades core. Necessário para demonstração prática.

**Independent Test**: Acessar Grafana, verificar dashboards mostrando métricas atualizadas de mensagens processadas.

**Acceptance Scenarios**:

1. **Given** Prometheus coletando métricas, **When** operador acessa Grafana, **Then** dashboards mostram gráficos atualizados de messages_processed_total, latency_ms, errors_total
2. **Given** sistema processando mensagens, **When** dashboard é visualizado, **Then** métricas atualizam em tempo real (intervalo < 15 segundos)
3. **Given** evento de erro ocorre, **When** dashboard é consultado, **Then** pico de erros é visível no gráfico

---

### User Story 9 - Demonstração Prática Completa (Priority: P3)

Como avaliador acadêmico, quero ver demonstração funcional do fluxo completo para validar implementação dos conceitos de sistemas distribuídos.

**Why this priority**: Consolidação de todas as funcionalidades para apresentação. Depende de todas as outras stories.

**Independent Test**: Executar script de demonstração que percorre todo o fluxo: login → envio mensagem → entrega via connector → callback → envio arquivo grande.

**Acceptance Scenarios**:

1. **Given** sistema completamente operacional, **When** script de demonstração é executado, **Then** fluxo completo é demonstrado: envio → persistência → connector → callback de leitura
2. **Given** demonstração em execução, **When** arquivo de ~1GB é enviado, **Then** upload completa com sucesso e sistema permanece estável
3. **Given** demonstração em andamento, **When** dashboards são exibidos, **Then** métricas em tempo real são visíveis durante execução

---

### Edge Cases

- **Upload interrompido**: Sistema deve permitir retomada de uploads parciais (resumable upload)
- **Arquivo excede 2GB**: Sistema deve rejeitar com erro claro informando limite
- **Connector offline**: Mensagens devem permanecer na fila Kafka até connector voltar
- **Todos workers falham**: Sistema deve manter mensagens seguras em Kafka para processamento quando workers voltarem
- **Presigned URL expirada**: Download deve falhar com erro indicando necessidade de nova URL
- **Checksum inválido**: Upload deve ser rejeitado se integridade do arquivo não for verificada

## Requirements *(mandatory)*

### Functional Requirements

#### Object Storage (Semanas 5-6)

- **FR-001**: Sistema DEVE permitir upload de arquivos até 2GB via upload multipart (resumable)
- **FR-002**: Sistema DEVE armazenar arquivos em Object Storage compatível com S3
- **FR-003**: Sistema DEVE registrar metadados de arquivo: file_id, checksum, tamanho, uploader, conversation_id
- **FR-004**: Sistema DEVE gerar URLs temporárias (presigned URLs) para download de arquivos
- **FR-005**: Sistema DEVE validar integridade de arquivos via checksum

#### Mensagens com Anexos (Semanas 5-6)

- **FR-006**: API POST /v1/messages DEVE aceitar payload com type:"file" e file_id para mensagens com anexo
- **FR-007**: Sistema DEVE vincular mensagens a arquivos previamente enviados
- **FR-008**: Sistema DEVE permitir consulta de mensagens com metadados de arquivos anexados

#### Connectors Mock (Semanas 5-6)

- **FR-009**: Sistema DEVE ter connector_whatsapp_mock que consome mensagens de tópico Kafka específico
- **FR-010**: Sistema DEVE ter connector_instagram_mock que consome mensagens de tópico Kafka específico
- **FR-011**: Connectors DEVEM simular entrega com logs identificando canal e destinatário
- **FR-012**: Connectors DEVEM enviar callbacks simulando confirmação de entrega e leitura
- **FR-013**: Sistema DEVE ter endpoints para receber mensagens simuladas dos canais externos

#### Status de Mensagens (Semanas 5-6)

- **FR-014**: Sistema DEVE implementar transições automáticas de status: SENT → DELIVERED → READ
- **FR-015**: Sistema DEVE atualizar status no banco de dados quando callbacks são recebidos
- **FR-016**: Sistema DEVE notificar clientes sobre mudanças de status via websocket ou webhook

#### Escalabilidade Horizontal (Semanas 7-8)

- **FR-017**: Sistema DEVE permitir execução de múltiplas instâncias de router-worker
- **FR-018**: Sistema DEVE permitir execução de múltiplas instâncias de connectors
- **FR-019**: Sistema DEVE demonstrar aumento de throughput ao adicionar workers
- **FR-020**: Sistema DEVE redistribuir carga automaticamente quando worker falha

#### Testes de Carga (Semanas 7-8)

- **FR-021**: Sistema DEVE suportar testes de carga com ferramentas como k6
- **FR-022**: Sistema DEVE gerar métricas de: mensagens/segundo, latência média, taxa de erros
- **FR-023**: Sistema DEVE persistir resultados de testes para análise

#### Monitoramento (Semanas 7-8)

- **FR-024**: Sistema DEVE expor métricas via Prometheus
- **FR-025**: Sistema DEVE ter dashboards Grafana com gráficos em tempo real
- **FR-026**: Sistema DEVE expor métricas: messages_processed_total, latency_ms, errors_total

#### Tolerância a Falhas (Semanas 7-8)

- **FR-027**: Sistema DEVE manter mensagens seguras em Kafka durante falhas de workers
- **FR-028**: Sistema DEVE recuperar processamento automaticamente após falha
- **FR-029**: Sistema DEVE garantir zero perda de dados em cenários de falha

#### Relatório Final (Semanas 7-8)

- **FR-030**: Relatório DEVE conter: introdução, arquitetura, decisões técnicas, testes de carga, tolerância a falhas, limitações

### Key Entities

- **File**: Arquivo armazenado no Object Storage (file_id, filename, size, checksum, mime_type, uploader_id, conversation_id, created_at)
- **Message**: Mensagem no sistema (message_id, content, type, file_id, sender_id, conversation_id, status, created_at)
- **MessageStatus**: Estados possíveis da mensagem (PENDING, SENT, DELIVERED, READ, FAILED)
- **Connector**: Serviço de integração com plataforma externa (connector_id, platform, status, last_heartbeat)
- **DeliveryCallback**: Notificação de entrega/leitura (callback_id, message_id, status, timestamp, source_platform)

## Success Criteria *(mandatory)*

### Measurable Outcomes

#### Object Storage & Arquivos

- **SC-001**: Usuários conseguem fazer upload de arquivo de 1GB em menos de 5 minutos (em rede local)
- **SC-002**: Download via presigned URL completa com velocidade equivalente à conexão disponível
- **SC-003**: 100% dos uploads têm checksum verificado e armazenado

#### Connectors & Entrega

- **SC-004**: Mensagens são entregues aos connectors mock em menos de 2 segundos após envio
- **SC-005**: Callbacks de status (DELIVERED, READ) são processados em menos de 1 segundo
- **SC-006**: Logs dos connectors mostram claramente origem e destino de cada mensagem

#### Escalabilidade

- **SC-007**: Throughput aumenta em pelo menos 50% ao dobrar número de workers (de 1 para 2)
- **SC-008**: Sistema suporta pelo menos 100 mensagens/segundo com 3 workers
- **SC-009**: Redistribuição de carga após falha ocorre em menos de 30 segundos

#### Tolerância a Falhas

- **SC-010**: Zero mensagens perdidas durante falha simulada de worker
- **SC-011**: Sistema recupera operação normal em menos de 1 minuto após reinício de worker
- **SC-012**: Mensagens em processamento durante falha são reprocessadas com sucesso

#### Monitoramento

- **SC-013**: Dashboards Grafana atualizam métricas em intervalos menores que 15 segundos
- **SC-014**: Todas as métricas definidas (messages_processed_total, latency_ms, errors_total) são visíveis
- **SC-015**: Alertas visuais são exibidos quando taxa de erro ultrapassa 5%

#### Demonstração

- **SC-016**: Fluxo completo (envio → entrega → callback) é demonstrável em menos de 30 segundos
- **SC-017**: Upload de arquivo de 1GB completa sem erros durante demonstração
- **SC-018**: Todos os componentes (API, workers, connectors, monitoramento) estão operacionais simultaneamente

## Assumptions

- O sistema já possui infraestrutura básica funcional (API, Kafka, PostgreSQL, Redis)
- MinIO já está configurado e acessível no docker-compose
- Connectors mock não se comunicam com APIs externas reais (apenas simulação)
- Testes de carga serão executados em ambiente local/desenvolvimento
- Limites de performance dependem do hardware disponível
- Demonstração será realizada com docker-compose em máquina local

## Out of Scope

- Integração real com APIs do WhatsApp Business ou Instagram
- Autenticação via OAuth com plataformas externas
- Criptografia end-to-end de mensagens
- Interface gráfica para administração de connectors
- Clustering de Kafka multi-broker em produção
- Backup automatizado de dados
