<?php
// app/Database.php
// Conexão única (singleton) com o MySQL via PDO.
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    /** Devolve a conexão compartilhada, criando-a na primeira chamada. */
    public static function get(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect([
                'host' => DB_HOST,
                'port' => DB_PORT,
                'name' => DB_NAME,
                'user' => DB_USER,
                'pass' => DB_PASS,
            ]);
        }
        return self::$pdo;
    }

    /**
     * Abre uma conexão nova com os parâmetros informados.
     * Usado pelo singleton e pelos testes (ex.: banco de teste, banco "desligado").
     *
     * @param array{host?:string,port?:int|string,name?:string,user?:string,pass?:string,timeout?:int} $p
     * @throws PDOException quando o banco está fora do ar ou as credenciais estão erradas.
     */
    public static function connect(array $p): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $p['host'] ?? '127.0.0.1',
            (int) ($p['port'] ?? 3306),
            $p['name'] ?? ''
        );
        $pdo = new PDO($dsn, $p['user'] ?? '', $p['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => (int) ($p['timeout'] ?? 5),
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
        // O servidor MySQL pode estar em outro fuso (UTC no HostGator); alinha NOW() ao PHP.
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        return $pdo;
    }

    /**
     * Divide um arquivo .sql em comandos, respeitando aspas e comentários "--".
     * Usado pelos testes e pela importação do esquema.
     * @return string[]
     */
    public static function splitSql(string $sql): array
    {
        $stmts = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($quote === null) {
                if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {           // comentário até o fim da linha
                    while ($i < $len && $sql[$i] !== "\n") {
                        $i++;
                    }
                    continue;
                }
                if ($ch === '#') {
                    while ($i < $len && $sql[$i] !== "\n") {
                        $i++;
                    }
                    continue;
                }
                if ($ch === "'" || $ch === '"' || $ch === '`') {
                    $quote = $ch;
                } elseif ($ch === ';') {
                    $stmt = trim($buf);
                    if ($stmt !== '') {
                        $stmts[] = $stmt;
                    }
                    $buf = '';
                    continue;
                }
            } else {
                if ($ch === '\\') {                                           // escape dentro da string
                    $buf .= $ch . ($sql[$i + 1] ?? '');
                    $i++;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
            }
            $buf .= $ch;
        }
        $stmt = trim($buf);
        if ($stmt !== '') {
            $stmts[] = $stmt;
        }
        return $stmts;
    }

    /** Executa todos os comandos de um arquivo .sql na conexão informada (ou na compartilhada). */
    public static function applySqlFile(string $file, ?PDO $pdo = null): int
    {
        $sql = @file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException('Arquivo SQL não encontrado: ' . $file);
        }
        $pdo ??= self::get();
        $n = 0;
        foreach (self::splitSql($sql) as $stmt) {
            $pdo->exec($stmt);
            $n++;
        }
        return $n;
    }

    /** Descarta a conexão compartilhada (usado em testes). */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
