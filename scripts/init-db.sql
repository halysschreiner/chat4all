-- Script de inicialização do banco de dados PostgreSQL
-- Chat4All - Sistema de Mensagens Distribuído

-- Extensão para gerar UUIDs
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ==================================================
-- TABELA: users
-- Armazena informações dos usuários do sistema
-- ==================================================
CREATE TABLE IF NOT EXISTS users (
    user_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    username VARCHAR(255) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'deleted'))
);

CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_username ON users(username);

-- ==================================================
-- TABELA: conversations
-- Armazena metadados das conversas
-- ==================================================
CREATE TABLE IF NOT EXISTS conversations (
    conversation_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    type VARCHAR(20) NOT NULL CHECK (type IN ('private', 'group')),
    created_by UUID NOT NULL REFERENCES users(user_id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    -- Última mensagem (desnormalizada para performance)
    last_message_id UUID,
    last_message_at TIMESTAMP,
    last_message_snippet TEXT,
    
    is_active BOOLEAN DEFAULT true
);

CREATE INDEX idx_conversations_created_by ON conversations(created_by);
CREATE INDEX idx_conversations_updated_at ON conversations(updated_at DESC);

-- ==================================================
-- TABELA: conversation_members
-- Relaciona usuários com conversas
-- ==================================================
CREATE TABLE IF NOT EXISTS conversation_members (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    conversation_id UUID NOT NULL REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    user_id UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    role VARCHAR(20) DEFAULT 'member' CHECK (role IN ('owner', 'admin', 'member')),
    joined_at TIMESTAMP DEFAULT NOW(),
    last_read_at TIMESTAMP,
    
    UNIQUE(conversation_id, user_id)
);

CREATE INDEX idx_conversation_members_conv ON conversation_members(conversation_id);
CREATE INDEX idx_conversation_members_user ON conversation_members(user_id);

-- ==================================================
-- TABELA: messages
-- Armazena as mensagens trocadas (simplificado para primeira versão)
-- ==================================================
CREATE TABLE IF NOT EXISTS messages (
    message_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    conversation_id UUID NOT NULL REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    from_user_id UUID NOT NULL REFERENCES users(user_id),
    
    -- Conteúdo
    message_type VARCHAR(20) DEFAULT 'text' CHECK (message_type IN ('text', 'file', 'image', 'video', 'audio')),
    content TEXT NOT NULL,
    
    -- Status da mensagem
    status VARCHAR(20) DEFAULT 'SENT' CHECK (status IN ('SENT', 'DELIVERED', 'READ', 'FAILED')),
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    delivered_at TIMESTAMP,
    read_at TIMESTAMP,
    
    -- Sequência para ordenação
    sequence_number BIGSERIAL,
    
    -- Resposta a outra mensagem
    reply_to_message_id UUID REFERENCES messages(message_id)
);

CREATE INDEX idx_messages_conversation ON messages(conversation_id, created_at DESC);
CREATE INDEX idx_messages_from_user ON messages(from_user_id, created_at DESC);
CREATE INDEX idx_messages_status ON messages(status);
CREATE INDEX idx_messages_sequence ON messages(conversation_id, sequence_number);

-- ==================================================
-- TABELA: audit_logs
-- Log de auditoria das operações
-- ==================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    log_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    event_type VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id UUID,
    user_id UUID REFERENCES users(user_id),
    details JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX idx_audit_logs_entity ON audit_logs(entity_type, entity_id);
CREATE INDEX idx_audit_logs_user ON audit_logs(user_id);
CREATE INDEX idx_audit_logs_created ON audit_logs(created_at DESC);

-- ==================================================
-- DADOS DE TESTE
-- Inserir usuários para testes
-- ==================================================

-- Senha: password123 (hash bcrypt gerado com password_hash())
INSERT INTO users (user_id, username, email, password_hash) VALUES
    ('11111111-1111-1111-1111-111111111111', 'alice', 'alice@chat4all.com', '$2y$10$De5AN/jDAfWZOyfUmKJSDeOusE5nR2FoiGtuOc9lSMOd/D3iEz83u'),
    ('22222222-2222-2222-2222-222222222222', 'bob', 'bob@chat4all.com', '$2y$10$De5AN/jDAfWZOyfUmKJSDeOusE5nR2FoiGtuOc9lSMOd/D3iEz83u')
ON CONFLICT (user_id) DO NOTHING;

-- Criar uma conversa de teste entre Alice e Bob
INSERT INTO conversations (conversation_id, type, created_by) VALUES
    ('33333333-3333-3333-3333-333333333333', 'private', '11111111-1111-1111-1111-111111111111')
ON CONFLICT (conversation_id) DO NOTHING;

-- Adicionar membros à conversa
INSERT INTO conversation_members (conversation_id, user_id, role) VALUES
    ('33333333-3333-3333-3333-333333333333', '11111111-1111-1111-1111-111111111111', 'owner'),
    ('33333333-3333-3333-3333-333333333333', '22222222-2222-2222-2222-222222222222', 'member')
ON CONFLICT (conversation_id, user_id) DO NOTHING;

-- Mensagem de log
DO $$
BEGIN
    RAISE NOTICE '✓ Banco de dados inicializado com sucesso!';
    RAISE NOTICE '✓ Usuários de teste criados: alice e bob (senha: password123)';
    RAISE NOTICE '✓ Conversa de teste criada entre os usuários';
END $$;
