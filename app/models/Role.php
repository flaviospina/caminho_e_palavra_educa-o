<?php
// app/models/Role.php
declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Role
{
    public const PROFESSOR = 'professor';
    public const COORDENADOR = 'coordenador';
    public const ADMIN = 'admin';

    /** @return array<int,array> */
    public static function all(): array
    {
        return Database::get()->query('SELECT * FROM roles ORDER BY id')->fetchAll();
    }

    public static function idBySlug(string $slug): ?int
    {
        $st = Database::get()->prepare('SELECT id FROM roles WHERE slug = :s');
        $st->execute(['s' => $slug]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
