<?php
// app/models/Page.php
// Páginas de um projeto (0 = capa). Espelham o JSON de conteúdo; a geração
// de imagem (fases seguintes) usa o status de cada página.
declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Page
{
    public static function forProject(int $projectId): array
    {
        $st = Database::get()->prepare('SELECT * FROM pages WHERE project_id = :p ORDER BY number');
        $st->execute(['p' => $projectId]);
        return $st->fetchAll();
    }

    /** Cria/atualiza as linhas de pages conforme o conteúdo; remove páginas que deixaram de existir. */
    public static function syncFromContent(int $projectId, array $doc): void
    {
        $pdo = Database::get();
        $wanted = [];
        if (!empty($doc['capa'])) {
            $wanted[0] = 'capa';
        }
        foreach ($doc['paginas'] ?? [] as $p) {
            $wanted[(int) $p['numero']] = (string) ($p['tipo'] ?? 'pagina');
        }
        $up = $pdo->prepare('INSERT INTO pages (project_id, number, kind) VALUES (:p, :n, :k) ON DUPLICATE KEY UPDATE kind = VALUES(kind)');
        foreach ($wanted as $n => $kind) {
            $up->execute(['p' => $projectId, 'n' => $n, 'k' => $kind]);
        }
        $existing = $pdo->prepare('SELECT number FROM pages WHERE project_id = :p');
        $existing->execute(['p' => $projectId]);
        $del = $pdo->prepare('DELETE FROM pages WHERE project_id = :p AND number = :n');
        foreach ($existing->fetchAll(\PDO::FETCH_COLUMN) as $n) {
            if (!isset($wanted[(int) $n])) {
                $del->execute(['p' => $projectId, 'n' => (int) $n]);
            }
        }
    }
}
