<?php
/*
 * Lista dos exemplos: php -S 127.0.0.1:8090 -t example
 */
$examples = [
    "find_example.php" => "Consultas: find, findById, in, count, join, colunas",
    "save_example.php" => "Cadastro e alteração (ative os blocos no arquivo)",
    "destroy_example.php" => "Remoção (apaga o usuário 6)",
    "pdo_example.php" => "Conexão PDO direta",
    "multi_db_example.php" => "Vários bancos (precisa do banco \"upinside\")"
];
?>
<h1>BrLayer: exemplos</h1>
<ul>
    <?php foreach ($examples as $file => $description): ?>
        <li><a href="<?= $file ?>"><?= $file ?></a>: <?= $description ?></li>
    <?php endforeach; ?>
</ul>
