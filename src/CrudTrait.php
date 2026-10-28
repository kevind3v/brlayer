<?php

declare(strict_types=1);

namespace BrLayer;

use DomainException;
use PDO;
use PDOException;

/**
 * Trait CrudTrait
 * @package BrLayer
 */
trait CrudTrait
{
    use StatementTrait;

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
