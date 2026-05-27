<?php
require __DIR__ . '/../vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class)) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$host = (isset($_ENV['DB_HOST']) && $_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : ((isset($_SERVER['DB_HOST']) && $_SERVER['DB_HOST']) ? $_SERVER['DB_HOST'] : (getenv('DB_HOST') ?: '127.0.0.1'));
$port = (isset($_ENV['DB_PORT']) && $_ENV['DB_PORT']) ? $_ENV['DB_PORT'] : ((isset($_SERVER['DB_PORT']) && $_SERVER['DB_PORT']) ? $_SERVER['DB_PORT'] : (getenv('DB_PORT') ?: '3306'));
$dbname = (isset($_ENV['DB_DATABASE']) && $_ENV['DB_DATABASE']) ? $_ENV['DB_DATABASE'] : ((isset($_SERVER['DB_DATABASE']) && $_SERVER['DB_DATABASE']) ? $_SERVER['DB_DATABASE'] : (getenv('DB_DATABASE') ?: ''));
$user = (isset($_ENV['DB_USERNAME']) && $_ENV['DB_USERNAME']) ? $_ENV['DB_USERNAME'] : ((isset($_SERVER['DB_USERNAME']) && $_SERVER['DB_USERNAME']) ? $_SERVER['DB_USERNAME'] : (getenv('DB_USERNAME') ?: ''));
$pass = (isset($_ENV['DB_PASSWORD']) && $_ENV['DB_PASSWORD']) ? $_ENV['DB_PASSWORD'] : ((isset($_SERVER['DB_PASSWORD']) && $_SERVER['DB_PASSWORD']) ? $_SERVER['DB_PASSWORD'] : (getenv('DB_PASSWORD') ?: ''));

$attempts = [
    'default' => [],
    'ssl_required' => [
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ],
];

foreach ($attempts as $label => $options) {
    echo "=== {$label} ===\n";
    $maxTries = 3;
    for ($try = 1; $try <= $maxTries; $try++) {
        echo "Attempt {$try}/{$maxTries}...\n";
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname}";
            $pdo = new PDO($dsn, $user, $pass, $options + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            echo "OK MySQL version: {$version}\n";
            $stmt = $pdo->query('SELECT * FROM personal_access_tokens WHERE id = 71 LIMIT 1');
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                echo "Row found\n";
            } else {
                echo "No row found\n";
            }
            break; // success
        } catch (Throwable $e) {
            echo "ERR: " . $e->getMessage() . "\n";
            if ($try < $maxTries) sleep(2);
        }
    }
}
