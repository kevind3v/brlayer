<?php

declare(strict_types=1);

namespace BrLayer;

use InvalidArgumentException;

/**
 * Valida nomes de tabela e coluna antes de irem para o SQL (eles não podem ir por placeholder).
 *
 * Aceita letras, números e "_", com "tabela.coluna" e `crase` onde faz sentido.
 * Qualquer outra coisa lança InvalidArgumentException.
 *
 * @package BrLayer
 */
final class Identifier
{
    private const PLAIN = '[A-Za-z_][A-Za-z0-9_]*';
    private const NAME = '(?:' . self::PLAIN . '|`[A-Za-z0-9_]+`)';
    private const COLUMN = self::NAME . '(?:\.' . self::NAME . ')?';

    /**
     * Nome simples, sem tabela nem crase (colunas do INSERT/UPDATE).
     *
     * @param string $name
     * @return string
     */
    public static function name(string $name): string
    {
        return self::check($name, '/^' . self::PLAIN . '$/D', $name);
    }

    /**
     * Coluna ou tabela, com prefixo opcional (ex.: "us.first_name").
     *
     * @param string $column
     * @return string
     */
    public static function column(string $column): string
    {
        return self::check($column, '/^' . self::COLUMN . '$/D', $column);
    }

    /**
     * Lista de colunas do SELECT (ex.: "*", "us.*", "first_name AS name, email").
     *
     * @param string $columns
     * @return string
     */
    public static function columns(string $columns): string
    {
        $part = '/^(?:\*|' . self::NAME . '\.\*|' . self::COLUMN . '(?: +(?:AS +)?' . self::NAME . ')?)$/Di';
        return self::each($columns, $part);
    }

    /**
     * Lista de colunas do GROUP BY (ex.: "us.genre, status").
     *
     * @param string $columns
     * @return string
     */
    public static function list(string $columns): string
    {
        return self::each($columns, '/^' . self::COLUMN . '$/D');
    }

    /**
     * Lista do ORDER BY (ex.: "us.first_name DESC, id").
     *
     * @param string $order
     * @return string
     */
    public static function order(string $order): string
    {
        return self::each($order, '/^' . self::COLUMN . '(?: +(?:ASC|DESC))?$/Di');
    }

    /**
     * Valida item a item e devolve a lista remontada (o que vai para o SQL é exatamente o que foi validado).
     *
     * @param string $list
     * @param string $pattern
     * @return string
     */
    private static function each(string $list, string $pattern): string
    {
        $parts = [];
        foreach (explode(",", $list) as $part) {
            $parts[] = self::check(trim($part, " "), $pattern, $list);
        }
        return implode(", ", $parts);
    }

    /**
     * @param string $value
     * @param string $pattern
     * @param string $original
     * @return string
     */
    private static function check(string $value, string $pattern, string $original): string
    {
        if (!preg_match($pattern, $value)) {
            throw new InvalidArgumentException("Nome de coluna ou tabela inválido: {$original}");
        }
        return $value;
    }
}
