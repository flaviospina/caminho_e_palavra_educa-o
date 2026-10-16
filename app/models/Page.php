<?php
// app/models/Page.php
// Páginas de um projeto (0 = capa). Espelham o JSON de conteúdo; a geração
// de imagem (fases seguintes) usa o status de cada página.
declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Page
{
    public const STATUS = ['pendente' => 'Na fila', 'gerando' => 'Gerando', 'conferindo' => 'Conferindo', 'pronto' => 'Pronta', 'erro' => 'Erro', 'revisar' => 'Revisar manualmente'];

    public static function find(int $id): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM pages WHERE id = :id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function findByNumber(int $projectId, int $number): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM pages WHERE project_id = :p AND number = :n');
        $st->execute(['p' => $projectId, 'n' => $number]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::get()->prepare('UPDATE pages SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $id]);
    }

    /** Grava o resultado de uma geração (caminho relativo a storage/, prompt, conferência, tentativas). */
    public static function setResult(int $id, string $status, ?string $imagePath, ?string $prompt, ?array $check, int $attempts): void
    {
        Database::get()->prepare('UPDATE pages SET status = :s, image_path = :i, prompt_text = :p, check_result = :c, attempts = :a WHERE id = :id')->execute([
            's' => $status, 'i' => $imagePath, 'p' => $prompt, 'c' => $check === null ? null : json_encode($check, JSON_UNESCAPED_UNICODE), 'a' => $attempts, 'id' => $id,
        ]);
    }

    /** Caminho absoluto da imagem da página, se existir. */
    public static function imageFile(array $page): ?string
    {
        if (empty($page['image_path'])) {
            return null;
        }
        $file = STORAGE_PATH . '/' . ltrim((string) $page['image_path'], '/');
        return is_file($file) ? $file : null;
    }

    public static function forProject(int $projectId): array
    {
        $st = Database::get()->prepare('SELECT * FROM pages WHERE project_id = :p ORDER BY number');
        $st->execute(['p' => $projectId]);
        return $st->fetchAll();
    }

    /** Cria/atualiza as linhas de pages conforme o conteúdo; remove páginas que deixaram de existir. */
    public static function syncFromContent(int $projectId, array $doc): void
    {
        if (($doc['tipo'] ?? '') === 'cartaz') {
            return;   // páginas de cartaz são criadas por formato (PosterService)
        }
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
