<?php
/**
 * ================================================
 * RedisService - Chat4All API Service
 * ================================================
 * 
 * Serviço para operações com Redis incluindo:
 * - Cache de dados
 * - Pub/Sub para notificações em tempo real
 * - Filas de mensagens
 * 
 * @package Chat4All\Api\Service
 * @author  Chat4All Team
 * @since   1.0.0
 * ================================================
 */

namespace Chat4All\Api\Service;

use Predis\Client as RedisClient;
use Monolog\Logger;

class RedisService
{
    /**
     * Cliente Redis
     * @var RedisClient
     */
    private RedisClient $client;

    /**
     * Logger para debug
     * @var Logger
     */
    private Logger $logger;

    /**
     * Prefixo para chaves
     * @var string
     */
    private string $prefix = 'chat4all:';

    /**
     * TTL padrão em segundos (1 hora)
     * @var int
     */
    private int $defaultTtl = 3600;

    /**
     * Construtor do serviço
     * 
     * @param string $host Host do Redis
     * @param int $port Porta do Redis
     * @param Logger $logger Logger para debug
     */
    public function __construct(string $host, int $port, Logger $logger)
    {
        $this->logger = $logger;

        try {
            $this->client = new RedisClient([
                'scheme' => 'tcp',
                'host' => $host,
                'port' => $port,
            ]);

            // Testar conexão
            $this->client->ping();
            
            $this->logger->info('Redis conectado', [
                'host' => $host,
                'port' => $port,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Falha ao conectar ao Redis', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Armazena um valor no cache
     * 
     * @param string $key Chave
     * @param mixed $value Valor (será serializado como JSON)
     * @param int|null $ttl TTL em segundos (null = usa padrão)
     * @return bool Sucesso
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            $fullKey = $this->prefix . $key;
            $serialized = json_encode($value);
            $ttl = $ttl ?? $this->defaultTtl;

            $this->client->setex($fullKey, $ttl, $serialized);
            
            $this->logger->debug('Cache set', [
                'key' => $fullKey,
                'ttl' => $ttl,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Erro ao setar cache', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Recupera um valor do cache
     * 
     * @param string $key Chave
     * @return mixed|null Valor ou null se não existir
     */
    public function get(string $key): mixed
    {
        try {
            $fullKey = $this->prefix . $key;
            $value = $this->client->get($fullKey);

            if ($value === null) {
                return null;
            }

            return json_decode($value, true);
        } catch (\Exception $e) {
            $this->logger->error('Erro ao buscar cache', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Remove uma chave do cache
     * 
     * @param string $key Chave
     * @return bool Sucesso
     */
    public function delete(string $key): bool
    {
        try {
            $fullKey = $this->prefix . $key;
            $this->client->del($fullKey);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Erro ao deletar cache', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Publica mensagem em um canal Pub/Sub
     * 
     * Este método é usado para enviar eventos de status
     * que serão capturados pelo websocket-worker.
     * 
     * @param string $channel Nome do canal
     * @param array $data Dados a publicar
     * @return bool Sucesso
     */
    public function publish(string $channel, array $data): bool
    {
        try {
            $message = json_encode($data);
            $this->client->publish($channel, $message);

            $this->logger->debug('Mensagem publicada', [
                'channel' => $channel,
                'data' => $data,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Erro ao publicar mensagem', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Incrementa um contador (útil para métricas)
     * 
     * @param string $key Chave do contador
     * @param int $by Valor a incrementar
     * @return int Novo valor
     */
    public function increment(string $key, int $by = 1): int
    {
        try {
            $fullKey = $this->prefix . $key;
            return $this->client->incrby($fullKey, $by);
        } catch (\Exception $e) {
            $this->logger->error('Erro ao incrementar contador', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Adiciona item a uma lista (para filas)
     * 
     * @param string $key Chave da lista
     * @param mixed $value Valor a adicionar
     * @return bool Sucesso
     */
    public function pushToList(string $key, mixed $value): bool
    {
        try {
            $fullKey = $this->prefix . $key;
            $this->client->rpush($fullKey, json_encode($value));
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Erro ao adicionar à lista', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove e retorna item de uma lista (para filas)
     * 
     * @param string $key Chave da lista
     * @param int $timeout Timeout em segundos (0 = não bloqueia)
     * @return mixed|null Valor ou null
     */
    public function popFromList(string $key, int $timeout = 0): mixed
    {
        try {
            $fullKey = $this->prefix . $key;
            
            if ($timeout > 0) {
                $result = $this->client->blpop([$fullKey], $timeout);
                return $result ? json_decode($result[1], true) : null;
            }
            
            $value = $this->client->lpop($fullKey);
            return $value ? json_decode($value, true) : null;
        } catch (\Exception $e) {
            $this->logger->error('Erro ao remover da lista', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Armazena hash (útil para sessões)
     * 
     * @param string $key Chave do hash
     * @param array $data Dados do hash
     * @param int|null $ttl TTL opcional
     * @return bool Sucesso
     */
    public function setHash(string $key, array $data, ?int $ttl = null): bool
    {
        try {
            $fullKey = $this->prefix . $key;
            $this->client->hmset($fullKey, $data);
            
            if ($ttl !== null) {
                $this->client->expire($fullKey, $ttl);
            }
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Erro ao setar hash', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Recupera hash
     * 
     * @param string $key Chave do hash
     * @return array|null Dados ou null
     */
    public function getHash(string $key): ?array
    {
        try {
            $fullKey = $this->prefix . $key;
            $data = $this->client->hgetall($fullKey);
            return !empty($data) ? $data : null;
        } catch (\Exception $e) {
            $this->logger->error('Erro ao buscar hash', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Retorna cliente Redis para operações avançadas
     * 
     * @return RedisClient Cliente
     */
    public function getClient(): RedisClient
    {
        return $this->client;
    }
}
