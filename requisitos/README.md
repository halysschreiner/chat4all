# Chat4All - Índice de Análise de Requisitos

> **Disciplina**: Sistemas Distribuídos - UFG  
> **Projeto**: Chat4All - Sistema de Chat Multicanal  
> **Abordagem**: Análise crítica com fundamentação teórica (First Principles)

---

## 📋 Requisitos Funcionais

| Arquivo | Requisitos | Resumo |
|---------|------------|--------|
| [`rf01_claude.md`](./rf01_claude.md) | RF01 | Cadastro de usuários (campos, validação, unicidade) |
| [`rf01_gemini.md`](./rf01_gemini.md) | RF01 | Análise alternativa do cadastro |
| [`rf02.md`](./rf02.md) | RF02 | Autenticação JWT (login, registro, validação de token) |
| [`rf03.md`](./rf03.md) | RF03 | Mensagens privadas (envio, listagem) |
| [`rf04.md`](./rf04.md) | RF04 | Mensagens em grupo (criação, distribuição) |
| [`rf03_rf04.md`](./rf03_rf04.md) | RF03, RF04 | Análise combinada de mensagens (privadas e grupo, Kafka) |
| [`rf05.md`](./rf05.md) | RF05 | Upload de arquivos (multipart, MinIO, até 2GB) |
| [`rf06.md`](./rf06.md) | RF06 | Controle de status de mensagens (SENT, DELIVERED, READ, FAILED) |
| [`rf07.md`](./rf07.md) | RF07 | Connectors Mock (WhatsApp, Instagram, callbacks) |
| [`rf08.md`](./rf08.md) | RF08 | Notificações em tempo real (WebSocket, JWT, Redis Pub/Sub) |
| [`rf06_rf07_rf08.md`](./rf06_rf07_rf08.md) | RF06, RF07, RF08 | Análise combinada do sistema de feedback |
| [`rf09.md`](./rf09.md) | RF09 | Gerenciamento de conversas (private/group, roles, last_read_at) |
| [`rf10.md`](./rf10.md) | RF10 | Auditoria e logs (audit trail, JSONB, compliance) |
| [`rf09_rf10.md`](./rf09_rf10.md) | RF09, RF10 | Análise combinada de conversas e auditoria |

---

## 🔧 Requisitos Não-Funcionais

| Arquivo | Requisitos | Resumo |
|---------|------------|--------|
| [`rnf01_rnf02_rnf03.md`](./rnf01_rnf02_rnf03.md) | RNF01-03 | Sockets TCP, Concorrência (Event Loop), Microsserviços gRPC |
| [`rnf04_rnf05.md`](./rnf04_rnf05.md) | RNF04-05 | Apache Kafka (at-least-once), Polyglot Persistence |
| [`rnf06_rnf07.md`](./rnf06_rnf07.md) | RNF06-07 | Escalabilidade horizontal, Tolerância a falhas |
| [`rnf08_rnf09.md`](./rnf08_rnf09.md) | RNF08-09 | Testes de carga (k6), Observabilidade (Prometheus/Grafana) |
| [`rnf10_rnf11_rnf12_rnf13.md`](./rnf10_rnf11_rnf12_rnf13.md) | RNF10-13 | Docker, Angular 17, Documentação, Stack tecnológica |

---

## 📊 Estrutura de Cada Análise

Cada documento segue a estrutura padrão:

1. **Resumo do Requisito**: Transcrição literal + importância teórica
2. **Fundamentos Teóricos**: Conceitos de SD (CAP, Kafka, gRPC, etc.)
3. **Implementação**: Código com referências de arquivo e linha
4. **Análise Crítica**: 
   - Conformidade (tabela checklist)
   - Pontos fortes
   - Limitações identificadas
   - Perguntas socráticas para aprofundamento
5. **Referências Teóricas**: Papers, livros, RFCs

---

## 🎯 Cobertura Total

### Requisitos Funcionais (RF01-RF10): ✅ 100%

| Req | Status | Análise em |
|-----|--------|------------|
| RF01 | ✅ | rf01_claude.md, rf01_gemini.md |
| RF02 | ✅ | rf02.md |
| RF03 | ✅ | rf03.md, rf03_rf04.md |
| RF04 | ✅ | rf04.md, rf03_rf04.md |
| RF05 | ✅ | rf05.md |
| RF06 | ✅ | rf06.md, rf06_rf07_rf08.md |
| RF07 | ✅ | rf07.md, rf06_rf07_rf08.md |
| RF08 | ✅ | rf08.md, rf06_rf07_rf08.md |
| RF09 | ✅ | rf09.md, rf09_rf10.md |
| RF10 | ✅ | rf10.md, rf09_rf10.md |

### Requisitos Não-Funcionais (RNF01-RNF13): ✅ 100%

| Req | Status | Análise em |
|-----|--------|------------|
| RNF01 | ✅ | rnf01_rnf02_rnf03.md |
| RNF02 | ✅ | rnf01_rnf02_rnf03.md |
| RNF03 | ✅ | rnf01_rnf02_rnf03.md |
| RNF04 | ✅ | rnf04_rnf05.md |
| RNF05 | ✅ | rnf04_rnf05.md |
| RNF06 | ✅ | rnf06_rnf07.md |
| RNF07 | ✅ | rnf06_rnf07.md |
| RNF08 | ✅ | rnf08_rnf09.md |
| RNF09 | ✅ | rnf08_rnf09.md |
| RNF10 | ✅ | rnf10_rnf11_rnf12_rnf13.md |
| RNF11 | ✅ | rnf10_rnf11_rnf12_rnf13.md |
| RNF12 | ✅ | rnf10_rnf11_rnf12_rnf13.md |
| RNF13 | ✅ | rnf10_rnf11_rnf12_rnf13.md |

---

## 🧠 Conceitos Teóricos Abordados

### Sistemas Distribuídos
- CAP Theorem / PACELC
- Consistência eventual vs forte
- At-least-once / Exactly-once delivery
- Consumer Groups e Rebalancing

### Arquitetura
- Microsserviços
- API Gateway Pattern
- Event-driven architecture
- Polyglot Persistence

### Protocolos
- gRPC / Protobuf
- WebSocket (RFC 6455)
- TCP/IP (RFC 793)
- JWT (RFC 7519)

### Escalabilidade
- Horizontal vs Vertical scaling
- Particionamento (Kafka)
- Load balancing

### Resiliência
- Graceful shutdown
- Manual commit
- Circuit breaker (discussão)
- Health checks

### Observabilidade
- Métricas (Prometheus)
- Dashboards (Grafana)
- SLI / SLO / SLA

---

## 📚 Bibliografia Comum

- **Kleppmann, M.** - *Designing Data-Intensive Applications*
- **Tanenbaum & Van Steen** - *Distributed Systems: Principles and Paradigms*
- **Google SRE Book** - *Site Reliability Engineering*
- **Fowler, M.** - *Patterns of Enterprise Application Architecture*
- **Apache Kafka Documentation**
- **gRPC Documentation**
