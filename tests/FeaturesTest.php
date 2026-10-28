<?php

namespace BrLayer\Tests;

use BrLayer\DataLayer;
use BrLayer\Tests\Models\StrictUser;
use BrLayer\Tests\Models\User;
use PDOException;
use RuntimeException;

class FeaturesTest extends TestCase
{
    public function testTransactionCommits(): void
    {
        $id = DataLayer::transaction(function () {
            $user = new User();
            $user->first_name = "Eva";
            $user->save();
            return $user->id;
        });

        $this->assertSame(5, $id);
        $this->assertNotNull((new User())->findById(5));
    }

    public function testTransactionRollsBackOnException(): void
    {
        try {
            DataLayer::transaction(function () {
                $user = new StrictUser();
                $user->first_name = "Eva";
                $user->save();

                $dup = new StrictUser();
                $dup->first_name = "Dup";
                $dup->email = "ana@x.com";
                $dup->save();
            });
            $this->fail("Deveria ter lançado a exceção");
        } catch (PDOException) {
            $this->assertSame(4, (int)$this->value("SELECT COUNT(*) FROM users"));
        }
    }

    public function testNestedTransactionIsCommittedByOuter(): void
    {
        try {
            DataLayer::transaction(function () {
                DataLayer::transaction(function () {
                    $user = new User();
                    $user->first_name = "Eva";
                    $user->save();
                });
                throw new RuntimeException("falha depois do aninhado");
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(4, (int)$this->value("SELECT COUNT(*) FROM users"));
    }

    public function testRowsRowAndExecute(): void
    {
        $model = new User();

        $this->assertSame([["is_admin" => 0, "total" => 4]], $model->stats());
        $this->assertSame("Bruno", $model->firstByIds([4, 2])["first_name"]);
        $this->assertNull($model->firstByIds([]));
        $this->assertSame(4, $model->promoteAll());
        $this->assertSame(1, (int)$this->value("SELECT MIN(is_admin) FROM users"));
    }

    public function testInListIsGenericAndDeduplicates(): void
    {
        $model = new User();
        $this->assertSame([1, 3], $model->idsIn([3, "1", 1, null, 3]));
        $this->assertSame([], $model->idsIn([]));
    }

    public function testRawQueryFailureGoesToFail(): void
    {
        $model = new User();
        $this->assertSame([], $model->rawFails());
        $this->assertInstanceOf(PDOException::class, $model->fail());

        $this->expectException(PDOException::class);
        (new StrictUser())->rawFails();
    }
}
