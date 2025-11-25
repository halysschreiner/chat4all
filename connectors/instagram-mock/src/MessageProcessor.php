<?php

namespace Chat4All\Connector\Instagram;

use Psr\Log\LoggerInterface;

class MessageProcessor
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function process(string $payload): void
    {
        try {
            $data = json_decode($payload, true);

            if (!$data) {
                $this->logger->error('Invalid JSON payload');
                return;
            }

            $messageId = $data['message_id'] ?? 'unknown';
            $to = $data['to'] ?? 'unknown';
            $text = $data['text'] ?? '';

            // Log de recebimento
            $this->logger->info('[Instagram] 📥 Mensagem recebida do Kafka', [
                'message_id' => $messageId,
                'to' => $to
            ]);

            // Simular processamento e envio
            $this->simulateSending($messageId, $to, $text);

            // Simular callbacks de status
            $this->simulateDeliveryCallback($messageId, $to);
            $this->simulateReadCallback($messageId, $to);

        } catch (\Exception $e) {
            $this->logger->error('Error processing message: ' . $e->getMessage());
        }
    }

    private function simulateSending(string $messageId, string $to, string $text): void
    {
        // Simular delay de envio (100-300ms)
        usleep(rand(100000, 300000));

        $this->logger->info("[Instagram] ✅ Entregue a usuário {$to}", [
            'message_id' => $messageId,
            'text' => substr($text, 0, 50) . (strlen($text) > 50 ? '...' : '')
        ]);
    }

    private function simulateDeliveryCallback(string $messageId, string $to): void
    {
        // Simular delay até entrega (2-4 segundos)
        sleep(rand(2, 4));

        $this->logger->info("[Instagram] 📬 Callback: DELIVERED", [
            'message_id' => $messageId,
            'to' => $to,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Aqui você poderia fazer um HTTP POST para o backend informando a entrega
        $this->sendCallbackToBackend($messageId, 'DELIVERED');
    }

    private function simulateReadCallback(string $messageId, string $to): void
    {
        // Simular delay até leitura (8-15 segundos após entrega)
        sleep(rand(8, 15));

        $this->logger->info("[Instagram] 👁️ Callback: READ", [
            'message_id' => $messageId,
            'to' => $to,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Aqui você poderia fazer um HTTP POST para o backend informando a leitura
        $this->sendCallbackToBackend($messageId, 'READ');
    }

    private function sendCallbackToBackend(string $messageId, string $status): void
    {
        // Simulação de callback - em produção faria HTTP POST para API
        $backendUrl = getenv('BACKEND_CALLBACK_URL') ?: 'http://api-service:8080/v1/callbacks/instagram';

        $payload = [
            'message_id' => $messageId,
            'status' => $status,
            'timestamp' => time(),
            'connector' => 'instagram'
        ];

        // Por enquanto apenas logamos. Você pode implementar o HTTP POST se necessário.
        $this->logger->debug('[Instagram] Would send callback to backend', [
            'url' => $backendUrl,
            'payload' => $payload
        ]);

        // Exemplo de implementação real (descomente se quiser):
        /*
        try {
            $ch = curl_init($backendUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $this->logger->info('[Instagram] Callback sent successfully');
            } else {
                $this->logger->warning('[Instagram] Callback failed', ['http_code' => $httpCode]);
            }
        } catch (\Exception $e) {
            $this->logger->error('[Instagram] Error sending callback: ' . $e->getMessage());
        }
        */
    }
}
