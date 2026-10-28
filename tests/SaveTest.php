<?php

namespace BrLayer\Tests;

use BrLayer\Tests\Models\Log;
use BrLayer\Tests\Models\Menu;
use BrLayer\Tests\Models\Product;
use BrLayer\Tests\Models\StrictUser;
use BrLayer\Tests\Models\Token;
use BrLayer\Tests\Models\User;
use PDOException;

class SaveTest extends TestCase
{
    public function testCreateFillsBothTimestamps(): void
    {
        $user = new User();
        $user->first_name = "Eva";

        $this->assertTrue($user->save());
        $this->assertSame(5, $user->id);
        $this->assertNotNull($user->created_at);
        $this->assertSame($user->created_at, $user->updated_at);
    }

    public function testUpdateFillsUpdatedAtOnly(): void
    {
        $this->pdo->exec("UPDATE users SET created_at = '2020-01-01 00:00:00' WHERE id = 1");

        $user = (new User())->findById(1);
        $user->first_name = "Ana Maria";

        $this->assertTrue($user->save());
        $this->assertSame("Ana Maria", $this->value("SELECT first_name FROM users WHERE id = 1"));
        $this->assertSame("2020-01-01 00:00:00", $user->created_at);
        $this->assertNotNull($user->updated_at);
    }

    public function testOnlyCreatedAt(): void
    {
        $log = new Log();
        $log->message = "entrou";
        $this->assertTrue($log->save());
        $this->assertNotNull($log->created_at);

        $log->message = "saiu";
        $this->assertTrue($log->save());
        $this->assertSame("saiu", $this->value("SELECT message FROM logs WHERE id = 1"));
    }

    public function testNoTimestampsByDefault(): void
    {
        $menu = new Menu();
        $menu->name = "Contato";
        $menu->order = 2;

        $this->assertTrue($menu->save());
        $this->assertSame(2, $menu->order);
    }

    public function testRequiredAcceptsZeroStringAndReportsMissingFields(): void
    {
        $product = new Product();
        $product->name = "Caneta";
        $product->stock = "0";
        $this->assertTrue($product->save());

        $empty = new Product();
        $empty->stock = 1;
        $this->assertFalse($empty->save());
        $this->assertStringContainsString("name", $empty->fail()->getMessage());
    }

    public function testFailIsClearedAfterSuccess(): void
    {
        $user = new User();
        $user->first_name = "Dup";
        $user->email = "ana@x.com";
        $this->assertFalse($user->save());
        $this->assertInstanceOf(PDOException::class, $user->fail());

        $user->email = "novo@x.com";
        $this->assertTrue($user->save());
        $this->assertNull($user->fail());
    }

    public function testThrowOnFailKeepsOriginalException(): void
    {
        $user = new StrictUser();
        $user->first_name = "Dup";
        $user->email = "ana@x.com";

        try {
            $user->save();
            $this->fail("Deveria ter lançado a exceção");
        } catch (PDOException $exception) {
            $this->assertSame("23000", $exception->getCode());
        }
    }

    public function testUuidPrimaryKeyCreatesThenUpdates(): void
    {
        $token = new Token();
        $token->id = "a3f1c2e4-0000-4000-8000-000000000001";
        $token->name = "primeiro";
        $this->assertTrue($token->save());

        $token->name = "alterado";
        $this->assertTrue($token->save());

        $this->assertSame(1, (int)$this->value("SELECT COUNT(*) FROM tokens"));
        $this->assertSame("alterado", (new Token())->findById("a3f1c2e4-0000-4000-8000-000000000001")->name);
    }

    public function testBooleanFalseIsSavedAsZero(): void
    {
        $product = new Product();
        $product->name = "Lápis";
        $product->stock = 3;
        $product->active = false;

        $this->assertTrue($product->save());
        $this->assertSame(0, (int)$this->value("SELECT active FROM products WHERE id = 1"));
    }

    public function testDateTimeIsSavedFormatted(): void
    {
        $menu = new Menu();
        $menu->name = "Agenda";
        $menu->data = new \DateTime("2025-12-25");

        $this->assertTrue($menu->save());
        $this->assertSame("2025-12-25", $this->value("SELECT data FROM menus WHERE id = 1"));
    }

    public function testEmojiRoundTrip(): void
    {
        $user = new User();
        $user->first_name = "Zé 😀";
        $this->assertTrue($user->save());
        $this->assertSame("Zé 😀", (new User())->findById($user->id)->first_name);
    }

    public function testDestroy(): void
    {
        $user = (new User())->findById(1);
        $this->assertTrue($user->destroy());
        $this->assertNull((new User())->findById(1));

        $this->assertFalse((new User())->destroy());
    }
}
