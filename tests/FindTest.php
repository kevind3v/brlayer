<?php

namespace BrLayer\Tests;

use BrLayer\Tests\Models\Menu;
use BrLayer\Tests\Models\User;

class FindTest extends TestCase
{
    public function testFindAllAndById(): void
    {
        $users = (new User())->find()->order("id")->fetch(true);
        $this->assertCount(4, $users);
        $this->assertInstanceOf(User::class, $users[0]);
        $this->assertSame("Ana", $users[0]->first_name);

        $this->assertSame("Bruno", (new User())->findById(2)->first_name);
        $this->assertNull((new User())->findById(999));
    }

    public function testNewFindDiscardsPreviousParams(): void
    {
        $model = new User();
        $model->find("email = :email", ["email" => "ana@x.com"])->fetch();

        $all = $model->find()->fetch(true);

        $this->assertNull($model->fail());
        $this->assertCount(4, $all);
    }

    public function testNewFindDiscardsPreviousLimitAndOrder(): void
    {
        $model = new User();
        $model->find()->order("id DESC")->limit(1)->fetch(true);

        $all = $model->find()->fetch(true);

        $this->assertCount(4, $all);
        $this->assertSame(1, $all[0]->id);
    }

    public function testLimitOffsetOrder(): void
    {
        $users = (new User())->find()->order("id DESC")->limit(2)->offset(1)->fetch(true);
        $this->assertSame([3, 2], array_map(fn($user) => $user->id, $users));
    }

    public function testInWithRepeatedValues(): void
    {
        $users = (new User())->find()->in("id", [2, 1, 2])->order("id")->fetch(true);
        $this->assertSame([1, 2], array_map(fn($user) => $user->id, $users));
    }

    public function testInWithEmptyListFindsNothing(): void
    {
        $model = new User();
        $this->assertNull($model->find()->in("id", [])->fetch(true));
        $this->assertNull($model->fail());
    }

    public function testTwoInCallsKeepTheirOwnValues(): void
    {
        $users = (new User())->find()
            ->in("id", [1, 2, 3])
            ->in("first_name", ["Bruno", "Carla", "Daniel"])
            ->order("id")
            ->fetch(true);

        $this->assertSame([2, 3], array_map(fn($user) => $user->id, $users));
    }

    public function testInCombinedWithOrTermsKeepsPrecedence(): void
    {
        $users = (new User())->find("id = :a OR id = :b", ["a" => 1, "b" => 2])
            ->in("first_name", ["Bruno"])
            ->fetch(true);

        $this->assertCount(1, $users);
        $this->assertSame("Bruno", $users[0]->first_name);
    }

    public function testInWithoutFind(): void
    {
        $users = (new User())->in("id", [4])->fetch(true);
        $this->assertSame("Daniel", $users[0]->first_name);
    }

    public function testCountUsesFiltersAndIgnoresLimit(): void
    {
        $model = new User();
        $this->assertSame(4, $model->find()->limit(1)->count());
        $this->assertSame(2, $model->find("id > :id", ["id" => 2])->count());
        $this->assertSame(1, $model->find()->in("id", [1, 99])->count());
    }

    public function testParamsAsArrayKeepPlusSign(): void
    {
        $user = (new User())->find("email = :email", ["email" => "carla+teste@x.com"])->fetch();
        $this->assertSame("Carla", $user->first_name);
    }

    public function testParamsAsQueryStringStillWork(): void
    {
        $user = (new User())->find("first_name = :name AND last_name = :last", "name=Bruno&last=Souza")->fetch();
        $this->assertSame(2, $user->id);
    }

    public function testParamsWithColonPrefix(): void
    {
        $user = (new User())->find("first_name = :name", [":name" => "Ana"])->fetch();
        $this->assertSame(1, $user->id);
    }

    public function testColumnsSelectionAndGroup(): void
    {
        $users = (new User())->find(null, null, "id, first_name AS name")->order("id")->fetch(true);
        $this->assertSame("Ana", $users[0]->name);
        $this->assertNull($users[0]->email);

        $groups = (new User())->find(null, null, "is_admin")->group("is_admin")->fetch(true);
        $this->assertCount(1, $groups);
    }

    public function testColumnsNamedDataAndOrderAreReadable(): void
    {
        $this->pdo->exec("INSERT INTO menus (name, `order`, data) VALUES ('Home', 3, '2024-03-10')");

        $menu = (new Menu())->findById(1);

        $this->assertSame(3, $menu->order);
        $this->assertSame("2024-03-10", $menu->data);
        $this->assertSame("Home", $menu->data()->name);
    }

    public function testDebugHelpers(): void
    {
        $model = (new User())->find("id = :id", ["id" => 1])->in("id", [1, 2])->order("id")->limit(5);

        $this->assertSame(
            "SELECT * FROM users WHERE (id = :id) AND (id IN (:in0_0, :in0_1)) ORDER BY id LIMIT 5",
            $model->getStatement()
        );
        $this->assertSame(["id" => 1, "in0_0" => 1, "in0_1" => 2], $model->getParams());
    }
}
