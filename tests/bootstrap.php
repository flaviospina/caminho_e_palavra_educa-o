<?php
// tests/bootstrap.php
// Carrega a aplicação (app/bootstrap.php) apontando para .env.test (ou .env) e define os
// auxiliares de teste: Teste::caso(), Teste::igual(), Teste::verdadeiro()...
declare(strict_types=1);

$envTest = dirname(__DIR__) . '/.env.test';
define('ENV_FILE', is_file($envTest) ? $envTest : dirname(__DIR__) . '/.env');

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/helpers.php';

/** Executa um arquivo .sql (vários comandos) no banco de teste. */
function teste_aplicar_sql(string $file): void
{
    App\Database::applySqlFile($file);
}

// Banco de teste sempre com o esquema e os dados iniciais mais recentes.
try {
    teste_aplicar_sql(dirname(__DIR__) . '/sql/schema.sql');
    teste_aplicar_sql(dirname(__DIR__) . '/sql/seeds.sql');
    (new App\Services\SchemaUpdater())->apply();
} catch (Throwable $e) {
    fwrite(STDERR, "Não foi possível preparar o banco de teste: " . $e->getMessage() . "\nConfira .env.test (DB_*).\n");
    exit(1);
}

/** Simula o login de um usuário nos testes (sessão em array). */
function teste_logar(?int $userId): void
{
    App\Session::start();
    $_SESSION = [];
    App\Auth::refresh();
    if ($userId !== null) {
        $_SESSION[App\Auth::SESSION_KEY] = $userId;
    }
}

final class FalhaDeTeste extends \RuntimeException
{
}

final class Teste
{
    public static int $total = 0;
    public static int $falhas = 0;
    /** @var string[] */
    public static array $nomesFalhos = [];

    /** Executa um caso de teste e imprime OK/FALHOU. */
    public static function caso(string $nome, callable $fn): void
    {
        self::$total++;
        $inicio = microtime(true);
        try {
            $fn();
            printf("  OK      %s (%.0f ms)\n", $nome, (microtime(true) - $inicio) * 1000);
        } catch (\Throwable $e) {
            self::$falhas++;
            self::$nomesFalhos[] = $nome;
            printf("  FALHOU  %s\n          %s\n", $nome, $e->getMessage());
            if (!$e instanceof FalhaDeTeste) {
                printf("          em %s:%d\n", $e->getFile(), $e->getLine());
            }
        }
    }

    public static function verdadeiro(mixed $valor, string $msg = 'Esperava verdadeiro'): void
    {
        if ($valor !== true) {
            throw new FalhaDeTeste($msg . ' — recebido: ' . var_export($valor, true));
        }
    }

    public static function falso(mixed $valor, string $msg = 'Esperava falso'): void
    {
        if ($valor !== false) {
            throw new FalhaDeTeste($msg . ' — recebido: ' . var_export($valor, true));
        }
    }

    public static function igual(mixed $esperado, mixed $recebido, string $msg = 'Valores diferentes'): void
    {
        if ($esperado !== $recebido) {
            throw new FalhaDeTeste(sprintf('%s — esperado %s, recebido %s', $msg, var_export($esperado, true), var_export($recebido, true)));
        }
    }

    public static function contem(string $trecho, string $texto, string $msg = 'Trecho não encontrado'): void
    {
        if (!str_contains($texto, $trecho)) {
            throw new FalhaDeTeste($msg . ' — procurado: ' . var_export($trecho, true));
        }
    }

    public static function naoContem(string $trecho, string $texto, string $msg = 'Trecho proibido encontrado'): void
    {
        if (str_contains($texto, $trecho)) {
            throw new FalhaDeTeste($msg . ' — encontrado: ' . var_export($trecho, true));
        }
    }

    /** Garante que a função lança exceção (opcionalmente de uma classe). */
    public static function lanca(callable $fn, string $classe = \Throwable::class, string $msg = 'Esperava exceção'): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $classe) {
                throw new FalhaDeTeste($msg . ' — classe recebida: ' . get_class($e));
            }
            return $e;
        }
        throw new FalhaDeTeste($msg . ' — nenhuma exceção lançada');
    }
}

/** Cria (ou reaproveita) um usuário de teste com o perfil indicado; devolve o id. */
function teste_criar_usuario(string $role = 'professor', string $email = '', string $senha = 'Senha123', bool $mustChange = false): int
{
    $email = $email !== '' ? $email : "teste.{$role}@exemplo.com";
    $existente = App\Models\User::findByEmail($email);
    if ($existente !== null) {
        App\Models\User::updatePassword((int) $existente['id'], $senha, $mustChange);
        App\Database::get()->prepare('UPDATE users SET active = 1, anonymized_at = NULL WHERE id = :id')->execute(['id' => $existente['id']]);
        return (int) $existente['id'];
    }
    $roleId = App\Models\Role::idBySlug($role);
    return App\Models\User::create([
        'name' => 'Teste ' . ucfirst($role), 'email' => $email, 'role_id' => $roleId,
        'school_id' => $role === 'admin' ? null : 1, 'active' => 1,
    ], $senha, $mustChange);
}

/** Registra o aceite do termo vigente para o usuário (sem passar pelo formulário). */
function teste_aceitar_termo(int $userId): void
{
    App\Models\Consent::record($userId, '127.0.0.1', 'teste');
}

/** Garante a senha provisória do admin inicial (id 1) como no seeds.sql, sem obrigar troca. */
function teste_preparar_admin(): void
{
    App\Models\User::updatePassword(1, 'Mudar@2026', false);
    App\Database::get()->exec('UPDATE users SET active = 1, failed_attempts = 0, locked_until = NULL WHERE id = 1');
}
