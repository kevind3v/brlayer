<?php

declare(strict_types=1);

namespace BrLayer;

use LogicException;
use PDO;
use PDOException;

/**
 * Class Connect
 * @package BrLayer
 */
class Connect
{
    /** @var PDO[] uma conexão por banco/usuário */
    private static array $instance = [];

    /** @var PDOException|null */
    private static ?PDOException $error = null;

    /**
     * Devolve a conexão do banco informado (ou da constante BRLAYER_CONFIG), abrindo na primeira vez.
     *
     * Chaves aceitas: driver, host, port, dbname, username, passwd, options e, opcionais,
     * charset (só mysql) e dsn (substitui o DSN montado, ex.: "sqlite::memory:").
     *
     * @param array|null $database
     * @return PDO
     * @throws PDOException quando não consegue conectar
     */
    public static function getInstance(?array $database = null): PDO
    {
        $dbConf = $database ?? self::defaultConfig();
        $dbName = self::key($dbConf);

        if (empty(self::$instance[$dbName])) {
            $dsn = self::dsn($dbConf);
            $options = $dbConf["options"] ?? [];
            $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

            //Um comando por chamada: bloqueia "...; DROP TABLE" mesmo se alguém concatenar valores no SQL
            if (str_starts_with($dsn, "mysql:")) {
                $options += [
                    (class_exists(\Pdo\Mysql::class)
                        ? \Pdo\Mysql::ATTR_MULTI_STATEMENTS
                        : PDO::MYSQL_ATTR_MULTI_STATEMENTS) => false
                ];
            }

            try {
                self::$instance[$dbName] = new PDO(
                    $dsn,
                    $dbConf["username"] ?? null,
                    $dbConf["passwd"] ?? null,
                    $options
                );
                self::$error = null;
            } catch (PDOException $exception) {
                self::$error = $exception;
                throw $exception;
            }
        }

        return self::$instance[$dbName];
    }

    /**
     * @return PDOException|null
     */
    public static function getError(): ?PDOException
    {
        return self::$error;
    }

    /**
     * Descarta a conexão guardada: a próxima chamada abre outra (ex.: depois que o banco caiu).
     *
     * @param array|null $database
     */
    public static function forget(?array $database = null): void
    {
        unset(self::$instance[self::key($database ?? self::defaultConfig())]);
    }

    /**
     * A conexão caiu (MySQL "server has gone away" / "lost connection")?
     *
     * @param PDOException $exception
     * @return bool
     */
    public static function isConnectionLost(PDOException $exception): bool
    {
        return in_array($exception->errorInfo[1] ?? null, [2006, 2013], true)
            || preg_match('/\b(2006|2013)\b.*(gone away|Lost connection)/i', $exception->getMessage()) === 1;
    }

    /**
     * @param array $dbConf
     * @return string
     */
    private static function key(array $dbConf): string
    {
        return implode("|", [
            $dbConf["dsn"] ?? null,
            $dbConf["driver"] ?? null,
            $dbConf["host"] ?? null,
            $dbConf["port"] ?? null,
            $dbConf["dbname"] ?? null,
            $dbConf["username"] ?? null
        ]);
    }

    /**
     * @param array $dbConf
     * @return string
     */
    private static function dsn(array $dbConf): string
    {
        if (!empty($dbConf["dsn"])) {
            return $dbConf["dsn"];
        }

        //DSN alternative for SQL Server (sqlsrv)
        if ($dbConf["driver"] == "sqlsrv") {
            return "sqlsrv:Server={$dbConf["host"]},{$dbConf["port"]};Database={$dbConf["dbname"]}";
        }

        $dsn = "{$dbConf["driver"]}:host={$dbConf["host"]};dbname={$dbConf["dbname"]};port={$dbConf["port"]}";
        if ($dbConf["driver"] == "mysql" && !empty($dbConf["charset"])) {
            $dsn .= ";charset={$dbConf["charset"]}";
        }

        return $dsn;
    }

    /**
     * @return array
     */
    private static function defaultConfig(): array
    {
        //DATA_LAYER_CONFIG: nome da CoffeeCode DataLayer, aceito para facilitar a migração
        foreach (["BRLAYER_CONFIG", "DATA_LAYER_CONFIG"] as $constant) {
            if (defined($constant)) {
                return constant($constant);
            }
        }

        throw new LogicException("Defina a constante BRLAYER_CONFIG ou informe a configuração do banco");
    }

    /**
     * Connect constructor.
     */
    private function __construct()
    {
    }

    /**
     * Connect clone.
     */
    private function __clone()
    {
    }
}
