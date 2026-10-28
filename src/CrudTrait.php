<?php

declare(strict_types=1);

namespace BrLayer;

use BackedEnum;
use DateTimeInterface;
use DomainException;
use PDO;
use PDOException;
use PDOStatement;
use Stringable;

/**
 * Trait CrudTrait
 * @package BrLayer
 */
trait CrudTrait
{
    /**
     * @param array $data
     * @return string|false id do registro criado
     */
    protected function create(array $data): string|false
    {
        if ($this->createdAt) {
            $data[$this->createdAt] = static::now();
        }
        if ($this->updatedAt) {
            $data[$this->updatedAt] = static::now();
        }

        try {
            $pdo = Connect::getInstance($this->database);
            $columns = implode(", ", array_map(fn($column) => $this->quote($pdo, $column), array_keys($data)));
            $values = ":" . implode(", :", array_keys($data));

            $this->run("INSERT INTO {$this->entity} ({$columns}) VALUES ({$values})", $data);
            return $pdo->lastInsertId();
        } catch (DomainException | PDOException $exception) {
            return $this->failed($exception) ?? false;
        }
    }

    /**
     * @param array $data
     * @param string $terms
     * @param array|string|null $params
     * @return int|null linhas alteradas
     */
    protected function update(array $data, string $terms, array|string|null $params): ?int
    {
        if ($this->updatedAt) {
            $data[$this->updatedAt] = static::now();
        }
        if (!$data) {
            return 0;
        }

        try {
            $pdo = Connect::getInstance($this->database);
            $dataSet = [];
            foreach (array_keys($data) as $bind) {
                $dataSet[] = $this->quote($pdo, $bind) . " = :{$bind}";
            }
            $dataSet = implode(", ", $dataSet);

            $stmt = $this->run(
                "UPDATE {$this->entity} SET {$dataSet} WHERE {$terms}",
                array_merge($data, $this->normalizeParams($params))
            );
            return $stmt->rowCount();
        } catch (DomainException | PDOException $exception) {
            return $this->failed($exception);
        }
    }

    /**
     * @param string $terms
     * @param array|string|null $params
     * @return bool
     */
    public function delete(string $terms, array|string|null $params = null): bool
    {
        $this->fail = null;

        try {
            $this->run("DELETE FROM {$this->entity} WHERE {$terms}", $this->normalizeParams($params));
            return true;
        } catch (DomainException | PDOException $exception) {
            return $this->failed($exception) ?? false;
        }
    }

    /**
     * Prepara, liga os valores com o tipo certo e executa.
     *
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     * @throws PDOException
     */
    private function run(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = Connect::getInstance($this->database)->prepare($sql);
            foreach ($params as $key => $value) {
                $param = is_int($key) ? $key + 1 : ":" . ltrim($key, ":");
                [$value, $type] = $this->bindable($value);
                $stmt->bindValue($param, $value, $type);
            }
            $stmt->execute();
            return $stmt;
        } catch (PDOException $exception) {
            //Conexão morta não fica guardada: a próxima operação reconecta (esta não é repetida)
            if (Connect::isConnectionLost($exception)) {
                Connect::forget($this->database);
            }
            throw $exception;
        }
    }

    /**
     * @param mixed $value
     * @return array [valor, tipo PDO]
     */
    private function bindable(mixed $value): array
    {
        return match (true) {
            is_null($value) => [null, PDO::PARAM_NULL],
            is_bool($value) => [(int)$value, PDO::PARAM_INT],
            is_int($value) => [$value, PDO::PARAM_INT],
            is_float($value) => [(string)$value, PDO::PARAM_STR],
            is_string($value) => [$value, PDO::PARAM_STR],
            $value instanceof DateTimeInterface => [$value->format("Y-m-d H:i:s"), PDO::PARAM_STR],
            $value instanceof BackedEnum => $this->bindable($value->value),
            $value instanceof Stringable => [(string)$value, PDO::PARAM_STR],
            default => throw new DomainException(
                "Valor do tipo " . get_debug_type($value) . " não pode ser usado no banco"
            )
        };
    }

    /**
     * Aceita array (recomendado) ou query string ("id=1&name=x", formato antigo).
     *
     * @param array|string|null $params
     * @return array
     */
    private function normalizeParams(array|string|null $params): array
    {
        if (is_string($params)) {
            parse_str($params, $params);
        }

        $normalized = [];
        foreach ($params ?? [] as $key => $value) {
            $normalized[is_int($key) ? $key : ltrim($key, ":")] = $value;
        }
        return $normalized;
    }

    /**
     * Valida e coloca o nome da coluna entre aspas do banco (permite colunas como "order").
     *
     * @param PDO $pdo
     * @param int|string $column
     * @return string
     */
    private function quote(PDO $pdo, int|string $column): string
    {
        $column = Identifier::name((string)$column);

        return match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            "mysql" => "`{$column}`",
            "sqlsrv", "dblib" => "[{$column}]",
            default => "\"{$column}\""
        };
    }
}
