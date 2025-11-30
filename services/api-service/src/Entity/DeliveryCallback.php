<?php

declare(strict_types=1);

namespace Chat4All\Api\Entity;

/**
 * DeliveryCallback - Representa um callback de status de entrega
 * 
 * Armazena o histórico de callbacks recebidos dos conectores
 * para cada mensagem, permitindo rastrear o progresso de entrega.
 */
class DeliveryCallback
{
    private string $id;
    private string $messageId;
    private string $status;
    private string $connector;
    private \DateTimeInterface $receivedAt;
    private ?string $connectorTimestamp;
    private array $metadata;

    /**
     * Status possíveis
     */
    public const STATUS_SENT = 'SENT';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_READ = 'READ';
    public const STATUS_FAILED = 'FAILED';

    /**
     * Conectores suportados
     */
    public const CONNECTOR_WHATSAPP = 'whatsapp';
    public const CONNECTOR_INSTAGRAM = 'instagram';

    public function __construct(
        string $id,
        string $messageId,
        string $status,
        string $connector,
        ?\DateTimeInterface $receivedAt = null,
        ?string $connectorTimestamp = null,
        array $metadata = []
    ) {
        $this->id = $id;
        $this->messageId = $messageId;
        $this->setStatus($status);
        $this->setConnector($connector);
        $this->receivedAt = $receivedAt ?? new \DateTime();
        $this->connectorTimestamp = $connectorTimestamp;
        $this->metadata = $metadata;
    }

    /**
     * Cria instância a partir de array do banco
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['message_id'],
            $data['status'],
            $data['connector'],
            isset($data['received_at']) ? new \DateTime($data['received_at']) : null,
            $data['connector_timestamp'] ?? null,
            isset($data['metadata']) ? json_decode($data['metadata'], true) : []
        );
    }

    /**
     * Converte para array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->messageId,
            'status' => $this->status,
            'connector' => $this->connector,
            'received_at' => $this->receivedAt->format('c'),
            'connector_timestamp' => $this->connectorTimestamp,
            'metadata' => $this->metadata
        ];
    }

    // Getters

    public function getId(): string
    {
        return $this->id;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getConnector(): string
    {
        return $this->connector;
    }

    public function getReceivedAt(): \DateTimeInterface
    {
        return $this->receivedAt;
    }

    public function getConnectorTimestamp(): ?string
    {
        return $this->connectorTimestamp;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMetadataValue(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    // Setters com validação

    private function setStatus(string $status): void
    {
        $validStatuses = [
            self::STATUS_SENT,
            self::STATUS_DELIVERED,
            self::STATUS_READ,
            self::STATUS_FAILED
        ];

        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException(
                "Status inválido: {$status}. Valores aceitos: " . implode(', ', $validStatuses)
            );
        }

        $this->status = $status;
    }

    private function setConnector(string $connector): void
    {
        $validConnectors = [
            self::CONNECTOR_WHATSAPP,
            self::CONNECTOR_INSTAGRAM
        ];

        if (!in_array($connector, $validConnectors)) {
            throw new \InvalidArgumentException(
                "Connector inválido: {$connector}. Valores aceitos: " . implode(', ', $validConnectors)
            );
        }

        $this->connector = $connector;
    }

    // Helpers

    /**
     * Verifica se é um status de sucesso
     */
    public function isSuccessful(): bool
    {
        return in_array($this->status, [
            self::STATUS_SENT,
            self::STATUS_DELIVERED,
            self::STATUS_READ
        ]);
    }

    /**
     * Verifica se é um status final
     */
    public function isFinalStatus(): bool
    {
        return in_array($this->status, [
            self::STATUS_READ,
            self::STATUS_FAILED
        ]);
    }

    /**
     * Retorna emoji representativo do status
     */
    public function getStatusEmoji(): string
    {
        return match ($this->status) {
            self::STATUS_SENT => '✓',
            self::STATUS_DELIVERED => '✓✓',
            self::STATUS_READ => '✓✓', // azul no frontend
            self::STATUS_FAILED => '❌',
            default => '?'
        };
    }

    /**
     * Retorna descrição legível do status
     */
    public function getStatusDescription(): string
    {
        return match ($this->status) {
            self::STATUS_SENT => 'Mensagem enviada',
            self::STATUS_DELIVERED => 'Mensagem entregue',
            self::STATUS_READ => 'Mensagem lida',
            self::STATUS_FAILED => 'Falha no envio',
            default => 'Status desconhecido'
        };
    }
}
