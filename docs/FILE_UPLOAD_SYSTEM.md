# Sistema de Upload de Arquivos - Chat4All

## Visão Geral

Sistema de upload de arquivos com suporte a **multipart upload** para arquivos grandes (até 2GB), utilizando **MinIO** como Object Storage compatível com S3.

## Características

- ✅ Upload multipart resumível
- ✅ Suporte a arquivos de até 2GB
- ✅ Armazenamento no MinIO (S3-compatible)
- ✅ URLs temporárias (pré-assinadas) para download
- ✅ Validação de checksum (MD5/SHA256)
- ✅ Gerenciamento de permissões por conversa
- ✅ Metadados de arquivo no PostgreSQL

## Arquitetura

```
Cliente
   │
   ├─► POST /v1/files/upload/initiate
   │   └─► Cria registro no PostgreSQL
   │       └─► Inicia multipart upload no MinIO
   │
   ├─► POST /v1/files/upload/part (N vezes)
   │   └─► Envia cada parte para o MinIO
   │       └─► Salva ETag no PostgreSQL
   │
   ├─► POST /v1/files/upload/complete
   │   └─► Completa upload no MinIO
   │       └─► Atualiza status no PostgreSQL
   │
   └─► GET /v1/files/{id}/download
       └─► Gera URL pré-assinada (válida por 1h)
```

## Fluxo de Upload

### 1. Iniciar Upload

```bash
curl -X POST http://localhost:8080/v1/files/upload/initiate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "uuid-da-conversa",
    "filename": "documento.pdf",
    "file_size": 104857600,
    "content_type": "application/pdf"
  }'
```

**Retorna:**
- `upload_id`: ID para rastrear o upload
- `file_id`: ID do arquivo no sistema
- `part_size`: Tamanho de cada parte (5MB)
- `total_parts`: Número de partes necessárias

### 2. Enviar Partes

Para cada parte do arquivo (5MB cada):

```bash
curl -X POST http://localhost:8080/v1/files/upload/part \
  -H "Authorization: Bearer $TOKEN" \
  -F "upload_id=$UPLOAD_ID" \
  -F "file_id=$FILE_ID" \
  -F "part_number=1" \
  -F "data=@parte1.bin"
```

**Retorna:**
- `etag`: Hash da parte (para validação)
- `bytes_uploaded`: Bytes enviados

### 3. Completar Upload

Após enviar todas as partes:

```bash
curl -X POST http://localhost:8080/v1/files/upload/complete \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "upload_id": "$UPLOAD_ID",
    "file_id": "$FILE_ID"
  }'
```

### 4. Obter URL de Download

```bash
curl -X GET http://localhost:8080/v1/files/$FILE_ID/download \
  -H "Authorization: Bearer $TOKEN"
```

**Retorna:**
- `download_url`: URL temporária (válida por 1 hora)
- `expires_at`: Timestamp de expiração

## Configuração

### Variáveis de Ambiente

```env
MINIO_ENDPOINT=minio:9000
MINIO_ACCESS_KEY=chat4all_admin
MINIO_SECRET_KEY=chat4all_minio_pass
MINIO_BUCKET=chat4all-files
MINIO_USE_SSL=false
```

### MinIO Console

Acesse o console web do MinIO em:
- URL: http://localhost:9002
- User: `chat4all_admin`
- Password: `chat4all_minio_pass`

## Estrutura de Armazenamento

```
chat4all-files/
├── {conversation_id}/
│   ├── {file_id}/
│   │   └── {filename}
│   ├── {file_id}/
│   │   └── {filename}
```

Exemplo:
```
chat4all-files/
├── 33333333-3333-3333-3333-333333333333/
│   ├── f9e8d7c6-b5a4-3210-9876-543210fedcba/
│   │   └── documento.pdf
│   ├── a1b2c3d4-e5f6-7890-abcd-ef1234567890/
│   │   └── foto.jpg
```

## Limites e Restrições

- **Tamanho máximo de arquivo**: 2GB (2.147.483.648 bytes)
- **Tamanho de cada parte**: 5MB (5.242.880 bytes)
- **Número máximo de partes**: 400 (calculado automaticamente)
- **Tempo de expiração da URL**: 1 hora (3600 segundos)
- **Tipos de arquivo**: Todos (sem restrição de MIME type)

## Segurança

### Permissões

- ✅ Apenas membros da conversa podem fazer upload
- ✅ Apenas membros da conversa podem baixar arquivos
- ✅ Apenas o dono pode deletar o arquivo
- ✅ URLs temporárias expiram após 1 hora

### Validação

- ✅ Verificação de tamanho de arquivo
- ✅ Sanitização de nomes de arquivo
- ✅ Validação de checksum (opcional)
- ✅ Verificação de partes completas

## Banco de Dados

### Tabela: files

```sql
CREATE TABLE files (
    file_id UUID PRIMARY KEY,
    upload_id UUID NOT NULL,
    conversation_id UUID NOT NULL,
    user_id UUID NOT NULL,
    username VARCHAR(255) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL,
    content_type VARCHAR(100) NOT NULL,
    storage_path TEXT NOT NULL,
    checksum VARCHAR(64),
    status VARCHAR(20) NOT NULL DEFAULT 'uploading',
    minio_upload_id TEXT,
    total_parts INT NOT NULL,
    uploaded_parts INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Tabela: file_parts

```sql
CREATE TABLE file_parts (
    file_id UUID NOT NULL,
    part_number INT NOT NULL,
    etag VARCHAR(255) NOT NULL,
    bytes_uploaded BIGINT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (file_id, part_number)
);
```

## Status do Arquivo

- **uploading**: Upload em progresso
- **completed**: Upload concluído com sucesso
- **failed**: Erro durante o upload
- **aborted**: Upload cancelado pelo usuário
- **deleted**: Arquivo deletado

## Tratamento de Erros

### Erros Comuns

| Código | Erro | Solução |
|--------|------|---------|
| 400 | Tamanho inválido | Verificar se file_size > 0 e < 2GB |
| 400 | Upload incompleto | Enviar todas as partes antes de completar |
| 403 | Sem permissão | Usuário não pertence à conversa |
| 404 | Arquivo não encontrado | Verificar se file_id está correto |
| 500 | Erro no MinIO | Verificar se MinIO está rodando |

### Retry de Partes

Se uma parte falhar, você pode reenviá-la:

```bash
# Reenviar parte 3
curl -X POST http://localhost:8080/v1/files/upload/part \
  -H "Authorization: Bearer $TOKEN" \
  -F "upload_id=$UPLOAD_ID" \
  -F "file_id=$FILE_ID" \
  -F "part_number=3" \
  -F "data=@parte3.bin"
```

## Cancelamento de Upload

Para cancelar um upload e liberar recursos:

```bash
curl -X POST http://localhost:8080/v1/files/upload/abort \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "upload_id": "$UPLOAD_ID",
    "file_id": "$FILE_ID"
  }'
```

## Listagem de Arquivos

### Listar arquivos de uma conversa

```bash
curl -X GET "http://localhost:8080/v1/conversations/$CONV_ID/files?limit=20&file_type=image" \
  -H "Authorization: Bearer $TOKEN"
```

**Filtros disponíveis:**
- `limit`: Quantidade de arquivos (padrão: 20, máximo: 100)
- `offset`: Paginação (padrão: 0)
- `file_type`: Filtro por tipo (image, video, document, etc.)

## Performance

### Upload de 100MB

- **Tempo estimado**: ~10 segundos (com conexão de 100 Mbps)
- **Partes**: 20 partes de 5MB cada
- **Requests HTTP**: 22 (1 initiate + 20 parts + 1 complete)

### Upload de 1GB

- **Tempo estimado**: ~1.5 minutos (com conexão de 100 Mbps)
- **Partes**: 200 partes de 5MB cada
- **Requests HTTP**: 202 (1 initiate + 200 parts + 1 complete)

## Monitoramento

### Ver uploads em progresso

```sql
SELECT 
    file_id,
    filename,
    file_size,
    uploaded_parts,
    total_parts,
    ROUND((uploaded_parts::float / total_parts) * 100, 2) as progress_pct,
    created_at
FROM files
WHERE status = 'uploading'
ORDER BY created_at DESC;
```

### Ver uploads completados hoje

```sql
SELECT 
    f.file_id,
    f.filename,
    f.file_size,
    u.username,
    f.created_at,
    f.updated_at
FROM files f
JOIN users u ON f.user_id = u.user_id
WHERE f.status = 'completed'
    AND f.created_at::date = CURRENT_DATE
ORDER BY f.created_at DESC;
```

## Próximas Melhorias

- [ ] Suporte a resumable upload (salvar progresso)
- [ ] Geração automática de thumbnails para imagens
- [ ] Compressão de imagens
- [ ] Análise de vírus (antivirus scan)
- [ ] Limite de armazenamento por usuário/conversa
- [ ] Expiração automática de arquivos antigos
- [ ] Cache de URLs de download no Redis
- [ ] Upload paralelo de múltiplas partes

## Dependências

### PHP

- `aws/aws-sdk-php`: ^3.0 - Cliente S3 para MinIO
- `slim/slim`: ^4.0 - Framework REST
- `monolog/monolog`: ^3.0 - Logging

### Serviços

- **MinIO**: Latest - Object Storage
- **PostgreSQL**: 16 - Metadados
- **Redis**: 7 - Cache (futuro)

## Referências

- [MinIO Documentation](https://min.io/docs/)
- [AWS S3 Multipart Upload](https://docs.aws.amazon.com/AmazonS3/latest/dev/mpuoverview.html)
- [AWS SDK for PHP](https://docs.aws.amazon.com/sdk-for-php/)
