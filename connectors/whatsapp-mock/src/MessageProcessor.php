<?php

namespace Chat4All\Connector\WhatsApp;

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
            $this->logger->info('[WhatsApp] 📥 Mensagem recebida do Kafka', [
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
        // Simular delay de envio (50-200ms)
        usleep(rand(50000, 200000));

        $this->logger->info("[WhatsApp] ✅ Entregue a usuário {$to}", [
            'message_id' => $messageId,
            'text' => substr($text, 0, 50) . (strlen($text) > 50 ? '...' : '')
        ]);
    }

    private function simulateDeliveryCallback(string $messageId, string $to): void
    {
        // Simular delay até entrega (1-3 segundos)
        sleep(rand(1, 3));

        $this->logger->info("[WhatsApp] 📬 Callback: DELIVERED", [
            'message_id' => $messageId,
            'to' => $to,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Aqui você poderia fazer um HTTP POST para o backend informando a entrega
        $this->sendCallbackToBackend($messageId, 'DELIVERED');
    }

    private function simulateReadCallback(string $messageId, string $to): void
    {
        // Simular delay até leitura (5-10 segundos após entrega)
        sleep(rand(5, 10));

        $this->logger->info("[WhatsApp] 👁️ Callback: READ", [
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
        $backendUrl = getenv('BACKEND_CALLBACK_URL') ?: 'http://api-service:8080/v1/callbacks/whatsapp';

        $payload = [
            'message_id' => $messageId,
            'status' => $status,
            'timestamp' => time(),
            'connector' => 'whatsapp'
        ];

        // Por enquanto apenas logamos. Você pode implementar o HTTP POST se necessário.
        $this->logger->debug('[WhatsApp] Would send callback to backend', [
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
                $this->logger->info('[WhatsApp] Callback sent successfully');
            } else {
                $this->logger->warning('[WhatsApp] Callback failed', ['http_code' => $httpCode]);
            }
        } catch (\Exception $e) {
            $this->logger->error('[WhatsApp] Error sending callback: ' . $e->getMessage());
        }
        */
    }
}
