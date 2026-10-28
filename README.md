# BrLayer

[![Maintainer](https://img.shields.io/badge/maintainer-@kevind3v-blue.svg?style=flat-square)](https://github.com/kevind3v)
[![Source Code](https://img.shields.io/badge/source-brbunny/brlayer-blue.svg?style=flat-square)](https://github.com/kevind3v/brlayer)
[![PHP from Packagist](https://img.shields.io/packagist/php-v/brbunny/brlayer.svg?style=flat-square)](https://packagist.org/packages/brbunny/brlayer)
[![Latest Version](https://img.shields.io/github/release/kevind3v/brlayer.svg?style=flat-square)](https://github.com/kevind3v/brlayer/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/brbunny/brlayer.svg?style=flat-square)](https://packagist.org/packages/brbunny/brlayer)

Camada de persistência para PHP 8.2+ com PDO e prepared statements. Você cria uma model por tabela
(padrão Active Record) e cadastra, lê, edita e remove dados com poucas linhas.

> Fork mantido da [CoffeeCode DataLayer](https://github.com/robsonvleite/datalayer), de Robson V. Leite,
> atualizado para o PHP 8.x e com correções de segurança. Veja [Migrando da CoffeeCode DataLayer](#migrando-da-coffeecode-datalayer).

## Instalação

```bash
composer require brbunny/brlayer
```

## Conexão

```php
const BRLAYER_CONFIG = [
    "driver" => "mysql",
    "host" => "localhost",
    "port" => "3306",
    "dbname" => "meu_banco",
    "username" => "root",
    "passwd" => "",
    "charset" => "utf8mb4",
    "options" => [
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ
    ]
];
```

- `charset` (só MySQL) entra no DSN. Use `utf8mb4` para aceitar emojis.
- `dsn` (opcional) substitui o DSN montado, ex.: `"dsn" => "sqlite:/caminho/banco.db"`.
- O modo de erro do PDO é sempre `ERRMODE_EXCEPTION`, independente das `options`.

Se a conexão falhar, a exceção real (ex.: `Access denied for user...`) vai para o `fail()` da model,
ou é lançada com `$throwOnFail`.

Proteções da conexão:

- **Um comando por chamada** (MySQL): `"...; DROP TABLE x"` é recusado pelo banco, mesmo que algum SQL
  seja montado concatenando valores. Para liberar, passe `Pdo\Mysql::ATTR_MULTI_STATEMENTS => true` nas `options`.
- **Conexão perdida** ("server has gone away"): a operação que pegou a queda falha normalmente e a
  próxima abre uma conexão nova. A operação **não** é repetida automaticamente, para não duplicar
  INSERT/UPDATE. Para descartar a conexão manualmente (ex.: em workers): `Connect::forget()`.

## Model

```php
use BrLayer\Model;

class User extends Model
{
    public function __construct()
    {
        // tabela, campos obrigatórios, chave primária (padrão "id"), banco (padrão BRLAYER_CONFIG)
        parent::__construct("users", ["first_name", "last_name"]);
    }
}
```

### Datas de criação e alteração

Desligadas por padrão. Declare só as colunas que a tabela tem:

```php
class User extends Model
{
    protected ?string $createdAt = "created_at"; // preenchida ao criar
    protected ?string $updatedAt = "updated_at"; // preenchida ao criar e ao alterar
}

class Log extends Model
{
    protected ?string $createdAt = "log_created_at"; // tabela só com data de criação
}
```

### Lançar exceção nas falhas

Por padrão, as falhas ficam em `fail()` e o método devolve `false`/`null`. Com `$throwOnFail`, a exceção
original (com o código SQL, ex.: `23000` para duplicidade) é lançada:

```php
class User extends Model
{
    protected bool $throwOnFail = true;
}
```

## Consultas

```php
$model = new User();

$users = $model->find()->fetch(true);                                  // todos
$users = $model->find()->limit(2)->offset(2)->order("first_name ASC")->fetch(true);
$users = $model->find()->in("id", [1, 2, 3])->fetch(true);
$user = $model->find("email = :email", ["email" => $email])->fetch();  // um registro
$user = $model->findById(2);                                           // id inteiro ou texto (UUID)
$total = $model->find("genre = :g", ["g" => "f"])->count();

foreach ($users ?? [] as $user) {
    echo $user->first_name;
}
```

- Cada `find()` começa uma consulta nova: filtros, ordem e limites da anterior são descartados.
- `fetch(true)` devolve um array de models ou `null` quando não encontra nada.
- `count()` considera o WHERE e o `in()`, e ignora `group`, `order`, `limit` e `offset`.

### Parâmetros

Passe os valores sempre por placeholder, como array:

```php
$model->find("first_name = :name AND last_name = :last", ["name" => "Ana", "last" => "Silva"]);
```

O formato antigo em query string (`"name=Ana&last=Silva"`) ainda funciona, mas evite: nele `+` vira
espaço e um valor com `&` consegue alterar outros parâmetros.

### Nomes de coluna validados

Os nomes de coluna não podem ir por placeholder, então a lib valida tudo que entra no SQL como nome:
`order()`, `group()`, as colunas do `find()`, a coluna do `in()` e as colunas gravadas pelo `save()`.
Aceita letras, números, `_`, `tabela.coluna` e `` `crase` ``. O resto lança `InvalidArgumentException`.

```php
$model->find()->order("us.first_name DESC, us.id");   // ok
$model->find()->order($_GET["sort"]);                 // seguro: algo fora do padrão é recusado
$model->find()->orderRaw("RAND()");                   // sem validação: nunca passe dados do usuário
```

O `WHERE` (`$terms`) é SQL livre: escreva os placeholders e nunca concatene valores nele.

## Salvar e remover

```php
$user = new User();
$user->first_name = "Ana";
$user->last_name = "Silva";
$user->save(); // cria (sem id) ou atualiza (com id existente)

$user = (new User())->findById(2);
$user->first_name = "Bruna";
$user->save();

$user->destroy();

if ($user->fail()) {
    echo $user->fail()->getMessage();
}
```

- Campos obrigatórios aceitam `0` e `"0"`. Só faltam quando não existem, são `null` ou `""`.
- Com chave definida pelo sistema (ex.: UUID), o `save()` cria quando o registro não existe.
- `true`/`false` são gravados como `1`/`0` e `DateTime` como `Y-m-d H:i:s`.
- Valor que não pode ir para o banco (ex.: array vindo de `campo[]=x` no formulário) é uma falha comum:
  vai para o `fail()` (`DomainException`), ou é lançada com `$throwOnFail`.
- `fail()` volta a `null` a cada operação.

### Dados de formulário: `fill()`

Preenche a model só com os campos permitidos. O resto (ex.: um `is_admin` injetado no formulário) é ignorado:

```php
$user = (new User())->fill($_POST, ["first_name", "last_name", "email"]);
$user->save();
```

Sem a lista, o `fill()` usa a propriedade `$fillable` da model. Sem nenhuma das duas, lança exceção.
A chave primária nunca entra pelo `$fillable`; só se for informada na lista do próprio `fill()`.

Atribuições diretas continuam livres: `$user->is_admin = 1;`.

## Métodos da model

Métodos públicos sem argumentos criados na sua model podem ser lidos como propriedade:

```php
class User extends Model
{
    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}

echo $user->full_name; // Ana Silva
```

Os métodos da própria BrLayer (`save`, `destroy`, `find`...) nunca são chamados assim.

## Transação

```php
Model::transaction(function () {
    $user->save();
    $address->save();
});
```

Confirma no fim, ou desfaz tudo se qualquer exceção for lançada (e repassa a exceção). Dentro de outra
transação, só roda dentro dela. Para que uma falha de `save()` desfaça a transação, use `$throwOnFail = true`.
Para outro banco: `Model::transaction($action, OUTRO_BANCO)`.

## SQL próprio

Para agregações, JOIN, UNION e comandos em lote, a model tem métodos protegidos:

```php
class User extends Model
{
    public function totalsByGenre(array $ids): array
    {
        [$in, $params] = static::inList($ids, "uid"); // ":uid0, :uid1" + valores
        return $this->rows(
            "SELECT genre, COUNT(*) AS total FROM users WHERE id IN ({$in}) GROUP BY genre",
            $params
        );
    }
}
```

| Método | Devolve |
|---|---|
| `rows($sql, $params)` | todas as linhas (arrays associativos) |
| `row($sql, $params)` | a primeira linha ou `null` |
| `execute($sql, $params)` | quantidade de linhas alteradas |
| `static::inList($values, $name)` | placeholders e valores de um `IN`. Lista vazia vira `NULL` (não encontra nada) |
| `static::now($modify)` | data e hora no fuso do PHP, ex.: `now("-30 days")` |

## Consultas com JOIN: `Query`

Para relatórios e consultas com mais de uma tabela, monte a consulta por partes. O resultado vem em
arrays associativos:

```php
use BrLayer\Query;

$query = Query::create()
    ->select("u.id, u.first_name, c.name AS company")
    ->from("users u")
    ->leftJoin("companies c", "c.id", "u.company_id")
    ->where("u.status = ?", "ativo")
    ->andWhere("u.first_name LIKE ? OR u.email LIKE ?", "%{$busca}%")
    ->andWhereIn("u.company_id", $empresas)
    ->order("u.first_name")
    ->limit(20)
    ->offset(40);

$total = $query->count();        // ignora order, limit e offset (para paginação)
$users = $query->fetchArray();   // todas as linhas, ou null
$user = $query->fetchArray(false); // só a primeira
```

**Valores** vão sempre por placeholder, em `where()`, `andWhere()` e `orWhere()`:

| Chamada | Valores |
|---|---|
| `where("id > ? AND id < ?", [1, 9])` | um por `?`, na ordem |
| `where("name LIKE ? OR email LIKE ?", "%ana%")` | um valor só, usado em todos os `?` |
| `where("email = :email", ["email" => $email])` | por nome |

A quantidade de valores precisa bater com a de `?`; senão a `Query` lança `InvalidArgumentException`.
Um `?` dentro de um texto (`'Quem?'`) não conta.

Detalhes:

- Cada condição entra entre parênteses: `where("a OR b")->andWhere("c")` vira `(a OR b) AND (c)`.
- `andWhereIn()` com lista vazia não encontra nada. Com `true` no terceiro parâmetro (`NOT IN`), lista
  vazia não exclui nada. Nulos e repetidos saem; `0` fica.
- `join("LEFT JOIN roles r ON r.user_id = u.id AND r.name = :role")` para ON com mais de uma condição,
  com os valores em `params(["role" => "admin"])`.
- `query("SELECT ...", "id > :id", ["id" => 2])` usa um SQL inteiro escrito por você. `where()`,
  `order()`, `limit()` e `count()` continuam funcionando.
- `group()` e `order()` são validados como na model. Para funções, use `groupRaw()` e `orderRaw()`.
- `limit(null)` e `offset(null)` removem o limite.
- Falhas ficam em `fail()`. Para lançar a exceção: `Query::create()->throwOnFail()`.
- Outro banco: `Query::create(OUTRO_BANCO)`.

> `select()`, `from()`, os joins e o texto do `where()` vão para o SQL como foram escritos. Nunca coloque
> dados do usuário neles: valores vão pelos placeholders.

## Depuração

```php
$model->find("id > :id", ["id" => 1])->order("id");
$model->getStatement(); // SELECT * FROM users WHERE id > :id ORDER BY id
$model->getParams();    // ["id" => 1]
```

`var_dump`/`print_r` de uma model mostram `passwd` como `********`.

## Migrando da CoffeeCode DataLayer

1. A classe base agora é `BrLayer\Model`: troque `use CoffeeCode\DataLayer\DataLayer;` por
   `use BrLayer\Model;` e `extends DataLayer` por `extends Model`. O `Connect` virou `BrLayer\Connect`.
   A constante `DATA_LAYER_CONFIG` continua funcionando; o nome novo é `BRLAYER_CONFIG`.
2. **Timestamps agora são desligados por padrão.** Nas models que usam `created_at`/`updated_at`, declare
   `$createdAt` e `$updatedAt`. O 4º parâmetro do construtor (`bool $timestamps`) saiu: o banco
   passou a ser o 4º.
3. `updated_at` agora também é preenchido na criação.
4. `Connect::getInstance()` lança a exceção quando não conecta (antes devolvia `null`).
5. Ler `$model->save`, `$model->destroy` etc. como propriedade não executa mais o método.
6. O estado interno (`data`, `params`, `order`...) é privado. Use `data()`, `getParams()`, `getStatement()`.
   Model que sobrescreve `create()`, `update()`, `rows()` ou `execute()` precisa ajustar a assinatura
   (os parâmetros agora aceitam `array|string|null`).
7. `RAND()` e outras funções no `order()`: use `orderRaw()`.
8. `in()` com lista vazia não encontra nada (antes dava erro de SQL).

## Testes

Os testes usam um MySQL de verdade (banco `brlayer_test`). Ajuste a conexão pelas variáveis
`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASS` (padrões no `phpunit.xml`):

```bash
mysql -uroot -p -e "CREATE DATABASE brlayer_test CHARACTER SET utf8mb4"
DB_PASS=senha composer test
```

## Créditos

- [Robson V. Leite](https://github.com/robsonvleite): autor da CoffeeCode DataLayer original
- [Kevin Siqueira](https://github.com/kevind3v): BrLayer

## Licença

MIT. Veja o [LICENSE](LICENSE).
