<?php

declare(strict_types=1);

namespace HackSim\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use PDO;

class DatabaseService
{
    private Capsule $capsule;
    private PDO $pdo;

    public function __construct(array $config)
    {
        $this->capsule = new Capsule();

        $connection = $config['connections'][$config['default']];

        $this->capsule->addConnection([
            'driver' => $connection['driver'],
            'host' => $connection['host'],
            'port' => $connection['port'],
            'database' => $connection['database'],
            'username' => $connection['username'],
            'password' => $connection['password'],
            'charset' => $connection['charset'],
            'collation' => $connection['collation'],
            'prefix' => $connection['prefix'],
            'strict' => $connection['strict'],
            'engine' => $connection['engine'],
            'options' => $connection['options'],
        ]);

        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->pdo = $this->capsule->getConnection()->getPdo();
    }

    public function getCapsule(): Capsule
    {
        return $this->capsule;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getSchema(): SchemaBuilder
    {
        return $this->capsule->schema();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    public function transaction(callable $callback): mixed
    {
        return $this->capsule->connection()->transaction($callback);
    }

    public function table(string $table)
    {
        return $this->capsule->table($table);
    }

    public function raw(string $expression)
    {
        return $this->capsule::raw($expression);
    }

    public function select(string $query, array $bindings = []): array
    {
        return $this->capsule::select($query, $bindings);
    }

    public function insert(string $query, array $bindings = []): bool
    {
        return $this->capsule::insert($query, $bindings);
    }

    public function update(string $query, array $bindings = []): int
    {
        return $this->capsule::update($query, $bindings);
    }

    public function delete(string $query, array $bindings = []): int
    {
        return $this->capsule::delete($query, $bindings);
    }
}