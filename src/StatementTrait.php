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
 * Execução dos comandos (compartilhada entre Model e Query).
 * Quem usa precisa ter a propriedade $database.
 *
 * @package BrLayer
 */
trait StatementTrait
{
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
}
