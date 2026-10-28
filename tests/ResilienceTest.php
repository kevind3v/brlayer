<?php

namespace BrLayer\Tests;

use BrLayer\Connect;
use BrLayer\Model;
use BrLayer\Tests\Models\StrictUser;
use BrLayer\Tests\Models\User;
use PDO;
use PDOException;

/**
 * Banco fora do ar, conexão derrubada, travas, dados inválidos: a lib falha de forma controlada.
 */
class ResilienceTest extends TestCase
{
    private function model(array $override, bool $strict = false): Model
    {
        $database = testDatabase($override);
        return $strict
            ? new class ($database) extends Model {
                protected bool $throwOnFail = true;
                public function __construct(array $database = [])
                {
                    parent::__construct("users", ["first_name"], "id", $database ?: testDatabase(["port" => "1"]));
                }
            }
            : new class ($database) extends Model {
                public function __construct(array $database = [])
                {
                    parent::__construct("users", ["first_name"], "id", $database ?: testDatabase(["port" => "1"]));
                }
            };
    }

    private function directPdo(): PDO
    {
        $db = testDatabase();
        return new PDO(
            "mysql:host={$db["host"]};port={$db["port"]};dbname={$db["dbname"]}",
            $db["username"],
            $db["passwd"],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public function testDatabaseDownFailsFastAndControlled(): void
    {
        $start = microtime(true);
        $model = $this->model(["port" => "1"]);

        $this->assertNull($model->findById(1));
        $this->assertSame(0, $model->find()->count());
        $this->assertFalse($model->fill(["first_name" => "Ana"], ["first_name"])->save());
        $this->assertInstanceOf(PDOException::class, $model->fail());
        $this->assertStringContainsString("2002", $model->fail()->getMessage());
        $this->assertLessThan(5, microtime(true) - $start);
    }

    public function testDatabaseDownThrowsWithThrowOnFail(): void
    {
        $this->expectException(PDOException::class);
        $this->model(["port" => "1"], true)->findById(1);
    }

    public function testUnknownDatabase(): void
    {
        $model = $this->model(["dbname" => "brlayer_nao_existe"]);
        $this->assertNull($model->findById(1));
        $this->assertStringContainsString("1049", $model->fail()->getMessage());
    }

    public function testMissingTableIsControlled(): void
    {
        $this->pdo->exec("DROP TABLE users");
        $model = new User();

        $this->assertNull($model->find()->fetch(true));
        $this->assertSame("42S02", $model->fail()->getCode());
    }

    public function testConnectionKilledMidSessionRecoversOnNextCall(): void
    {
        $id = (int)Connect::getInstance()->query("SELECT CONNECTION_ID()")->fetchColumn();
        $this->directPdo()->exec("KILL {$id}");

        $model = new User();
        $this->assertNull($model->findById(1), "A consulta com a conexão morta falha");
        $this->assertNotNull($model->fail());

        $this->assertSame("Ana", (new User())->findById(1)?->first_name, "A próxima consulta reconecta");
    }

    public function testRowLockedByAnotherTransaction(): void
    {
        $locker = $this->directPdo();
        $locker->beginTransaction();
        $locker->query("SELECT * FROM users WHERE id = 1 FOR UPDATE")->fetchAll();

        Connect::getInstance()->exec("SET SESSION innodb_lock_wait_timeout = 1");

        $user = (new User())->findById(1);
        $user->first_name = "Travada";
        $this->assertFalse($user->save());
        $this->assertStringContainsString("1205", $user->fail()->getMessage());

        try {
            Model::transaction(function () {
                $other = (new StrictUser())->findById(2);
                $other->first_name = "Mudou";
                $other->save();

                $locked = (new StrictUser())->findById(1);
                $locked->first_name = "Travada";
                $locked->save();
            });
            $this->fail("Deveria ter lançado a exceção");
        } catch (PDOException) {
            $this->assertSame("Bruno", $this->value("SELECT first_name FROM users WHERE id = 2"), "Rollback");
        } finally {
            $locker->rollBack();
            Connect::getInstance()->exec("SET SESSION innodb_lock_wait_timeout = 50");
        }
    }

    public function testManyModelsShareOneConnection(): void
    {
        $count = fn() => (int)$this->value(
            "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = DATABASE()"
        );
        $before = $count();

        for ($i = 0; $i < 300; $i++) {
            (new User())->findById(1);
            (new User())->find()->count();
        }

        $this->assertSame($before, $count());
    }

    public function testArrayFromFormIsAFailureNotACrash(): void
    {
        // ex.: first_name[]=x no formulário
        $user = (new User())->fill(["first_name" => ["x"]], ["first_name"]);
        $this->assertFalse($user->save());
        $this->assertNotNull($user->fail());

        $model = new User();
        $this->assertNull($model->find("first_name = :n", ["n" => ["x"]])->fetch());
        $this->assertNotNull($model->fail());
    }

    public function testArrayFromFormThrowsWithThrowOnFail(): void
    {
        $this->expectException(\DomainException::class);
        (new StrictUser())->fill(["first_name" => ["x"]], ["first_name"])->save();
    }

    public function testTooLongValueIsControlled(): void
    {
        $user = new User();
        $user->first_name = str_repeat("a", 1000);
        $this->assertFalse($user->save());
        $this->assertStringContainsString("1406", $user->fail()->getMessage());
    }

    public function testInvalidUtf8IsControlled(): void
    {
        $user = new User();
        $user->first_name = "Jos\xC3\x28";
        $this->assertFalse($user->save());
        $this->assertNotNull($user->fail());
        $this->assertSame(4, (int)$this->value("SELECT COUNT(*) FROM users"));
    }
}
