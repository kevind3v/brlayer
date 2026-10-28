<?php

declare(strict_types=1);

namespace BrLayer;

use DomainException;
use Exception;
use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * Consulta montada por partes, para JOIN, relatórios e filtros dinâmicos.
 *
 * select(), from(), os joins e o where() vão para o SQL como foram escritos: nunca coloque
 * dados do usuário neles. Valores vão sempre por "?" ou ":nome".
 *
 * @package BrLayer
 */
final class Query
{
    use StatementTrait;

    /** "?" fora de textos ('...', "...") e nomes entre crases */
    private const QUESTION_MARK = '/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`|\?/s';

    /** @var array|null */
    private ?array $database;

    /** @var bool */
    private bool $throwOnFail = false;

    /** @var Exception|null */
    private ?Exception $fail = null;

    /** @var string|null SQL informado no query() */
    private ?string $raw = null;

    /** @var string */
    private string $select = "*";

    /** @var string */
    private string $from = "";

    /** @var string[] */
    private array $joins = [];

    /** @var array[] [conector, condição] */
    private array $where = [];

    /** @var array */
    private array $params = [];

    /** @var string */
    private string $group = "";

    /** @var string */
    private string $order = "";

    /** @var string */
    private string $limit = "";

    /** @var string */
    private string $offset = "";

    /** @var int numera os placeholders criados pela Query */
    private int $counter = 0;

    /**
     * @param array|null $database
     */
    private function __construct(?array $database)
    {
        $this->database = $database;
    }

    /**
     * @param array|null $database banco (padrão BRLAYER_CONFIG)
     * @return Query
     */
    public static function create(?array $database = null): Query
    {
        return new self($database);
    }

    /**
     * Lança a exceção da falha em vez de só guardar em fail().
     *
     * @param bool $throw
     * @return Query
     */
    public function throwOnFail(bool $throw = true): Query
    {
        $this->throwOnFail = $throw;
        return $this;
    }

    /**
     * @param string $columns ex.: "u.id, COUNT(*) AS total"
     * @return Query
     */
    public function select(string $columns): Query
    {
        $this->select = $columns;
        return $this;
    }

    /**
     * @param string $table ex.: "users u"
     * @return Query
     */
    public function from(string $table): Query
    {
        $this->from = $table;
        return $this;
    }

    /**
     * @param string $table ex.: "companies c"
     * @param string $toId ex.: "c.id"
     * @param string $fromId ex.: "u.company_id"
     * @return Query
     */
    public function innerJoin(string $table, string $toId, string $fromId): Query
    {
        return $this->join("INNER JOIN {$table} ON {$toId} = {$fromId}");
    }

    /**
     * @param string $table
     * @param string $toId
     * @param string $fromId
     * @return Query
     */
    public function leftJoin(string $table, string $toId, string $fromId): Query
    {
        return $this->join("LEFT JOIN {$table} ON {$toId} = {$fromId}");
    }

    /**
     * JOIN escrito por inteiro (ex.: com mais de uma condição no ON).
     *
     * @param string $join ex.: "LEFT JOIN roles r ON r.user_id = u.id AND r.active = 1"
     * @return Query
     */
    public function join(string $join): Query
    {
        $this->joins[] = $join;
        return $this;
    }

    /**
     * Adiciona uma condição (com AND, se já houver outra).
     *
     * Valores: um só para todos os "?" ("name LIKE ? OR email LIKE ?", "%ana%"),
     * uma lista, um por "?" ("id > ? AND id < ?", [1, 9]), ou um array com nomes para ":nome".
     *
     * @param string $terms
     * @param mixed $params
     * @return Query
     */
    public function where(string $terms, mixed $params = null): Query
    {
        return $this->condition("AND", $terms, $params);
    }

    /**
     * @param string $terms
     * @param mixed $params
     * @return Query
     */
    public function andWhere(string $terms, mixed $params = null): Query
    {
        return $this->condition("AND", $terms, $params);
    }

    /**
     * @param string $terms
     * @param mixed $params
     * @return Query
     */
    public function orWhere(string $terms, mixed $params = null): Query
    {
        return $this->condition("OR", $terms, $params);
    }

    /**
     * Filtro "IN" com os valores por placeholder. Nulos e repetidos saem.
     *
     * Lista vazia: IN não encontra nada; NOT IN não exclui nada.
     *
     * @param string $column
     * @param array $values
     * @param bool $not
     * @return Query
     */
    public function andWhereIn(string $column, array $values, bool $not = false): Query
    {
        $values = array_values(array_unique(
            array_filter($values, fn($value) => $value !== null),
            SORT_REGULAR
        ));
        if (!$values) {
            return $not ? $this : $this->condition("AND", "1 = 0", null);
        }

        $names = [];
        foreach ($values as $value) {
            $names[] = $name = "brlayer_in" . $this->counter++;
            $this->params[$name] = $value;
        }

        $in = $not ? "NOT IN" : "IN";
        $this->where[] = ["AND", "{$column} {$in} (:" . implode(", :", $names) . ")"];
        return $this;
    }

    /**
     * Valores de placeholders usados fora do where() (ex.: num join()).
     *
     * @param array $params
     * @return Query
     */
    public function params(array $params): Query
    {
        $this->params = array_merge($this->params, $this->normalizeParams($params));
        return $this;
    }

    /**
     * SQL escrito por inteiro. O where(), group(), order(), limit() e offset() continuam valendo.
     *
     * @param string $sql
     * @param string|null $terms condição do WHERE, com placeholders
     * @param array|string|null $params
     * @return Query
     */
    public function query(string $sql, ?string $terms = null, array|string|null $params = null): Query
    {
        $this->raw = $sql;
        if ($terms) {
            $this->where[] = ["AND", $terms];
        }
        $this->params = array_merge($this->params, $this->normalizeParams($params));
        return $this;
    }

    /**
     * @param string $columns ex.: "u.genre, status"
     * @return Query
     */
    public function group(string $columns): Query
    {
        $this->group = " GROUP BY " . Identifier::list($columns);
        return $this;
    }

    /**
     * GROUP BY sem validação (ex.: "DATE(created_at)"). Nunca passe dados do usuário aqui.
     *
     * @param string $group
     * @return Query
     */
    public function groupRaw(string $group): Query
    {
        $this->group = " GROUP BY {$group}";
        return $this;
    }

    /**
     * @param string $columnOrder ex.: "u.first_name DESC, id"
     * @return Query
     */
    public function order(string $columnOrder): Query
    {
        $this->order = " ORDER BY " . Identifier::order($columnOrder);
        return $this;
    }

    /**
     * ORDER BY sem validação (ex.: "COUNT(*) DESC"). Nunca passe dados do usuário aqui.
     *
     * @param string $order
     * @return Query
     */
    public function orderRaw(string $order): Query
    {
        $this->order = " ORDER BY {$order}";
        return $this;
    }

    /**
     * @param int|null $limit null remove o limite
     * @return Query
     */
    public function limit(?int $limit): Query
    {
        $this->limit = $limit === null ? "" : " LIMIT {$limit}";
        return $this;
    }

    /**
     * @param int|null $offset null remove o offset
     * @return Query
     */
    public function offset(?int $offset): Query
    {
        $this->offset = $offset === null ? "" : " OFFSET {$offset}";
        return $this;
    }

    /**
     * @param bool $all todas as linhas ou só a primeira
     * @return array|null linhas como arrays associativos, ou null quando não encontra nada
     */
    public function fetchArray(bool $all = true): ?array
    {
        $this->fail = null;

        try {
            $stmt = $this->run($this->getStatement(), $this->params);
            $result = $all ? $stmt->fetchAll(PDO::FETCH_ASSOC) : $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (DomainException | PDOException $exception) {
            return $this->failed($exception);
        }
    }

    /**
     * Total de linhas da consulta (considera joins, WHERE e GROUP BY; ignora order, limit e offset).
     *
     * @return int
     */
    public function count(): int
    {
        $this->fail = null;

        //Com GROUP BY, DISTINCT ou SQL próprio, conta as linhas da consulta inteira
        $wrap = $this->raw !== null || $this->group !== "" || preg_match('/^\s*DISTINCT\b/i', $this->select);
        $sql = $wrap
            ? "SELECT COUNT(*) FROM (" . $this->baseSql($this->select) . $this->group . ") brlayer_count"
            : $this->baseSql("COUNT(*)");

        try {
            return (int)$this->run($sql, $this->params)->fetchColumn();
        } catch (DomainException | PDOException $exception) {
            return $this->failed($exception) ?? 0;
        }
    }

    /**
     * SQL da consulta montada.
     *
     * @return string
     */
    public function getStatement(): string
    {
        return $this->baseSql($this->select) . $this->group . $this->order . $this->limit . $this->offset;
    }

    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @return Exception|null
     */
    public function fail(): ?Exception
    {
        return $this->fail;
    }

    /**
     * var_dump/print_r sem a senha do banco.
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        $info = get_object_vars($this);
        if (isset($info["database"]["passwd"])) {
            $info["database"]["passwd"] = "********";
        }
        return $info;
    }

    /**
     * @param string $connector
     * @param string $terms
     * @param mixed $params
     * @return Query
     */
    private function condition(string $connector, string $terms, mixed $params): Query
    {
        $original = $terms;
        $names = [];
        $terms = preg_replace_callback(self::QUESTION_MARK, function (array $match) use (&$names): string {
            if ($match[0] !== "?") {
                return $match[0];
            }
            $names[] = $name = "brlayer_w" . $this->counter++;
            return ":{$name}";
        }, $terms);

        if ($params === null || $params === []) {
            $values = [];
        } elseif (is_array($params) && !array_is_list($params)) {
            $this->params = array_merge($this->params, $this->normalizeParams($params));
            $values = [];
        } elseif (is_array($params)) {
            $values = $params;
        } else {
            $values = $names ? array_fill(0, count($names), $params) : [$params];
        }

        if (count($values) !== count($names)) {
            throw new InvalidArgumentException(
                "A condição \"{$original}\" tem " . count($names) . " \"?\" e recebeu " . count($values) . " valor(es)"
            );
        }

        foreach ($names as $i => $name) {
            $this->params[$name] = $values[$i];
        }

        $this->where[] = [$connector, $terms];
        return $this;
    }

    /**
     * @param string $select
     * @return string
     */
    private function baseSql(string $select): string
    {
        if ($this->raw !== null) {
            return $this->raw . $this->whereSql();
        }

        $sql = "SELECT {$select}";
        if ($this->from !== "") {
            $sql .= " FROM {$this->from}";
        }
        foreach ($this->joins as $join) {
            $sql .= " {$join}";
        }
        return $sql . $this->whereSql();
    }

    /**
     * Cada condição entre parênteses: "a OR b" num where() não escapa do AND seguinte.
     *
     * @return string
     */
    private function whereSql(): string
    {
        $sql = "";
        foreach ($this->where as $i => [$connector, $terms]) {
            $sql .= ($i === 0 ? " WHERE " : " {$connector} ") . "({$terms})";
        }
        return $sql;
    }

    /**
     * @param Exception $exception
     * @return null
     */
    private function failed(Exception $exception): null
    {
        $this->fail = $exception;
        if ($this->throwOnFail) {
            throw $exception;
        }
        return null;
    }
}
