<?php

namespace BrLayer\Tests;

use BrLayer\Query;
use InvalidArgumentException;
use PDOException;

class QueryTest extends TestCase
{
    private function users(): Query
    {
        return Query::create()->select("u.id, u.first_name")->from("users u");
    }

    public function testInnerJoinWithWhereAndOrder(): void
    {
        $rows = Query::create()
            ->select("u.first_name, r.name AS role")
            ->from("users u")
            ->innerJoin("roles r", "r.user_id", "u.id")
            ->where("r.name = ?", "editor")
            ->order("u.first_name")
            ->fetchArray();

        $this->assertSame([
            ["first_name" => "Ana", "role" => "editor"],
            ["first_name" => "Bruno", "role" => "editor"]
        ], $rows);
    }

    public function testLeftJoinKeepsUsersWithoutRole(): void
    {
        $rows = Query::create()
            ->select("u.first_name, r.name AS role")
            ->from("users u")
            ->leftJoin("roles r", "r.user_id", "u.id")
            ->where("r.id IS NULL")
            ->fetchArray();

        $this->assertSame([["first_name" => "Daniel", "role" => null]], $rows);
    }

    public function testJoinsKeepTheOrderTheyWereAdded(): void
    {
        $sql = Query::create()
            ->from("users u")
            ->leftJoin("roles r", "r.user_id", "u.id")
            ->innerJoin("logs l", "l.id", "r.id")
            ->getStatement();

        $this->assertSame(
            "SELECT * FROM users u LEFT JOIN roles r ON r.user_id = u.id INNER JOIN logs l ON l.id = r.id",
            $sql
        );
    }

    public function testRawJoinWithParams(): void
    {
        $rows = Query::create()
            ->select("u.first_name")
            ->from("users u")
            ->join("INNER JOIN roles r ON r.user_id = u.id AND r.name = :role")
            ->params(["role" => "viewer"])
            ->fetchArray();

        $this->assertSame([["first_name" => "Carla"]], $rows);
    }

    public function testQuestionMarkValues(): void
    {
        $this->assertSame(
            [2, 3],
            array_column($this->users()->where("u.id > ? AND u.id < ?", [1, 4])->fetchArray(), "id")
        );

        //um valor só vale para todos os "?"
        $this->assertSame(
            [1],
            array_column($this->users()->where("u.first_name LIKE ? OR u.last_name LIKE ?", "%ilva%")->fetchArray(), "id")
        );
    }

    public function testNamedValues(): void
    {
        $row = $this->users()->where("u.email = :email", ["email" => "carla+teste@x.com"])->fetchArray(false);
        $this->assertSame(["id" => 3, "first_name" => "Carla"], $row);
    }

    public function testQuestionMarkInsideTextIsNotAPlaceholder(): void
    {
        $query = $this->users()->where("u.first_name <> 'Quem?' AND u.id = ?", 2);

        $this->assertStringContainsString("'Quem?'", $query->getStatement());
        $this->assertSame([["id" => 2, "first_name" => "Bruno"]], $query->fetchArray());
    }

    public function testWrongNumberOfValuesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->users()->where("u.id > ? AND u.id < ?", [1]);
    }

    public function testQuestionMarkWithoutValueThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->users()->where("u.id = ?");
    }

    public function testValueWithoutQuestionMarkThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->users()->where("u.id = 1", 5);
    }

    public function testAndWhereAsFirstCondition(): void
    {
        $query = $this->users()->andWhere("u.id = ?", 1);

        $this->assertSame("SELECT u.id, u.first_name FROM users u WHERE (u.id = :brlayer_w0)", $query->getStatement());
        $this->assertCount(1, $query->fetchArray());
    }

    public function testOrWhereDoesNotEscapeOtherConditions(): void
    {
        $rows = $this->users()
            ->where("u.id = ? OR u.id = ?", [1, 2])
            ->andWhere("u.first_name = ?", "Bruno")
            ->orWhere("u.id = ?", 4)
            ->order("u.id")
            ->fetchArray();

        $this->assertSame([2, 4], array_column($rows, "id"));
    }

    public function testWhereInKeepsZeroAndIgnoresNullAndRepeated(): void
    {
        $query = $this->users()->andWhereIn("u.id", [0, "0", 2, "2", null, 3]);

        $this->assertSame(["brlayer_in0" => 0, "brlayer_in1" => 2, "brlayer_in2" => 3], $query->getParams());
        $this->assertSame([2, 3], array_column($query->order("u.id")->fetchArray(), "id"));
    }

    public function testWhereInWithEmptyListFindsNothing(): void
    {
        $this->assertNull($this->users()->andWhereIn("u.id", [])->fetchArray());
        $this->assertNull($this->users()->andWhereIn("u.id", [null])->fetchArray());
    }

    public function testWhereNotIn(): void
    {
        $this->assertSame(
            [3, 4],
            array_column($this->users()->andWhereIn("u.id", [1, 2], true)->order("u.id")->fetchArray(), "id")
        );
        $this->assertCount(4, $this->users()->andWhereIn("u.id", [], true)->fetchArray());
    }

    public function testCountIgnoresOrderLimitAndOffset(): void
    {
        $query = Query::create()
            ->from("users u")
            ->innerJoin("roles r", "r.user_id", "u.id")
            ->order("u.id")
            ->limit(1)
            ->offset(1);

        $this->assertSame(4, $query->count());
        $this->assertCount(1, $query->fetchArray());
    }

    public function testCountWithGroupDistinctAndRawQuery(): void
    {
        $grouped = Query::create()
            ->select("r.user_id, COUNT(*) AS total")
            ->from("roles r")
            ->group("r.user_id");
        $this->assertSame(3, $grouped->count());

        $distinct = Query::create()->select("DISTINCT r.name")->from("roles r");
        $this->assertSame(3, $distinct->count());

        $raw = Query::create()->query("SELECT * FROM users", "id > :id", ["id" => 2]);
        $this->assertSame(2, $raw->count());
        $this->assertSame([3, 4], array_column($raw->fetchArray(), "id"));
    }

    public function testCountWithColumnsWithSameName(): void
    {
        $query = Query::create()->select("u.id, r.id")->from("users u")->innerJoin("roles r", "r.user_id", "u.id");
        $this->assertSame(4, $query->count());
    }

    public function testGroupRawAndOrderRaw(): void
    {
        $rows = Query::create()
            ->select("UPPER(r.name) AS role, COUNT(*) AS total")
            ->from("roles r")
            ->groupRaw("UPPER(r.name)")
            ->orderRaw("COUNT(*) DESC, role")
            ->fetchArray();

        $this->assertSame(["role" => "EDITOR", "total" => 2], $rows[0]);
    }

    public function testLimitAndOffsetNullRemoveThem(): void
    {
        $query = $this->users()->limit(1)->offset(1);
        $this->assertCount(1, $query->fetchArray());

        $this->assertCount(4, $query->limit(null)->offset(null)->fetchArray());
    }

    public function testFetchReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->users()->where("u.id = ?", 99)->fetchArray());
        $this->assertNull($this->users()->where("u.id = ?", 99)->fetchArray(false));
    }

    public function testFailIsStoredOrThrown(): void
    {
        $query = Query::create()->from("missing_table");
        $this->assertNull($query->fetchArray());
        $this->assertSame(0, $query->count());
        $this->assertInstanceOf(PDOException::class, $query->fail());

        $this->assertCount(4, $query->from("users")->fetchArray());
        $this->assertNull($query->fail());

        $this->expectException(PDOException::class);
        Query::create()->from("missing_table")->throwOnFail()->fetchArray();
    }

    public function testInjectionInValuesIsJustText(): void
    {
        foreach (["1 OR 1=1", "1; DROP TABLE roles", "' OR '1'='1", "0) OR (1=1"] as $payload) {
            $this->assertNull($this->users()->where("u.first_name = ?", $payload)->fetchArray(), $payload);
            $this->assertNull($this->users()->andWhereIn("u.first_name", [$payload])->fetchArray(), $payload);
        }
        $this->assertSame(4, (int)$this->value("SELECT COUNT(*) FROM roles"));
    }

    public function testInjectionInOrderAndGroupIsRefused(): void
    {
        foreach (["id; DROP TABLE roles", "(SELECT 1)", "id, SLEEP(1)"] as $payload) {
            try {
                $this->users()->order($payload);
                $this->fail("order aceitou: {$payload}");
            } catch (InvalidArgumentException) {
            }
            try {
                $this->users()->group($payload);
                $this->fail("group aceitou: {$payload}");
            } catch (InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testStackedQueryIsBlocked(): void
    {
        $query = Query::create()->query("SELECT * FROM users; DROP TABLE roles");
        $query->fetchArray();

        $this->assertInstanceOf(PDOException::class, $query->fail());
        $this->assertSame(4, (int)$this->value("SELECT COUNT(*) FROM roles"));
    }

    public function testDebugInfoHidesPassword(): void
    {
        $dump = print_r(Query::create(testDatabase(["passwd" => "segredo"])), true);
        $this->assertStringNotContainsString("segredo", $dump);
    }

    public function testTypedValues(): void
    {
        $rows = Query::create()
            ->select("id")
            ->from("users")
            ->where("is_admin = ? AND created_at IS NULL", false)
            ->fetchArray();

        $this->assertCount(4, $rows);
    }
}
