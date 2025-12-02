<?php
/**
 * ================================================
 * MessageProcessor - Router Worker
 * Chat4All - Sistema de Mensagens Distribuído
 * ================================================
 * 
 * Processador de mensagens que roteia para os
 * conectores de plataforma (WhatsApp, Instagram)
 * via tópicos Kafka específicos.
 * 
 * @package Chat4All\Worker
 * @author  Chat4All Team
 * @since   1.0.0
 * ================================================
 */

namespace Chat4All\Worker;

use Monolog\Logger;

class MessageProcessor
{
    private Database $database;
    private Logger $logger;
    
    /**
     * Producer Kafka para publicar em tópicos de plataforma
     * @var \RdKafka\Producer|null
     */
    private ?\RdKafka\Producer $producer = null;
    
    /**
     * Brokers Kafka
     * @var string
     */
    private string $kafkaBrokers;

    /**
     * Construtor do processador
     * 
     * @param Database $database Conexão com banco
     * @param Logger $logger Logger
     * @param string|null $kafkaBrokers Brokers Kafka (opcional)
     */
    public function __construct(Database $database, Logger $logger, ?string $kafkaBrokers = null)
    {
        $this->database = $database;
        $this->logger = $logger;
        $this->kafkaBrokers = $kafkaBrokers ?? (getenv('KAFKA_BROKERS') ?: 'kafka:9093');
        
        $this->initProducer();
    }

    /**
     * Inicializa producer Kafka para publicar em tópicos de plataforma
     */
    private function initProducer(): void
    {
        try {
            $conf = new \RdKafka\Conf();
            $conf->set('metadata.broker.list', $this->kafkaBrokers);
            $conf->set('socket.timeout.ms', '5000');
            
            $this->producer = new \RdKafka\Producer($conf);
            
            $this->logger->info('Kafka producer inicializado', [
                'brokers' => $this->kafkaBrokers,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Falha ao inicializar Kafka producer', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Processa mensagem recebida do Kafka
     * 
     * Fluxo:
     * 1. Decodificar payload JSON
     * 2. Validar campos obrigatórios
     * 3. Determinar plataforma de destino
     * 4. Publicar no tópico da plataforma
     * 5. Registrar auditoria
     * 
     * @param string $payload JSON da mensagem
     */
    public function process(string $payload): void
    {
        // Decodificar JSON
        $message = json_decode($payload, true);

        if (!$message) {
            $this->logger->error('Payload JSON inválido', ['payload' => substr($payload, 0, 100)]);
            return;
        }

        $messageId = $message['message_id'] ?? 'unknown';
        $conversationId = $message['conversation_id'] ?? 'unknown';

        $this->logger->info('Processando mensagem', [
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'has_file' => isset($message['file_id']),
        ]);

        // Validar dados obrigatórios
        if (!isset($message['message_id']) || !isset($message['conversation_id'])) {
            $this->logger->error('Campos obrigatórios ausentes', ['message' => $message]);
            return;
        }

        try {
            // Determinar plataforma de destino
            $platform = $this->detectPlatform($message);
            
            $this->logger->info('Plataforma detectada', [
                'message_id' => $messageId,
                'platform' => $platform,
            ]);

            // Rotear para tópico da plataforma
            $this->routeToPlatform($message, $platform);

            // Log de auditoria
            $this->database->insertAuditLog(
                'message.routed',
                'message',
                $messageId,
                $message['from_user_id'] ?? null,
                [
                    'conversation_id' => $conversationId,
                    'platform' => $platform,
                    'processed_by' => 'router-worker',
                    'has_file' => isset($message['file_id']),
                ]
            );

            $this->logger->info('Mensagem roteada com sucesso', [
                'message_id' => $messageId,
                'platform' => $platform,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Erro ao processar mensagem', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            // Atualizar status para FAILED
            try {
                $this->database->updateMessageStatus($messageId, 'FAILED');
            } catch (\Exception $updateException) {
                $this->logger->error('Falha ao atualizar status para FAILED', [
                    'error' => $updateException->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Detecta plataforma de destino baseado nos metadados
     * 
     * Lógica de detecção:
     * 1. Campo explícito 'platform' na mensagem
     * 2. Metadados da conversa
     * 3. Alternância round-robin (para demo)
     * 
     * @param array $message Dados da mensagem
     * @return string Plataforma (whatsapp, instagram)
     */
    private function detectPlatform(array $message): string
    {
        // 1. Verificar campo explícito
        if (isset($message['platform'])) {
            return strtolower($message['platform']);
        }

        // 2. Verificar metadados da conversa
        if (isset($message['conversation_id'])) {
            $conversationMeta = $this->database->getConversationMetadata($message['conversation_id']);
            if ($conversationMeta && isset($conversationMeta['platform'])) {
                return strtolower($conversationMeta['platform']);
            }
        }

        // 3. Para demonstração: alternar entre plataformas
        // baseado no hash do message_id
        $hash = crc32($message['message_id']);
        return ($hash % 2 === 0) ? 'whatsapp' : 'instagram';
    }

    /**
     * Roteia mensagem para tópico Kafka da plataforma
     * 
     * @param array $message Dados da mensagem
     * @param string $platform Plataforma de destino
     */
    private function routeToPlatform(array $message, string $platform): void
    {
        if (!$this->producer) {
            $this->logger->warning('Producer não disponível, simulando roteamento');
            usleep(100000); // Simular delay
            return;
        }

        // Mapear plataforma para tópico Kafka
        $topic = match ($platform) {
            'whatsapp' => 'whatsapp.messages',
            'instagram' => 'instagram.messages',
            default => 'messages.unknown',
        };

        // Adicionar metadados de roteamento
        $message['routed_at'] = date('c');
        $message['routed_to'] = $platform;
        $message['router_version'] = '1.0.0';

        try {
            $kafkaTopic = $this->producer->newTopic($topic);
            $kafkaTopic->produce(
                RD_KAFKA_PARTITION_UA, // Auto-partition
                0,
                json_encode($message),
                $message['conversation_id'] // Key para ordenação
            );
            
            // Flush para garantir envio
            $this->producer->poll(0);
            
            for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
                $result = $this->producer->flush(1000);
                if ($result === RD_KAFKA_RESP_ERR_NO_ERROR) {
                    break;
                }
            }

            $this->logger->info('Mensagem publicada no tópico', [
                'message_id' => $message['message_id'],
                'topic' => $topic,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Erro ao publicar mensagem no tópico', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
