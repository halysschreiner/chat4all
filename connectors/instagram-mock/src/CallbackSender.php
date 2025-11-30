<?php

declare(strict_types=1);

namespace Chat4All\Connector\Instagram;

use Psr\Log\LoggerInterface;

/**
 * CallbackSender - Envia callbacks de status de entrega para o backend
 * 
 * Responsável por notificar o sistema principal sobre mudanças de status
 * das mensagens (SENT, DELIVERED, READ, FAILED).
 */
class CallbackSender
{
    private LoggerInterface $logger;
    private string $callbackUrl;
    private int $timeout;
    private int $maxRetries;
    private array $retryDelays = [1, 2, 4]; // Exponential backoff (seconds)

    public function __construct(
        LoggerInterface $logger,
        ?string $callbackUrl = null,
        int $timeout = 5,
        int $maxRetries = 3
    ) {
        $this->logger = $logger;
        $this->callbackUrl = $callbackUrl 
            ?? getenv('BACKEND_CALLBACK_URL') 
            ?: 'http://api-service:8080/v1/callbacks/status';
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
    }

    /**
     * Envia callback de status para o backend
     * 
     * @param string $messageId ID da mensagem
     * @param string $status Status da mensagem (SENT, DELIVERED, READ, FAILED)
     * @param array $metadata Dados adicionais do callback
     * @return bool True se enviado com sucesso
     */
    public function send(string $messageId, string $status, array $metadata = []): bool
    {
        $payload = [
            'message_id' => $messageId,
            'status' => $status,
            'connector' => 'instagram',
            'timestamp' => (new \DateTime())->format('c'),
            'metadata' => array_merge([
                'platform' => 'instagram',
                'instance' => gethostname(),
            ], $metadata)
        ];

        $this->logger->info('[Instagram] 📤 Enviando callback de status', [
            'message_id' => $messageId,
            'status' => $status,
            'url' => $this->callbackUrl
        ]);

        return $this->sendWithRetry($payload);
    }

    /**
     * Envia callback DELIVERED com delay simulado
     * Instagram tem delays típicos de 2-4 segundos
     * 
     * @param string $messageId ID da mensagem
     * @param int $minDelay Delay mínimo em segundos
     * @param int $maxDelay Delay máximo em segundos
     */
    public function sendDeliveredWithDelay(string $messageId, int $minDelay = 2, int $maxDelay = 4): bool
    {
        $delay = rand($minDelay, $maxDelay);
        
        $this->logger->debug('[Instagram] ⏳ Aguardando {delay}s antes de DELIVERED', [
            'message_id' => $messageId,
            'delay' => $delay
        ]);

        sleep($delay);

        return $this->send($messageId, 'DELIVERED', [
            'simulated_delay' => $delay
        ]);
    }

    /**
     * Envia callback READ com delay simulado
     * Instagram tem delays típicos de 5-12 segundos para leitura
     * 
     * @param string $messageId ID da mensagem
     * @param int $minDelay Delay mínimo em segundos
     * @param int $maxDelay Delay máximo em segundos
     */
    public function sendReadWithDelay(string $messageId, int $minDelay = 5, int $maxDelay = 12): bool
    {
        $delay = rand($minDelay, $maxDelay);
        
        $this->logger->debug('[Instagram] ⏳ Aguardando {delay}s antes de READ', [
            'message_id' => $messageId,
            'delay' => $delay
        ]);

        sleep($delay);

        return $this->send($messageId, 'READ', [
            'simulated_delay' => $delay
        ]);
    }

    /**
     * Envia callback FAILED
     * 
     * @param string $messageId ID da mensagem
     * @param string $errorCode Código do erro
     * @param string $errorMessage Mensagem de erro
     */
    public function sendFailed(string $messageId, string $errorCode, string $errorMessage): bool
    {
        return $this->send($messageId, 'FAILED', [
            'error_code' => $errorCode,
            'error_message' => $errorMessage
        ]);
    }

    /**
     * Envia o payload com retry exponencial
     */
    private function sendWithRetry(array $payload): bool
    {
        $attempt = 0;

        while ($attempt < $this->maxRetries) {
            try {
                $result = $this->doSend($payload);
                
                if ($result) {
                    $this->logger->info('[Instagram] ✅ Callback enviado com sucesso', [
                        'message_id' => $payload['message_id'],
                        'status' => $payload['status'],
                        'attempt' => $attempt + 1
                    ]);
                    return true;
                }
            } catch (\Exception $e) {
                $this->logger->warning('[Instagram] ⚠️ Falha ao enviar callback', [
                    'message_id' => $payload['message_id'],
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage()
                ]);
            }

            $attempt++;

            if ($attempt < $this->maxRetries) {
                $delay = $this->retryDelays[$attempt - 1] ?? 4;
                $this->logger->debug('[Instagram] 🔄 Retry em {delay}s', [
                    'attempt' => $attempt + 1,
                    'delay' => $delay
                ]);
                sleep($delay);
            }
        }

        $this->logger->error('[Instagram] ❌ Callback falhou após {maxRetries} tentativas', [
            'message_id' => $payload['message_id'],
            'status' => $payload['status'],
            'maxRetries' => $this->maxRetries
        ]);

        return false;
    }

    /**
     * Realiza o envio HTTP POST
     */
    private function doSend(array $payload): bool
    {
        $ch = curl_init($this->callbackUrl);
        
        if ($ch === false) {
            throw new \RuntimeException('Falha ao inicializar cURL');
        }

        $jsonPayload = json_encode($payload);
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Connector: instagram',
                'X-Message-ID: ' . $payload['message_id'],
                'User-Agent: Chat4All-Instagram-Connector/1.0'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            throw new \RuntimeException("cURL error: {$error}");
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        $this->logger->warning('[Instagram] HTTP {code} ao enviar callback', [
            'code' => $httpCode,
            'response' => substr((string)$response, 0, 200)
        ]);

        // Retry apenas para erros 5xx (servidor)
        if ($httpCode >= 500) {
            throw new \RuntimeException("HTTP {$httpCode}");
        }

        // Erros 4xx são definitivos (não faz retry)
        return false;
    }

    /**
     * Configura URL de callback em runtime
     */
    public function setCallbackUrl(string $url): void
    {
        $this->callbackUrl = $url;
    }

    /**
     * Retorna URL de callback configurada
     */
    public function getCallbackUrl(): string
    {
        return $this->callbackUrl;
    }
}
