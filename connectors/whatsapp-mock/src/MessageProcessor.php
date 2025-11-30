<?php

declare(strict_types=1);

namespace Chat4All\Connector\WhatsApp;

use Psr\Log\LoggerInterface;

/**
 * MessageProcessor - Processa mensagens recebidas do Kafka
 * 
 * Simula o envio de mensagens via WhatsApp e dispara callbacks
 * de status (SENT, DELIVERED, READ) para o backend.
 */
class MessageProcessor
{
    private LoggerInterface $logger;
    private CallbackSender $callbackSender;
    
    // Configurações de delay simulado (em segundos)
    private int $deliveryMinDelay = 1;
    private int $deliveryMaxDelay = 3;
    private int $readMinDelay = 3;
    private int $readMaxDelay = 8;
    
    // Probabilidade de falha simulada (0.0 a 1.0)
    private float $failureProbability = 0.0;

    public function __construct(LoggerInterface $logger, ?CallbackSender $callbackSender = null)
    {
        $this->logger = $logger;
        $this->callbackSender = $callbackSender ?? new CallbackSender($logger);
        
        // Carregar configurações do ambiente
        $this->loadConfig();
    }
    
    /**
     * Carrega configurações do ambiente
     */
    private function loadConfig(): void
    {
        if ($val = getenv('DELIVERY_MIN_DELAY')) {
            $this->deliveryMinDelay = (int)$val;
        }
        if ($val = getenv('DELIVERY_MAX_DELAY')) {
            $this->deliveryMaxDelay = (int)$val;
        }
        if ($val = getenv('READ_MIN_DELAY')) {
            $this->readMinDelay = (int)$val;
        }
        if ($val = getenv('READ_MAX_DELAY')) {
            $this->readMaxDelay = (int)$val;
        }
        if ($val = getenv('FAILURE_PROBABILITY')) {
            $this->failureProbability = (float)$val;
        }
    }

    /**
     * Processa uma mensagem do Kafka
     */
    public function process(string $payload): void
    {
        try {
            $data = json_decode($payload, true);

            if (!$data) {
                $this->logger->error('[WhatsApp] ❌ Payload JSON inválido');
                return;
            }

            $messageId = $data['message_id'] ?? null;
            $to = $data['to'] ?? 'unknown';
            $text = $data['text'] ?? '';
            $fileId = $data['file_id'] ?? null;

            if (!$messageId) {
                $this->logger->error('[WhatsApp] ❌ message_id ausente no payload');
                return;
            }

            // Log de recebimento
            $this->logger->info('[WhatsApp] 📥 Mensagem recebida do Kafka', [
                'message_id' => $messageId,
                'to' => $to,
                'has_file' => $fileId !== null
            ]);

            // Simular falha aleatória se configurado
            if ($this->shouldSimulateFailure()) {
                $this->handleFailure($messageId, $to);
                return;
            }

            // Simular processamento e envio
            $this->simulateSending($messageId, $to, $text, $fileId);

            // Enviar callbacks de status via CallbackSender
            $this->sendDeliveryCallbacks($messageId, $to);

        } catch (\Exception $e) {
            $this->logger->error('[WhatsApp] ❌ Erro ao processar mensagem: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Simula o envio da mensagem
     */
    private function simulateSending(string $messageId, string $to, string $text, ?string $fileId): void
    {
        // Simular delay de envio (50-200ms)
        usleep(rand(50000, 200000));

        $logContext = [
            'message_id' => $messageId,
            'text' => substr($text, 0, 50) . (strlen($text) > 50 ? '...' : '')
        ];

        if ($fileId) {
            $logContext['file_id'] = $fileId;
            $this->logger->info("[WhatsApp] 📎 Mensagem com anexo enviada para {$to}", $logContext);
        } else {
            $this->logger->info("[WhatsApp] ✅ Mensagem enviada para {$to}", $logContext);
        }

        // Enviar callback SENT imediatamente
        $this->callbackSender->send($messageId, 'SENT', [
            'recipient' => $to,
            'has_attachment' => $fileId !== null
        ]);
    }

    /**
     * Envia callbacks de entrega e leitura com delays simulados
     */
    private function sendDeliveryCallbacks(string $messageId, string $to): void
    {
        // Callback DELIVERED (1-3 segundos)
        $this->callbackSender->sendDeliveredWithDelay(
            $messageId,
            $this->deliveryMinDelay,
            $this->deliveryMaxDelay
        );

        $this->logger->info("[WhatsApp] 📬 Callback DELIVERED enviado", [
            'message_id' => $messageId,
            'to' => $to
        ]);

        // Callback READ (3-8 segundos após DELIVERED)
        $this->callbackSender->sendReadWithDelay(
            $messageId,
            $this->readMinDelay,
            $this->readMaxDelay
        );

        $this->logger->info("[WhatsApp] 👁️ Callback READ enviado", [
            'message_id' => $messageId,
            'to' => $to
        ]);
    }

    /**
     * Verifica se deve simular falha
     */
    private function shouldSimulateFailure(): bool
    {
        if ($this->failureProbability <= 0.0) {
            return false;
        }
        
        return (mt_rand() / mt_getrandmax()) < $this->failureProbability;
    }

    /**
     * Trata falha simulada
     */
    private function handleFailure(string $messageId, string $to): void
    {
        $errorCodes = [
            'RECIPIENT_NOT_FOUND' => 'Destinatário não encontrado no WhatsApp',
            'RATE_LIMITED' => 'Taxa de envio excedida',
            'NETWORK_ERROR' => 'Erro de rede ao conectar com WhatsApp',
            'MEDIA_TOO_LARGE' => 'Arquivo muito grande para envio'
        ];

        $errorCode = array_rand($errorCodes);
        $errorMessage = $errorCodes[$errorCode];

        $this->logger->warning("[WhatsApp] ⚠️ Falha simulada ao enviar para {$to}", [
            'message_id' => $messageId,
            'error_code' => $errorCode,
            'error_message' => $errorMessage
        ]);

        $this->callbackSender->sendFailed($messageId, $errorCode, $errorMessage);
    }

    /**
     * Configura probabilidade de falha (para testes)
     */
    public function setFailureProbability(float $probability): void
    {
        $this->failureProbability = max(0.0, min(1.0, $probability));
    }

    /**
     * Configura delays de entrega (para testes)
     */
    public function setDeliveryDelays(int $minDelay, int $maxDelay): void
    {
        $this->deliveryMinDelay = $minDelay;
        $this->deliveryMaxDelay = $maxDelay;
    }

    /**
     * Configura delays de leitura (para testes)
     */
    public function setReadDelays(int $minDelay, int $maxDelay): void
    {
        $this->readMinDelay = $minDelay;
        $this->readMaxDelay = $maxDelay;
    }
}
