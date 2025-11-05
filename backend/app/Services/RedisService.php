<?php

declare(strict_types=1);

namespace HackSim\Services;

use Redis;
use RedisException;

class RedisService
{
    private Redis $redis;
    private array $config;
    private string $prefix;

    public function __construct(array $cacheConfig, array $redisConfig)
    {
        $this->config = $redisConfig;
        $this->prefix = $cacheConfig['prefix'];

        $this->redis = new Redis();
        $this->connect();
    }

    private function connect(): void
    {
        try {
            $this->redis->connect(
                $this->config['host'],
                $this->config['port'],
                2.0
            );

            if (!empty($this->config['password'])) {
                $this->redis->auth($this->config['password']);
            }

            if (isset($this->config['database'])) {
                $this->redis->select($this->config['database']);
            }
        } catch (RedisException $e) {
            throw new \RuntimeException("Failed to connect to Redis: " . $e->getMessage());
        }
    }

    private function getKey(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key): mixed
    {
        $value = $this->redis->get($this->getKey($key));

        if ($value === false) {
            return null;
        }

        return json_decode($value, true);
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $jsonValue = json_encode($value);

        if ($ttl > 0) {
            return $this->redis->setex($this->getKey($key), $ttl, $jsonValue);
        }

        return $this->redis->set($this->getKey($key), $jsonValue);
    }

    public function delete(string $key): bool
    {
        return $this->redis->del($this->getKey($key)) > 0;
    }

    public function exists(string $key): bool
    {
        return $this->redis->exists($this->getKey($key)) > 0;
    }

    public function increment(string $key, int $amount = 1): int
    {
        return $this->redis->incrBy($this->getKey($key), $amount);
    }

    public function decrement(string $key, int $amount = 1): int
    {
        return $this->redis->decrBy($this->getKey($key), $amount);
    }

    public function expire(string $key, int $ttl): bool
    {
        return $this->redis->expire($this->getKey($key), $ttl);
    }

    public function ttl(string $key): int
    {
        return $this->redis->ttl($this->getKey($key));
    }

    public function hSet(string $key, string $field, mixed $value): bool
    {
        $jsonValue = json_encode($value);
        return $this->redis->hSet($this->getKey($key), $field, $jsonValue) !== false;
    }

    public function hGet(string $key, string $field): mixed
    {
        $value = $this->redis->hGet($this->getKey($key), $field);

        if ($value === false) {
            return null;
        }

        return json_decode($value, true);
    }

    public function hGetAll(string $key): array
    {
        $data = $this->redis->hGetAll($this->getKey($key));

        $result = [];
        foreach ($data as $field => $value) {
            $result[$field] = json_decode($value, true);
        }

        return $result;
    }

    public function hDel(string $key, string $field): bool
    {
        return $this->redis->hDel($this->getKey($key), $field) > 0;
    }

    public function lPush(string $key, mixed $value): int
    {
        $jsonValue = json_encode($value);
        return $this->redis->lPush($this->getKey($key), $jsonValue);
    }

    public function rPop(string $key): mixed
    {
        $value = $this->redis->rPop($this->getKey($key));

        if ($value === false) {
            return null;
        }

        return json_decode($value, true);
    }

    public function lRange(string $key, int $start, int $end): array
    {
        $values = $this->redis->lRange($this->getKey($key), $start, $end);

        return array_map(function ($value) {
            return json_decode($value, true);
        }, $values);
    }

    public function lLen(string $key): int
    {
        return $this->redis->lLen($this->getKey($key));
    }

    public function clear(): bool
    {
        $pattern = $this->prefix . '*';
        $keys = $this->redis->keys($pattern);

        if (empty($keys)) {
            return true;
        }

        return $this->redis->del($keys) > 0;
    }

    public function getRedis(): Redis
    {
        return $this->redis;
    }
}