<?php

namespace BrLayer\Tests;

use BrLayer\Tests\Models\Menu;
use BrLayer\Tests\Models\User;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use TypeError;

/**
 * Tentativas de SQL injection: nada pode vazar dados, alterar o banco ou demorar (SLEEP).
 */
class InjectionTest extends TestCase
{
    public static function valuePayloads(): array
    {
        return [
            "aspas OR" => ["' OR '1'='1"],
            "comentário" => ["' OR 1=1 -- "],
            "comentário #" => ["' OR 1=1 #"],
            "stacked DROP" => ["'; DROP TABLE users; -- "],
            "barra invertida" => ["\\' OR 1=1 -- "],
            "UNION" => ["' UNION SELECT 1,2,3,4,5,6,7 -- "],
            "comentário bloco" => ["admin'/*"],
            "byte nulo" => ["\0' OR 1=1 -- "],
            "aspa unicode" => ["ʼ OR 1=1 -- "],
            "time based" => ["' OR SLEEP(3) -- "],
            "LIKE coringa" => ["%' OR '%'='"],
            "hex" => ["0x416E61"]
        ];
    }

    #[DataProvider('valuePayloads')]
    public function testValuePayloadsAreOnlyData(string $payload): void
    {
        $start = microtime(true);
        $model = new User();

        $this->assertNull($model->find("first_name = :name", ["name" => $payload])->fetch());
        $this->assertSame(0, $model->find("first_name = :name", ["name" => $payload])->count());
        $this->assertNull($model->find()->in("first_name", [$payload, "x"])->fetch(true));
        $this->assertNull($model->find("first_name = :name", "name={$payload}")->fetch());

        $this->assertLessThan(2, microtime(true) - $start, "Consulta demorou: SLEEP executou?");
        $this->assertSame(4, (int)$this->value("SELECT COUNT(*) FROM users"));
    }

    #[DataProvider('valuePayloads')]
    public function testSecondOrderPayloadIsStoredAndReadBackIntact(string $payload): void
    {
        $user = new User();
        $user->first_name = $payload;
        $this->assertTrue($user->save(), (string)$user->fail()?->getMessage());

        $found = (new User())->find("first_name = :name", ["name" => $user->first_name])->fetch(true);
        $this->assertCount(1, $found);
        $this->assertSame($payload, $found[0]->first_name);
        $this->assertSame(5, (int)$this->value("SELECT COUNT(*) FROM users"));
    }

    public static function identifierPayloads(): array
    {
        return [
            ["id\n"],
            ["first_name\n"],
            ["id/**/DESC"],
            ["id#"],
            ["id -- x"],
            ["`id`;DROP TABLE users"],
            ["`a``b`"],
            ["id DESC,"],
            [",id"],
            [""],
            [" "],
            ["id DESC DESC"],
            ["us.id.x"],
            ["1"],
            ["@@version"],
            ["id, (SELECT 1)"],
            ["SLEEP(5)"],
            ["id'"],
            ["id\""],
            ["id\0"],
            ["ïd"]
        ];
    }

    #[DataProvider('identifierPayloads')]
    public function testOrderRejects(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new User())->find()->order($payload);
    }

    #[DataProvider('identifierPayloads')]
    public function testGroupRejects(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new User())->find()->group($payload);
    }

    #[DataProvider('identifierPayloads')]
    public function testInColumnRejects(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new User())->find()->in($payload, [1]);
    }

    #[DataProvider('identifierPayloads')]
    public function testSaveColumnRejects(string $payload): void
    {
        $user = new User();
        $user->first_name = "Ana";
        $user->$payload = 1;

        $this->expectException(InvalidArgumentException::class);
        $user->save();
    }

    public static function columnPayloads(): array
    {
        return [
            ["*, (SELECT 1)"],
            ["COUNT(*)"],
            ["id AS `x`; --"],
            ["id FROM users; DROP TABLE users; --"],
            ["* FROM users WHERE 1=1 OR"],
            ["id\n"]
        ];
    }

    #[DataProvider('columnPayloads')]
    public function testSelectColumnsReject(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new User())->find(null, null, $payload);
    }

    public function testFillCannotSmuggleColumnName(): void
    {
        $user = (new User())->fill(["first_name" => "Ana", "is_admin`=1, `first_name" => "x"], ["first_name"]);
        $this->assertTrue($user->save());
        $this->assertSame(0, $user->is_admin);
    }

    public function testInjectedParamKeyFails(): void
    {
        $model = new User();
        $this->assertNull($model->find("id = :id", ["id) OR (1=1" => 1])->fetch(true));
        $this->assertNotNull($model->fail());
    }

    public function testLimitAndOffsetOnlyAcceptIntegers(): void
    {
        $model = new User();
        foreach ([fn() => $model->find()->limit("1; DROP TABLE users"), fn() => $model->find()->offset("0 UNION SELECT 1")] as $call) {
            try {
                $call();
                $this->fail("Deveria ter recusado");
            } catch (TypeError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFindByIdWithPayloadReturnsAtMostOneRow(): void
    {
        $user = (new User())->findById("2 OR 1=1");
        $this->assertSame(2, $user?->id, "MySQL converte '2 OR 1=1' em 2: nunca vira condição");
        $this->assertNull((new User())->findById("' OR '1'='1"));
    }

    public function testStackedQueriesInRawTermsAreBlocked(): void
    {
        $model = new User();
        $model->find("id = 1; DROP TABLE logs")->fetch(true);

        $this->assertNotNull($model->fail());
        $this->assertSame(1, (int)$this->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'logs'"));
    }

    public function testInListPlaceholderNameIsValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new class extends User {
            public function attack(): array
            {
                return static::inList([1], "x) OR (1=1");
            }
        })->attack();
    }

    public function testBacktickQuotedColumnStillWorks(): void
    {
        $this->pdo->exec("INSERT INTO menus (name, `order`) VALUES ('A', 2), ('B', 1)");
        $menus = (new Menu())->find()->order("`order` ASC")->fetch(true);
        $this->assertSame("B", $menus[0]->name);
    }
}
