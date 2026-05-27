<?php
require __DIR__ . '/../vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class)) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

echo "PHP: " . PHP_VERSION . "\n";
echo "SAPI: " . php_sapi_name() . "\n";

echo "\n--- \\$_ENV values ---\n";
echo 'DB_HOST: ' . var_export(
    (isset($_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : null),
    true
) . "\n";
echo 'DB_PORT: ' . var_export((isset($_ENV['DB_PORT']) ? $_ENV['DB_PORT'] : null), true) . "\n";
echo 'DB_DATABASE: ' . var_export((isset($_ENV['DB_DATABASE']) ? $_ENV['DB_DATABASE'] : null), true) . "\n";
echo 'DB_USERNAME: ' . var_export((isset($_ENV['DB_USERNAME']) ? $_ENV['DB_USERNAME'] : null), true) . "\n";
echo 'DB_PASSWORD: ' . (isset($_ENV['DB_PASSWORD']) ? str_repeat('*', strlen($_ENV['DB_PASSWORD'])) : 'null') . "\n";

echo "\n--- getenv() values ---\n";
echo 'DB_HOST: ' . var_export(getenv('DB_HOST'), true) . "\n";
echo 'DB_PORT: ' . var_export(getenv('DB_PORT'), true) . "\n";
echo 'DB_DATABASE: ' . var_export(getenv('DB_DATABASE'), true) . "\n";
echo 'DB_USERNAME: ' . var_export(getenv('DB_USERNAME'), true) . "\n";
echo 'DB_PASSWORD: ' . (getenv('DB_PASSWORD') ? str_repeat('*', strlen(getenv('DB_PASSWORD'))) : 'null') . "\n";

echo "\n--- \\$_SERVER values ---\n";
echo 'DB_HOST: ' . var_export((isset($_SERVER['DB_HOST']) ? $_SERVER['DB_HOST'] : null), true) . "\n";
echo 'DB_PORT: ' . var_export((isset($_SERVER['DB_PORT']) ? $_SERVER['DB_PORT'] : null), true) . "\n";

echo "\n--- End ---\n";

// Quick PDO DSN test (no query)
try {
    $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? '127.0.0.1';
    $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? '3306';
    $dbname = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?? '';
    $user = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?? '';
    $pass = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?? '';
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname}";
    echo "\nDSN: {$dsn}\n";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "PDO connect: OK\n";
} catch (Exception $e) {
    echo "PDO connect ERR: " . $e->getMessage() . "\n";
}

?>
