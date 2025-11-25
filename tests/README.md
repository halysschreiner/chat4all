# Testes Integrados - Chat4All

Este diretório contém testes integrados conforme especificado em **TAREFA.md**.

## 📋 Requisitos dos Testes

1. **Enviar mensagens e arquivos via API**
2. **Verificar armazenamento**
3. **Verificar logs dos connectors mock**
4. **Testar múltiplos usuários simultaneamente**

## 🧪 Scripts de Teste

### 1. `test-messages.sh`
Testa envio de mensagens via API e verifica armazenamento no PostgreSQL.

### 2. `test-files.sh`
Testa upload de arquivos via API e verifica armazenamento no MinIO.

### 3. `test-connectors.sh`
Verifica logs dos connectors mock (WhatsApp e Instagram).

### 4. `test-multiple-users.sh`
Testa múltiplos usuários enviando mensagens simultaneamente.

### 5. `run-all-tests.sh`
Executa todos os testes em sequência.

## 🚀 Como Executar

### Todos os testes
```bash
cd tests
./run-all-tests.sh
```

### Teste individual
```bash
cd tests
./test-messages.sh
```

## 📊 Resultados Esperados

Cada teste gera um arquivo de resultado em `tests/results/`:
- `messages-test-result.txt`
- `files-test-result.txt`
- `connectors-test-result.txt`
- `multiple-users-test-result.txt`

## ⚠️ Pré-requisitos

- Docker e Docker Compose instalados e rodando
- Todos os serviços do Chat4All devem estar ativos:
  ```bash
  docker-compose up -d
  ```
- `curl` instalado para fazer requisições HTTP
- `jq` instalado para processar JSON (opcional, mas recomendado)

## 🔍 Verificações dos Testes

### 1. Mensagens
- ✅ Criação de usuários
- ✅ Login e obtenção de tokens
- ✅ Criação de conversas
- ✅ Envio de mensagens
- ✅ Verificação no banco de dados
- ✅ Transições de status (SENT → DELIVERED → READ)

### 2. Arquivos
- ✅ Iniciar upload multipart
- ✅ Upload de partes do arquivo
- ✅ Completar upload
- ✅ Enviar mensagem com referência ao arquivo
- ✅ Verificação no MinIO
- ✅ Download do arquivo

### 3. Connectors Mock
- ✅ Verificar health checks
- ✅ Enviar mensagens para WhatsApp mock
- ✅ Enviar mensagens para Instagram mock
- ✅ Verificar logs de processamento
- ✅ Verificar callbacks simulados

### 4. Múltiplos Usuários
- ✅ Criar 5 usuários diferentes
- ✅ Enviar mensagens simultâneas (paralelo)
- ✅ Verificar que todas foram processadas
- ✅ Verificar ausência de race conditions
- ✅ Verificar logs de auditoria

## 📈 Relatório de Testes

Após executar `run-all-tests.sh`, um relatório consolidado é gerado em:
```
tests/results/test-report.txt
```

O relatório inclui:
- Total de testes executados
- Testes que passaram
- Testes que falharam
- Tempo total de execução
- Detalhes de cada teste
