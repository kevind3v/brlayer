<?php

declare(strict_types=1);

namespace BrLayer;

use DateTime;
use DomainException;
use Exception;
use InvalidArgumentException;
use PDO;
use PDOException;
use ReflectionMethod;
use stdClass;
use Throwable;

/**
 * Class Model
 * @package BrLayer
 */
abstract class Model
{
    use CrudTrait;

    /** @var string|null coluna preenchida na criação (null = a tabela não tem) */
    protected ?string $createdAt = null;

    /** @var string|null coluna preenchida na criação e na alteração (null = a tabela não tem) */
    protected ?string $updatedAt = null;

    /** @var array colunas aceitas pelo fill() quando ele não recebe a lista */
    protected array $fillable = [];

    /** @var bool lança a exceção da falha em vez de só guardar em fail() */
    protected bool $throwOnFail = false;

    /** @var string $entity database table */
    private string $entity;

    /** @var string $primary table primary key field */
    private string $primary;

    /** @var array $required table required fields */
    private array $required;

    /** @var array|null */
    private ?array $database;

    /*
     * Estado da consulta e dados: privados para que colunas com o mesmo nome
     * (ex.: "data", "order") passem pelo __get/__set e nunca colidam com eles.
     */

    /** @var string|null */
    private ?string $columns = null;

    /** @var string[] */
    private array $where = [];

    /** @var array|null */
    private ?array $params = null;

    /** @var string|null */
    private ?string $group = null;

    /** @var string|null */
    private ?string $order = null;

    /** @var string|null */
    private ?string $limit = null;

    /** @var string|null */
    private ?string $offset = null;

    /** @var int */
    private int $inIndex = 0;

    /** @var Exception|null */
    private ?Exception $fail = null;

    /** @var object|null */
    private ?object $data = null;

    /**
     * Model constructor.
     * @param string $entity
     * @param array $required
     * @param string $primary
     * @param array|null $database
     */
    public function __construct(
        string $entity,
        array $required,
        string $primary = 'id',
        ?array $database = null
    ) {
        $this->entity = Identifier::column($entity);
        $this->primary = Identifier::name($primary);
        $this->required = $required;
        $this->database = $database;
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function __set(string $name, mixed $value): void
    {
        if (empty($this->data)) {
            $this->data = new stdClass();
        }

        $this->data->$name = $value;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return isset($this->data->$name);
    }

    /**
     * Devolve o valor da coluna ou o resultado de um método da model (ex.: full_name => fullName()).
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        foreach ([$this->toCamelCase($name), $name] as $method) {
            if ($this->isAccessor($method)) {
                return $this->$method();
            }
        }

        return ($this->data->$name ?? null);
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
     * Roda tudo numa transação: confirma no fim ou desfaz tudo se der qualquer erro (e repassa o erro).
     * Se já houver uma transação aberta, só roda dentro dela (quem abriu é quem confirma).
     *
     * Falhas só desfazem a transação se lançarem exceção: use $throwOnFail = true nas models.
     *
     * @param callable $action o retorno dela é devolvido
     * @param array|null $database
     * @return mixed
     */
    public static function transaction(callable $action, ?array $database = null): mixed
    {
        $pdo = Connect::getInstance($database);
        if ($pdo->inTransaction()) {
            return $action();
        }

        $pdo->beginTransaction();
        try {
            $result = $action();
            $pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (PDOException) {
                //conexão caiu: o banco já desfez a transação; repassa o erro original
            }
            throw $exception;
        }
    }

    /**
     * @param int $mode
     * @return array|null
     */
    public function columns(int $mode = PDO::FETCH_OBJ): ?array
    {
        $this->fail = null;

        try {
            return $this->run("DESCRIBE {$this->entity}")->fetchAll($mode);
        } catch (DomainException | PDOException $exception) {
            return $this->failed($exception);
        }
    }

    /**
     * @return object|null
     */
    public function data(): ?object
    {
        return $this->data;
    }

    /**
     * @return Exception|null
     */
    public function fail(): ?Exception
    {
        return $this->fail;
    }

    /**
     * Preenche a model só com os campos permitidos (ex.: dados de formulário).
     *
     * Sem a lista, usa $fillable, mas nunca a chave primária. Para aceitar a chave primária,
     * informe-a na lista.
     *
     * @param array $data
     * @param array|null $allowed
     * @return static
     */
    public function fill(array $data, ?array $allowed = null): static
    {
        $allowed = $allowed ?? array_diff($this->fillable, [$this->primary]);
        if (empty($allowed)) {
            throw new InvalidArgumentException("Informe os campos permitidos para o fill() ou defina \$fillable");
        }

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $this->__set($field, $data[$field]);
            }
        }

        return $this;
    }

    /**
     * Começa uma nova consulta (descarta filtros, ordem e limites da anterior).
     *
     * @param string|null $terms condição do WHERE, sempre com placeholders
     * @param array|string|null $params valores: array (recomendado) ou query string
     * @param string $columns
     * @return static
     */
    public function find(?string $terms = null, array|string|null $params = null, string $columns = "*"): static
    {
        $this->columns = Identifier::columns($columns);
        $this->where = $terms ? [$terms] : [];
        $this->params = $terms ? $this->normalizeParams($params) : null;
        $this->group = null;
        $this->order = null;
        $this->limit = null;
        $this->offset = null;
        $this->inIndex = 0;
        return $this;
    }

    /**
     * @param int|string $id
     * @param string $columns
     * @return static|null
     */
    public function findById(int|string $id, string $columns = "*"): ?static
    {
        return $this->find("{$this->primary} = :brlayer_pk", ["brlayer_pk" => $id], $columns)->fetch();
    }

    /**
     * @param string $column
     * @return static
     */
    public function group(string $column): static
    {
        $this->group = " GROUP BY " . Identifier::list($column);
        return $this;
    }

    /**
     * @param string $columnOrder ex.: "us.first_name DESC, id"
     * @return static
     */
    public function order(string $columnOrder): static
    {
        $this->order = " ORDER BY " . Identifier::order($columnOrder);
        return $this;
    }

    /**
     * ORDER BY sem validação (ex.: "RAND()"). Nunca passe dados do usuário aqui.
     *
     * @param string $order
     * @return static
     */
    public function orderRaw(string $order): static
    {
        $this->order = " ORDER BY {$order}";
        return $this;
    }

    /**
     * @param int $limit
     * @return static
     */
    public function limit(int $limit): static
    {
        $this->limit = " LIMIT {$limit}";
        return $this;
    }

    /**
     * @param string $column
     * @param array $values
     * @return static
     */
    public function in(string $column, array $values): static
    {
        Identifier::column($column);
        if ($this->columns === null) {
            $this->find();
        }

        [$placeholders, $params] = static::inList($values, "in{$this->inIndex}_");
        $this->inIndex++;

        $this->where[] = "{$column} IN ({$placeholders})";
        $this->params = array_merge($this->params ?? [], $this->normalizeParams($params));
        return $this;
    }

    /**
     * @param int $offset
     * @return static
     */
    public function offset(int $offset): static
    {
        $this->offset = " OFFSET {$offset}";
        return $this;
    }

    /**
     * @param bool $all
     * @return static|static[]|null
     */
    public function fetch(bool $all = false): array|static|null
    {
        $this->fail = null;

        try {
            $stmt = $this->run($this->selectSql(), $this->params ?? []);

            if ($all) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return $rows ? array_map(fn($row) => $this->hydrate($row), $rows) : null;
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->hydrate($row) : null;
        } catch (DomainException | PDOException $exception) {
            return $this->failed($exception);
        }
    }

    /**
     * Total de registros da consulta (considera o WHERE e o in(); ignora group, order, limit e offset).
     *
     * @return int
     */
    public function count(): int
    {
        $this->fail = null;

        try {
            $stmt = $this->run("SELECT COUNT(*) FROM {$this->entity}{$this->whereSql()}", $this->params ?? []);
            return (int)$stmt->fetchColumn();
        } catch (DomainException | PDOException $exception) {
            return $this->failed($exception) ?? 0;
        }
    }

    /**
     * @return bool
     */
    public function save(): bool
    {
        $this->fail = null;
        $primary = $this->primary;
        $data = (array)$this->data;
        $id = $data[$primary] ?? null;
        unset($data[$primary]);

        try {
            $missing = $this->missing();
            if ($missing) {
                throw new DomainException("Preencha os campos necessários: " . implode(", ", $missing));
            }

            if (empty($id)) {
                /** Create */
                $id = $this->create($data);
                if ($id === false) {
                    return false;
                }
            } elseif ($this->exists($id)) {
                /** Update */
                if ($this->update($data, "{$primary} = :brlayer_pk", ["brlayer_pk" => $id]) === null) {
                    return false;
                }
            } elseif ($this->create([$primary => $id] + $data) === false) {
                /** Create com a chave definida pelo sistema (ex.: UUID) */
                return false;
            }

            $saved = $this->findById($id);
            if ($saved) {
                $this->data = $saved->data();
            }
            return true;
        } catch (DomainException | PDOException $exception) {
            return $this->failed($exception) ?? false;
        }
    }

    /**
     * @return bool
     */
    public function destroy(): bool
    {
        $id = $this->data->{$this->primary} ?? null;

        if (empty($id)) {
            return false;
        }

        return $this->delete("{$this->primary} = :brlayer_pk", ["brlayer_pk" => $id]);
    }

    /**
     * Parâmetros da consulta montada (para depuração).
     *
     * @return array|null
     */
    public function getParams(): ?array
    {
        return $this->params;
    }

    /**
     * SQL da consulta montada (para depuração).
     *
     * @return string|null
     */
    public function getStatement(): ?string
    {
        return $this->columns === null ? null : $this->selectSql();
    }

    /**
     * Monta os placeholders de um "IN (...)", para nunca colocar os valores direto no SQL.
     *
     * Ex.: inList([3, 7], "acc") => [":acc0, :acc1", [":acc0" => 3, ":acc1" => 7]].
     * Nulos e repetidos saem. Lista vazia vira "NULL" (não encontra nada).
     * Use um nome diferente para cada IN do mesmo SQL.
     *
     * @param array $values
     * @param string $name prefixo dos placeholders
     * @return array [string $placeholders, array $params]
     */
    protected static function inList(array $values, string $name = "id"): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException("Nome de placeholder inválido: {$name}");
        }

        $values = array_values(array_unique(
            array_filter($values, fn($value) => $value !== null),
            SORT_REGULAR
        ));
        if (!$values) {
            return ["NULL", []];
        }

        $params = [];
        foreach ($values as $i => $value) {
            $params[":{$name}{$i}"] = $value;
        }
        return [implode(", ", array_keys($params)), $params];
    }

    /**
     * Executa uma consulta com SQL próprio (agregações, JOIN, UNION) e devolve as linhas.
     *
     * @param string $sql SQL com placeholders
     * @param array|string|null $params
     * @return array linhas como arrays associativos
     */
    protected function rows(string $sql, array|string|null $params = []): array
    {
        $this->fail = null;

        try {
            return $this->run($sql, $this->normalizeParams($params))->fetchAll(PDO::FETCH_ASSOC);
        } catch (DomainException | PDOException $exception) {
            return $this->failed($exception) ?? [];
        }
    }

    /**
     * Executa uma consulta com SQL próprio e devolve a primeira linha.
     *
     * @param string $sql
     * @param array|string|null $params
     * @return array|null
     */
    protected function row(string $sql, array|string|null $params = []): ?array
    {
        $this->fail = null;

        try {
            $row = $this->run($sql, $this->normalizeParams($params))->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (DomainException | PDOException $exception) {
            return $this->failed($exception);
        }
    }

    /**
     * Executa um comando com SQL próprio (ex.: UPDATE em lote).
     *
     * @param string $sql
     * @param array|string|null $params
     * @return int linhas alteradas
     */
    protected function execute(string $sql, array|string|null $params = []): int
    {
        $this->fail = null;

        try {
            return $this->run($sql, $this->normalizeParams($params))->rowCount();
        } catch (DomainException | PDOException $exception) {
            return $this->failed($exception) ?? 0;
        }
    }

    /**
     * Data e hora agora no fuso do PHP (o banco pode estar em outro fuso).
     *
     * @param string|null $modify ajuste relativo (ex.: "+30 minutes")
     * @return string Y-m-d H:i:s
     */
    protected static function now(?string $modify = null): string
    {
        $date = new DateTime("now");
        if ($modify) {
            $date->modify($modify);
        }
        return $date->format("Y-m-d H:i:s");
    }

    /**
     * @return string[] campos obrigatórios não preenchidos
     */
    protected function missing(): array
    {
        $data = (array)$this->data;
        return array_values(array_filter(
            $this->required,
            fn($field) => !isset($data[$field]) || $data[$field] === ""
        ));
    }

    /**
     * @param string $string
     * @return string
     */
    protected function toCamelCase(string $string): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $string))));
    }

    /**
     * Guarda a falha e, com $throwOnFail, lança a exceção.
     *
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

    /**
     * Só métodos públicos criados na model (não os da Model, como save e destroy) e sem argumentos.
     *
     * @param string $method
     * @return bool
     */
    private function isAccessor(string $method): bool
    {
        if ($method === "" || !method_exists($this, $method) || method_exists(self::class, $method)) {
            return false;
        }

        $reflection = new ReflectionMethod($this, $method);
        return $reflection->isPublic()
            && !$reflection->isStatic()
            && $reflection->getNumberOfRequiredParameters() === 0;
    }

    /**
     * @param int|string $id
     * @return bool
     */
    private function exists(int|string $id): bool
    {
        return $this->run(
            "SELECT 1 FROM {$this->entity} WHERE {$this->primary} = :brlayer_pk",
            ["brlayer_pk" => $id]
        )->fetchColumn() !== false;
    }

    /**
     * @param array $row
     * @return static
     */
    private function hydrate(array $row): static
    {
        $model = new static();
        $model->data = (object)$row;
        return $model;
    }

    /**
     * @return string
     */
    private function selectSql(): string
    {
        $columns = $this->columns ?? "*";
        return "SELECT {$columns} FROM {$this->entity}{$this->whereSql()}"
            . $this->group . $this->order . $this->limit . $this->offset;
    }

    /**
     * @return string
     */
    private function whereSql(): string
    {
        if (!$this->where) {
            return "";
        }
        if (count($this->where) === 1) {
            return " WHERE {$this->where[0]}";
        }
        return " WHERE (" . implode(") AND (", $this->where) . ")";
    }
}
