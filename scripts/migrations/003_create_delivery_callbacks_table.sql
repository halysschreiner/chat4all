-- ================================================
-- Migration: Criar tabela DELIVERY_CALLBACKS
-- Chat4All - Sistema de Mensagens Distribuído
-- ================================================
-- Esta tabela registra callbacks de entrega 
-- recebidos dos conectores (WhatsApp, Instagram).
-- ================================================

-- Criar extensão para UUID se não existir
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ================================================
-- Tabela: delivery_callbacks
-- ================================================
-- Registra todos os callbacks de status de entrega
-- recebidos dos conectores de plataforma.
-- ================================================
CREATE TABLE IF NOT EXISTS delivery_callbacks (
    -- Identificador único do callback (UUID v4)
    callback_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    
    -- ID da mensagem relacionada
    message_id UUID NOT NULL,
    
    -- ID do usuário dono da mensagem
    user_id UUID NOT NULL,
    
    -- ID da conversa relacionada
    conversation_id UUID,
    
    -- Plataforma de origem: whatsapp, instagram
    platform VARCHAR(50) NOT NULL,
    
    -- ID externo na plataforma (ex: WhatsApp message ID)
    external_message_id VARCHAR(255),
    
    -- Status do callback: sent, delivered, read, failed
    status VARCHAR(50) NOT NULL,
    
    -- Status anterior (para auditoria)
    previous_status VARCHAR(50),
    
    -- Timestamp do evento na plataforma
    platform_timestamp TIMESTAMP WITH TIME ZONE,
    
    -- Código de erro (se status = failed)
    error_code VARCHAR(50),
    
    -- Mensagem de erro detalhada
    error_message TEXT,
    
    -- Dados raw do callback (JSON)
    raw_payload JSONB,
    
    -- Metadados adicionais (JSON)
    metadata JSONB,
    
    -- Se já foi processado
    processed BOOLEAN DEFAULT FALSE,
    
    -- Timestamp de processamento
    processed_at TIMESTAMP WITH TIME ZONE,
    
    -- Timestamps
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ================================================
-- Índices para performance
-- ================================================

-- Busca por message_id (principal)
CREATE INDEX IF NOT EXISTS idx_callbacks_message_id ON delivery_callbacks(message_id);

-- Busca por user_id (listar callbacks de um usuário)
CREATE INDEX IF NOT EXISTS idx_callbacks_user_id ON delivery_callbacks(user_id);

-- Busca por conversation_id
CREATE INDEX IF NOT EXISTS idx_callbacks_conversation_id ON delivery_callbacks(conversation_id);

-- Busca por plataforma
CREATE INDEX IF NOT EXISTS idx_callbacks_platform ON delivery_callbacks(platform);

-- Busca por status
CREATE INDEX IF NOT EXISTS idx_callbacks_status ON delivery_callbacks(status);

-- Busca por data de criação (ordenação cronológica)
CREATE INDEX IF NOT EXISTS idx_callbacks_created_at ON delivery_callbacks(created_at DESC);

-- Busca por callbacks não processados
CREATE INDEX IF NOT EXISTS idx_callbacks_processed ON delivery_callbacks(processed) WHERE processed = FALSE;

-- Busca composta: message_id + status
CREATE INDEX IF NOT EXISTS idx_callbacks_message_status ON delivery_callbacks(message_id, status);

-- Busca por external_message_id (para deduplicação)
CREATE INDEX IF NOT EXISTS idx_callbacks_external_id ON delivery_callbacks(external_message_id) WHERE external_message_id IS NOT NULL;

-- ================================================
-- Trigger para atualizar updated_at
-- ================================================
CREATE OR REPLACE FUNCTION update_delivery_callbacks_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trigger_delivery_callbacks_updated_at ON delivery_callbacks;
CREATE TRIGGER trigger_delivery_callbacks_updated_at
    BEFORE UPDATE ON delivery_callbacks
    FOR EACH ROW
    EXECUTE FUNCTION update_delivery_callbacks_updated_at();

-- ================================================
-- Comentários na tabela
-- ================================================
COMMENT ON TABLE delivery_callbacks IS 'Callbacks de status de entrega recebidos dos conectores';
COMMENT ON COLUMN delivery_callbacks.callback_id IS 'Identificador único do callback (UUID v4)';
COMMENT ON COLUMN delivery_callbacks.message_id IS 'ID da mensagem relacionada';
COMMENT ON COLUMN delivery_callbacks.user_id IS 'ID do usuário dono da mensagem';
COMMENT ON COLUMN delivery_callbacks.platform IS 'Plataforma de origem: whatsapp, instagram';
COMMENT ON COLUMN delivery_callbacks.external_message_id IS 'ID da mensagem na plataforma externa';
COMMENT ON COLUMN delivery_callbacks.status IS 'Status: sent, delivered, read, failed';
COMMENT ON COLUMN delivery_callbacks.previous_status IS 'Status anterior para auditoria';
COMMENT ON COLUMN delivery_callbacks.platform_timestamp IS 'Timestamp do evento na plataforma';
COMMENT ON COLUMN delivery_callbacks.error_code IS 'Código de erro se status = failed';
COMMENT ON COLUMN delivery_callbacks.error_message IS 'Mensagem de erro detalhada';
COMMENT ON COLUMN delivery_callbacks.raw_payload IS 'Dados raw do callback em JSON';
COMMENT ON COLUMN delivery_callbacks.metadata IS 'Metadados adicionais em JSON';
COMMENT ON COLUMN delivery_callbacks.processed IS 'Se o callback já foi processado';
