<?php
/**
 * ================================================
 * MetricsService - Chat4All API Service
 * ================================================
 * 
 * Serviço para coleta e exposição de métricas
 * no formato Prometheus.
 * 
 * Métricas coletadas:
 * - Requisições HTTP (contagem, latência)
 * - Mensagens processadas
 * - Arquivos uploadados
 * - Callbacks recebidos
 * - Conexões WebSocket
 * 
 * @package Chat4All\Api\Service
 * @author  Chat4All Team
 * @since   1.0.0
 * ================================================
 */

namespace Chat4All\Api\Service;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\Redis;
use Prometheus\RenderTextFormat;
use Monolog\Logger;

class MetricsService
{
    /**
     * Registry do Prometheus
     * @var CollectorRegistry
     */
    private CollectorRegistry $registry;

    /**
     * Logger para debug
     * @var Logger
     */
    private Logger $logger;

    /**
     * Prefixo para métricas
     * @var string
     */
    private string $namespace = 'chat4all';

    /**
     * Construtor do serviço
     * 
     * @param string $redisHost Host do Redis
     * @param int $redisPort Porta do Redis
     * @param Logger $logger Logger para debug
     */
    public function __construct(string $redisHost, int $redisPort, Logger $logger)
    {
        $this->logger = $logger;

        try {
            // Usar Redis como backend para métricas (compartilhado entre processos)
            $adapter = new Redis([
                'host' => $redisHost,
                'port' => $redisPort,
                'database' => 1, // Database separado para métricas
            ]);

            $this->registry = new CollectorRegistry($adapter);

            $this->logger->info('MetricsService inicializado', [
                'redis' => $redisHost . ':' . $redisPort,
            ]);

            // Registrar métricas padrão
            $this->registerDefaultMetrics();

        } catch (\Exception $e) {
            $this->logger->error('Falha ao inicializar MetricsService', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Registra métricas padrão do sistema
     */
    private function registerDefaultMetrics(): void
    {
        // Contador de requisições HTTP
        $this->registry->getOrRegisterCounter(
            $this->namespace,
            'http_requests_total',
            'Total de requisições HTTP',
            ['method', 'endpoint', 'status']
        );

        // Histograma de latência HTTP
        $this->registry->getOrRegisterHistogram(
            $this->namespace,
            'http_request_duration_seconds',
            'Duração das requisições HTTP em segundos',
            ['method', 'endpoint'],
            [0.01, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]
        );

        // Contador de mensagens processadas
        $this->registry->getOrRegisterCounter(
            $this->namespace,
            'messages_processed_total',
            'Total de mensagens processadas',
            ['platform', 'status']
        );

        // Contador de arquivos uploadados
        $this->registry->getOrRegisterCounter(
            $this->namespace,
            'files_uploaded_total',
            'Total de arquivos uploadados',
            ['status']
        );

        // Histograma de tamanho de arquivos
        $this->registry->getOrRegisterHistogram(
            $this->namespace,
            'file_size_bytes',
            'Tamanho dos arquivos em bytes',
            [],
            [1024, 10240, 102400, 1048576, 10485760, 104857600] // 1KB, 10KB, 100KB, 1MB, 10MB, 100MB
        );

        // Contador de callbacks recebidos
        $this->registry->getOrRegisterCounter(
            $this->namespace,
            'delivery_callbacks_total',
            'Total de callbacks de entrega recebidos',
            ['platform', 'status']
        );

        // Gauge de conexões WebSocket ativas
        $this->registry->getOrRegisterGauge(
            $this->namespace,
            'websocket_connections_active',
            'Conexões WebSocket ativas'
        );

        // Contador de erros
        $this->registry->getOrRegisterCounter(
            $this->namespace,
            'errors_total',
            'Total de erros',
            ['type', 'service']
        );
    }

    /**
     * Incrementa contador de requisições HTTP
     * 
     * @param string $method Método HTTP
     * @param string $endpoint Endpoint
     * @param int $status Status HTTP
     */
    public function incrementHttpRequests(string $method, string $endpoint, int $status): void
    {
        try {
            $counter = $this->registry->getCounter($this->namespace, 'http_requests_total');
            $counter->inc([$method, $endpoint, (string)$status]);
        } catch (\Exception $e) {
            $this->logger->debug('Erro ao incrementar http_requests_total', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Registra duração de requisição HTTP
     * 
     * @param string $method Método HTTP
     * @param string $endpoint Endpoint
     * @param float $durationSeconds Duração em segundos
     */
    public function observeHttpDuration(string $method, string $endpoint, float $durationSeconds): void
    {
        try {
            $histogram = $this->registry->getHistogram($this->namespace, 'http_request_duration_seconds');
            $histogram->observe($durationSeconds, [$method, $endpoint]);
        } catch (\Exception $e) {
            $this->logger->debug('Erro ao registrar http_request_duration_seconds', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Incrementa contador de mensagens processadas
     * 
     * @param string $platform Plataforma (whatsapp, instagram)
     * @param string $status Status (sent, delivered, read, failed)
     */
    public function incrementMessagesProcessed(string $platform, string $status): void
    {
        try {
            $counter = $this->registry->getCounter($this->namespace, 'messages_processed_total');
            $counter->inc([$platform, $status]);
        } catch (\Exception $e) {
            $this->logger->debug('Erro ao incrementar messages_processed_total', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Incrementa contador de arquivos uploadados
     * 
     * @param string $status Status (completed, failed)
     */
    public function incrementFilesUploaded(string $status): void
    {
        try {
            $counter = $this->registry->getCounter($this->namespace, 'files_uploaded_total');
            $counter->inc([$status]);
        } catch (\Exception $e) {
            $this->logger->debug('Erro ao incrementar files_uploaded_total', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Registra tamanho de arquivo
     * 
     * @param int $sizeBytes Tamanho em bytes
     */
    public function observeFileSize(int $sizeBytes): void
    {
        try {
            $histogram = $this->registry->getHistogram($this->namespace, 'file_size_bytes');
            $histogram->observe($sizeBytes, []);
        } catch (\Exception $e) {
            $this->logger->debug('Erro ao registrar file_size_bytes', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Incrementa contador de callbacks
     * 
     * @param string $platform Plataforma
     * @param string $status Status do callback
     */
    public function incrementDeliveryCallbacks(string $platform, string $status): void
    {
        try {
            $counter = $this->registry->getCounter($this->namespace, 'delivery_callbacks_total');
            $counter->inc([$platform, $status]);
        } catch (\Exception $e) {
            $this->logger->debug('Erro ao incrementar delivery_callbacks_total', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Atualiza gauge de conexões WebSocket
     * 
     * @param int $count Número de conexões ativas
     */
    public function setWebSocketConnections(int $count): void
    {
        try {
            $gauge = $this->registry->getGauge($this->namespace, 'websocket_connections_active');
            $gauge->set($count);
        } catch (\Exception $e) {
            $this->logger->debug('Erro ao setar websocket_connections_active', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Incrementa contador de erros
     * 
     * @param string $type Tipo de erro
     * @param string $service Serviço que gerou o erro
     */
    public function incrementErrors(string $type, string $service): void
    {
        try {
            $counter = $this->registry->getCounter($this->namespace, 'errors_total');
            $counter->inc([$type, $service]);
        } catch (\Exception $e) {
            $this->logger->debug('Erro ao incrementar errors_total', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retorna métricas no formato Prometheus
     * 
     * @return string Métricas formatadas
     */
    public function render(): string
    {
        try {
            $renderer = new RenderTextFormat();
            return $renderer->render($this->registry->getMetricFamilySamples());
        } catch (\Exception $e) {
            $this->logger->error('Erro ao renderizar métricas', [
                'error' => $e->getMessage(),
            ]);
            return "# Error rendering metrics: " . $e->getMessage();
        }
    }

    /**
     * Retorna o Registry (para uso avançado)
     * 
     * @return CollectorRegistry
     */
    public function getRegistry(): CollectorRegistry
    {
        return $this->registry;
    }
}
