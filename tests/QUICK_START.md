# 🧪 Guia Rápido de Execução dos Testes

## ⚡ Execução Rápida

```powershell
# Na raiz do projeto
cd tests
.\run-all-tests.ps1
```

## 📋 Pré-requisitos

1. **Docker e Docker Compose** rodando
2. **Todos os serviços** ativos:
   ```powershell
   docker-compose up -d
   ```
3. Aguardar ~30 segundos para inicialização completa

## 🎯 Testes Individuais

### Teste 1: Mensagens via API
```powershell
cd tests
.\test-messages.ps1
```
**Verifica:**
- ✅ Criação de usuários
- ✅ Autenticação (login/tokens)
- ✅ Criação de conversas
- ✅ Envio de mensagens
- ✅ Armazenamento no PostgreSQL
- ✅ Transições de status (SENT → DELIVERED → READ)

### Teste 2: Upload de Arquivos
```powershell
cd tests
.\test-files.ps1
```
**Verifica:**
- ✅ Upload multipart
- ✅ Envio de partes do arquivo
- ✅ Completar upload
- ✅ Mensagem com arquivo anexado
- ✅ Armazenamento no MinIO
- ✅ Download de arquivo

### Teste 3: Logs dos Connectors Mock
```powershell
cd tests
.\test-connectors.ps1
```
**Verifica:**
- ✅ Health checks (WhatsApp e Instagram)
- ✅ Envio de mensagens para mocks
- ✅ Logs de processamento
- ✅ Kafka consumers ativos
- ✅ Callbacks simulados

### Teste 4: Múltiplos Usuários Simultâneos
```powershell
cd tests
.\test-multiple-users.ps1
```
**Verifica:**
- ✅ Criação de 5 usuários
- ✅ Envio paralelo de mensagens
- ✅ Envio sequencial rápido
- ✅ Ausência de race conditions
- ✅ Integridade dos dados
- ✅ Logs de auditoria

## 📊 Resultados

Após executar os testes, os resultados ficam em:
```
tests/results/
├── messages-test-result.txt
├── files-test-result.txt
├── connectors-test-result.txt
├── multiple-users-test-result.txt
└── test-report.txt (relatório consolidado)
```

## 🔍 Interpretação dos Resultados

### ✅ Sucesso
```
✓ TODOS OS TESTES PASSARAM!
```
Sistema está funcionando conforme especificado.

### ✗ Falha
```
✗ ALGUNS TESTES FALHARAM!
```
Verifique os arquivos de resultado para detalhes específicos.

## 🐛 Troubleshooting

### Erro: "Serviços não estão rodando"
```powershell
docker-compose up -d
Start-Sleep -Seconds 30
.\run-all-tests.ps1
```

### Erro: "Falha na autenticação"
Os testes criam usuários automaticamente. Se já existirem, são reutilizados.

### Erro: "Connector não responde"
Verifique se os connectors mock estão configurados:
```powershell
docker-compose ps | Select-String connector
```

### Erro: "Timeout ao conectar"
Aguarde mais tempo para inicialização:
```powershell
docker-compose logs -f api-gateway
# Aguarde até ver "ready to handle connections"
```

## 📈 Métricas de Performance

Os testes medem:
- **Tempo de resposta** da API
- **Taxa de mensagens/segundo**
- **Tempo de upload** de arquivos
- **Concorrência** (múltiplos usuários)

Valores esperados:
- Envio de mensagem: < 1 segundo
- Upload de arquivo (1MB): < 5 segundos
- 10 mensagens rápidas: < 3 segundos
- 5 usuários paralelos: < 5 segundos

## 🔄 Limpeza

Para limpar os dados de teste:
```powershell
# Limpar resultados
Remove-Item tests\results\*.txt

# Limpar banco de dados de teste (CUIDADO!)
docker-compose down -v
docker-compose up -d
```

## 📝 Logs Detalhados

Para ver logs em tempo real durante os testes:
```powershell
# Terminal 1: Executar testes
cd tests
.\run-all-tests.ps1

# Terminal 2: Ver logs
docker-compose logs -f api-gateway api-service
```

## ✨ Dicas

1. **Execute todos os testes** primeiro para visão geral
2. **Testes individuais** para debug específico
3. **Verifique o relatório** consolidado em `results/test-report.txt`
4. **Logs detalhados** estão em cada arquivo de resultado individual
5. **Performance** pode variar conforme recursos da máquina

## 📞 Suporte

Em caso de problemas:
1. Verifique os logs: `docker-compose logs`
2. Verifique os serviços: `docker-compose ps`
3. Reinicie o sistema: `docker-compose restart`
4. Consulte a documentação em `docs/`
