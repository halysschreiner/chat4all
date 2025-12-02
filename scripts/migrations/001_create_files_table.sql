-- ================================================
-- Migration: Criar tabela FILES
-- Chat4All - Sistema de Mensagens Distribuído
-- ================================================
-- Esta tabela armazena metadados de arquivos 
-- enviados através do sistema, incluindo 
-- referências ao armazenamento MinIO/S3.
-- ================================================

-- Criar extensão para UUID se não existir
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ================================================
-- Tabela: files
-- ================================================
-- Armazena metadados de arquivos uploadados.
-- Os arquivos em si são armazenados no MinIO (S3).
-- ================================================
CREATE TABLE IF NOT EXISTS files (
    -- Identificador único do arquivo (UUID v4)
    file_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    
    -- ID do usuário que fez o upload
    user_id UUID NOT NULL,
    
    -- Nome original do arquivo (como enviado pelo cliente)
    original_filename VARCHAR(255) NOT NULL,
    
    -- Nome do arquivo no storage (pode ser diferente do original)
    storage_filename VARCHAR(255) NOT NULL,
    
    -- Caminho/chave no bucket S3/MinIO
    storage_path VARCHAR(512) NOT NULL,
    
    -- Bucket onde o arquivo está armazenado
    bucket_name VARCHAR(100) NOT NULL DEFAULT 'chat4all-files',
    
    -- Tamanho do arquivo em bytes
    file_size BIGINT NOT NULL DEFAULT 0,
    
    -- MIME type do arquivo (ex: image/jpeg, application/pdf)
    mime_type VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
    
    -- Hash SHA-256 para verificação de integridade
    checksum VARCHAR(64),
    
    -- Status do upload: pending, uploading, completed, failed
    upload_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    
    -- Mensagem de erro (se upload falhou)
    error_message TEXT,
    
    -- Timestamps
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Soft delete
    deleted_at TIMESTAMP WITH TIME ZONE
);

-- ================================================
-- Índices para performance
-- ================================================

-- Busca por usuário (listar arquivos de um usuário)
CREATE INDEX IF NOT EXISTS idx_files_user_id ON files(user_id);

-- Busca por status (encontrar uploads pendentes/falhos)
CREATE INDEX IF NOT EXISTS idx_files_upload_status ON files(upload_status);

-- Busca por data de criação (ordenação cronológica)
CREATE INDEX IF NOT EXISTS idx_files_created_at ON files(created_at DESC);

-- Busca por checksum (verificar duplicatas)
CREATE INDEX IF NOT EXISTS idx_files_checksum ON files(checksum) WHERE checksum IS NOT NULL;

-- Filtrar arquivos não deletados
CREATE INDEX IF NOT EXISTS idx_files_deleted_at ON files(deleted_at) WHERE deleted_at IS NULL;

-- ================================================
-- Trigger para atualizar updated_at
-- ================================================
CREATE OR REPLACE FUNCTION update_files_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trigger_files_updated_at ON files;
CREATE TRIGGER trigger_files_updated_at
    BEFORE UPDATE ON files
    FOR EACH ROW
    EXECUTE FUNCTION update_files_updated_at();

-- ================================================
-- Comentários na tabela
-- ================================================
COMMENT ON TABLE files IS 'Metadados de arquivos uploadados para o sistema Chat4All';
COMMENT ON COLUMN files.file_id IS 'Identificador único do arquivo (UUID v4)';
COMMENT ON COLUMN files.user_id IS 'ID do usuário que fez o upload';
COMMENT ON COLUMN files.original_filename IS 'Nome original do arquivo enviado pelo cliente';
COMMENT ON COLUMN files.storage_filename IS 'Nome do arquivo no storage (pode incluir prefixos)';
COMMENT ON COLUMN files.storage_path IS 'Caminho completo no bucket S3/MinIO';
COMMENT ON COLUMN files.bucket_name IS 'Nome do bucket onde o arquivo está armazenado';
COMMENT ON COLUMN files.file_size IS 'Tamanho do arquivo em bytes';
COMMENT ON COLUMN files.mime_type IS 'Tipo MIME do arquivo';
COMMENT ON COLUMN files.checksum IS 'Hash SHA-256 para verificação de integridade';
COMMENT ON COLUMN files.upload_status IS 'Status: pending, uploading, completed, failed';
COMMENT ON COLUMN files.error_message IS 'Mensagem de erro caso upload tenha falhado';
