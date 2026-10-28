<?php

namespace BrLayer\Tests;

use BrLayer\Tests\Models\BrokenUser;
use BrLayer\Tests\Models\StrictBrokenUser;
use BrLayer\Tests\Models\User;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

class SecurityTest extends TestCase
{
    public static function validOrders(): array
    {
        return [
            ["first_name"],
            ["us.first_name"],
            ["us.first_name DESC"],
            ["us.first_name desc, us.id ASC"],
            ["`order`"]
        ];
    }

    #[DataProvider('validOrders')]
    public function testOrderAcceptsColumns(string $order): void
    {
        (new User())->find()->order($order);
        $this->addToAssertionCount(1);
    }

    public static function invalidOrders(): array
    {
        return [
            ["(SELECT IF(SUBSTR(password,1,1)='a', SLEEP(5), 0) FROM admins LIMIT 1)"],
            ["id; DROP TABLE users"],
            ["first_name -- "],
            ["RAND()"],
            ["id DESC LIMIT 1"]
        ];
    }

    #[DataProvider('invalidOrders')]
    public function testOrderRejectsInjection(string $order): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new User())->find()->order($order);
    }

    public function testOrderRawAllowsFunctions(): void
    {
        $this->assertCount(4, (new User())->find()->orderRaw("RAND()")->fetch(true));
    }

    public function testGroupColumnsAndInAreValidated(): void
    {
        $model = new User();
        foreach ([
            fn() => $model->find()->group("is_admin; DROP TABLE users"),
            fn() => $model->find(null, null, "(SELECT password FROM admins)"),
            fn() => $model->find()->in("id) OR (1=1", [1])
        ] as $call) {
            try {
                $call();
                $this->fail("Deveria ter recusado");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInvalidColumnNameOnSaveIsRejected(): void
    {
        $user = new User();
        $user->first_name = "Ana";
        $user->{"is_admin) VALUES (1); --"} = 1;

        $this->expectException(InvalidArgumentException::class);
        $user->save();
    }

    public function testQueryStringParamsCannotOverrideWithArray(): void
    {
        $name = "x&first_name=Ana";
        $user = (new User())->find("first_name = :first_name", ["first_name" => $name])->fetch();
        $this->assertNull($user);
    }

    public function testGetDoesNotCallDataLayerMethods(): void
    {
        $user = (new User())->findById(1);

        $this->assertNull($user->destroy);
        $this->assertNull($user->save);
        $this->assertNotNull((new User())->findById(1));
    }

    public function testGetCallsModelAccessors(): void
    {
        $user = (new User())->findById(1);

        $this->assertSame("Ana Silva", $user->full_name);
        $this->assertSame("Ana Silva", $user->fullName);
        $this->assertNull($user->greet);
    }

    public function testConnectionErrorShowsRealReason(): void
    {
        $user = new BrokenUser();
        $this->assertNull($user->findById(1));
        $this->assertInstanceOf(PDOException::class, $user->fail());
        $this->assertStringContainsString("Access denied", $user->fail()->getMessage());
    }

    public function testConnectionErrorThrowsWithThrowOnFail(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage("Access denied");
        (new StrictBrokenUser())->findById(1);
    }

    public function testDumpHidesDatabasePassword(): void
    {
        $dump = print_r(new BrokenUser(), true);
        $this->assertStringNotContainsString("senha-errada", $dump);
        $this->assertStringContainsString("********", $dump);
    }

    public function testFillOnlyAllowedFields(): void
    {
        $post = ["first_name" => "Eva", "last_name" => "Reis", "is_admin" => 1];

        $user = (new User())->fill($post, ["first_name", "last_name"]);
        $this->assertTrue($user->save());

        $this->assertSame("Reis", $user->last_name);
        $this->assertSame(0, $user->is_admin);
    }

    public function testFillWithFillableNeverTakesPrimaryKey(): void
    {
        $user = (new User())->findById(2);
        $user->fill(["id" => 1, "first_name" => "Bruna"]);
        $this->assertTrue($user->save());

        $this->assertSame("Ana", (new User())->findById(1)->first_name);
        $this->assertSame("Bruna", (new User())->findById(2)->first_name);
    }

    public function testFillAcceptsPrimaryKeyWhenListedExplicitly(): void
    {
        $user = (new User())->fill(["id" => 2, "first_name" => "Bruna"], ["id", "first_name"]);
        $this->assertSame(2, $user->id);
    }

    public function testFillWithoutListThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new \BrLayer\Tests\Models\Menu())->fill(["name" => "x"]);
    }

    public function testFillWithColumnNamedLikeInternalState(): void
    {
        $menu = (new \BrLayer\Tests\Models\Menu())->fill(["name" => "Home", "order" => 1], ["name", "order"]);
        $this->assertTrue($menu->save());
        $this->assertSame(1, (int)$this->value("SELECT `order` FROM menus WHERE id = 1"));
    }
}
