<?php
declare(strict_types=1);

namespace LifeOS;

define('LIFEOS_ROOT', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'LifeOS\\', 7) !== 0) return;
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (is_file($file)) require $file;
});

final class Config
{
    private static ?array $c = null;
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$c === null) {
            $f = LIFEOS_ROOT . '/config.php';
            if (!is_file($f)) throw new HttpError('Server not configured: create config.php', 500);
            self::$c = require $f;
        }
        return self::$c[$key] ?? $default;
    }
}

final class HttpError extends \RuntimeException
{
    public function __construct(string $msg, public int $status = 400) { parent::__construct($msg); }
}

final class DB
{
    private static ?\PDO $pdo = null;
    public static function pdo(): \PDO
    {
        if (self::$pdo) return self::$pdo;
        $c = Config::get('db');
        self::$pdo = new \PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",
            $c['user'], $c['pass'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES => false]
        );
        self::$pdo->exec("SET time_zone = '+06:00'");
        return self::$pdo;
    }
    public static function all(string $sql, array $p = []): array { $s = self::pdo()->prepare($sql); $s->execute($p); return $s->fetchAll(); }
    public static function one(string $sql, array $p = []): ?array { $s = self::pdo()->prepare($sql); $s->execute($p); $r = $s->fetch(); return $r ?: null; }
    public static function val(string $sql, array $p = []): mixed { $s = self::pdo()->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
    public static function run(string $sql, array $p = []): int { $s = self::pdo()->prepare($sql); $s->execute($p); return $s->rowCount(); }
    public static function insert(string $sql, array $p = []): int { self::run($sql, $p); return (int) self::pdo()->lastInsertId(); }
    /** Runs $fn in a transaction; rolls back on any error. Safe to nest (uses savepoints). */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            $level = ++self::$txLevel;
            $pdo->exec("SAVEPOINT sp_$level");
            try { $r = $fn(); $pdo->exec("RELEASE SAVEPOINT sp_$level"); return $r; }
            catch (\Throwable $e) { $pdo->exec("ROLLBACK TO SAVEPOINT sp_$level"); throw $e; }
            finally { self::$txLevel--; }
        }
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    }
    private static int $txLevel = 0;
}

final class Http
{
    public static function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($base !== '' && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
        return '/' . trim($uri, '/');
    }
    public static function rawBody(): string { static $b = null; return $b ??= (string) file_get_contents('php://input'); }
    public static function body(): array
    {
        $d = json_decode(self::rawBody(), true);
        return is_array($d) ? $d : $_POST;
    }
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    public static function ok(mixed $data = null): never { self::json(['ok' => true, 'data' => $data]); }
    public static function cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        try { $allowed = Config::get('cors_origins', []); } catch (\Throwable) { $allowed = []; }
        if ($origin && in_array($origin, $allowed, true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        }
    }
    public static function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
}

date_default_timezone_set('Asia/Dhaka');
