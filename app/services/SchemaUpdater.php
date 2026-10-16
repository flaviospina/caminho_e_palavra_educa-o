<?php
// app/services/SchemaUpdater.php
// Atualizações de estrutura idempotentes e compatíveis com MySQL 5.7 (que não
// tem ADD COLUMN IF NOT EXISTS): verifica information_schema e aplica só o que
// falta. Usada pelos testes, por tools/migrate.php e pelo admin.
declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

final class SchemaUpdater
{
    /** Colunas acrescentadas depois do schema inicial: [tabela, coluna, definição SQL]. */
    public const COLUMNS = [
        ['schools', 'logo_path', "VARCHAR(255) NULL AFTER inep_code"],
        ['schools', 'primary_color', "CHAR(7) NULL AFTER logo_path"],
        ['schools', 'tagline', "VARCHAR(120) NULL AFTER primary_color"],
        ['pages', 'format', "VARCHAR(10) NULL AFTER kind"],
    ];

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::get();
    }

    public function columnExists(string $table, string $column): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        $st->execute(['t' => $table, 'c' => $column]);
        return (int) $st->fetchColumn() > 0;
    }

    public function tableExists(string $table): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
        $st->execute(['t' => $table]);
        return (int) $st->fetchColumn() > 0;
    }

    /** @return array<int,array{tabela:string,coluna:string}> o que ainda falta */
    public function pending(): array
    {
        $out = [];
        foreach (self::COLUMNS as [$t, $c]) {
            if ($this->tableExists($t) && !$this->columnExists($t, $c)) {
                $out[] = ['tabela' => $t, 'coluna' => $c];
            }
        }
        return $out;
    }

    /** Aplica o que falta. @return string[] descrições do que foi aplicado */
    public function apply(): array
    {
        $done = [];
        foreach (self::COLUMNS as [$t, $c, $def]) {
            if ($this->tableExists($t) && !$this->columnExists($t, $c)) {
                $this->pdo->exec("ALTER TABLE `{$t}` ADD COLUMN `{$c}` {$def}");
                $done[] = "{$t}.{$c}";
            }
        }
        return $done;
    }
}
