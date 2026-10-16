<?php
// app/models/School.php
declare(strict_types=1);

namespace App\Models;

use App\Database;
use InvalidArgumentException;

final class School
{
    public static function find(int $id): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM schools WHERE id = :id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array> */
    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT s.*, (SELECT COUNT(*) FROM users u WHERE u.school_id = s.id AND u.anonymized_at IS NULL) AS users_count FROM schools s';
        if ($activeOnly) {
            $sql .= ' WHERE s.active = 1';
        }
        return Database::get()->query($sql . ' ORDER BY s.name')->fetchAll();
    }

    /** Valida e normaliza os campos do formulário. @throws InvalidArgumentException */
    public static function validate(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Informe o nome da escola (3 a 150 caracteres).');
        }
        $inep = preg_replace('/\D/', '', (string) ($in['inep_code'] ?? '')) ?? '';
        if ($inep !== '' && strlen($inep) !== 8) {
            throw new InvalidArgumentException('O código INEP tem 8 dígitos.');
        }
        $cor = strtoupper(trim((string) ($in['primary_color'] ?? '')));
        if ($cor !== '' && !preg_match('/^#[0-9A-F]{6}$/', $cor)) {
            throw new InvalidArgumentException('A cor principal deve estar no formato #RRGGBB.');
        }
        return [
            'name'          => $name,
            'short_name'    => mb_substr(trim((string) ($in['short_name'] ?? '')), 0, 60) ?: null,
            'inep_code'     => $inep ?: null,
            'city'          => mb_substr(trim((string) ($in['city'] ?? 'São Caetano do Sul')), 0, 80) ?: 'São Caetano do Sul',
            'active'        => !empty($in['active']) ? 1 : 0,
            'primary_color' => $cor ?: null,
            'tagline'       => mb_substr(trim((string) ($in['tagline'] ?? '')), 0, 120) ?: null,
        ];
    }

    public static function create(array $data): int
    {
        $pdo = Database::get();
        $st = $pdo->prepare('INSERT INTO schools (name, short_name, inep_code, city, active, primary_color, tagline) VALUES (:name, :short_name, :inep_code, :city, :active, :primary_color, :tagline)');
        $st->execute($data);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $st = Database::get()->prepare('UPDATE schools SET name = :name, short_name = :short_name, inep_code = :inep_code, city = :city, active = :active, primary_color = :primary_color, tagline = :tagline WHERE id = :id');
        $st->execute($data + ['id' => $id]);
    }

    public static function setLogo(int $id, ?string $path): void
    {
        Database::get()->prepare('UPDATE schools SET logo_path = :p WHERE id = :id')->execute(['p' => $path, 'id' => $id]);
    }

    public static function nameExists(string $name, ?int $exceptId = null): bool
    {
        $st = Database::get()->prepare('SELECT id FROM schools WHERE name = :n AND id <> :e');
        $st->execute(['n' => $name, 'e' => $exceptId ?? 0]);
        return $st->fetchColumn() !== false;
    }
}
