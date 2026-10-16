<?php
// app/models/User.php
// Usuários (professor, coordenador, admin). Senhas sempre com password_hash().
declare(strict_types=1);

namespace App\Models;

use App\Database;
use InvalidArgumentException;

final class User
{
    public const MIN_PASSWORD = 8;

    private const SELECT = 'SELECT u.*, r.slug AS role_slug, r.name AS role_name, s.name AS school_name, s.short_name AS school_short_name
        FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN schools s ON s.id = u.school_id';

    public static function find(int $id): ?array
    {
        $st = Database::get()->prepare(self::SELECT . ' WHERE u.id = :id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function findByEmail(string $email): ?array
    {
        $st = Database::get()->prepare(self::SELECT . ' WHERE u.email = :e');
        $st->execute(['e' => mb_strtolower(trim($email))]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Lista paginada com filtros (q, role, school_id, active).
     * @return array{itens:array,total:int,pagina:int,por_pagina:int,paginas:int}
     */
    public static function paginate(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['u.anonymized_at IS NULL'];
        $params = [];
        if (!empty($filters['q'])) {
            $where[] = '(u.name LIKE :q1 OR u.email LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['role'])) {
            $where[] = 'r.slug = :role';
            $params['role'] = $filters['role'];
        }
        if (!empty($filters['school_id'])) {
            $where[] = 'u.school_id = :school';
            $params['school'] = (int) $filters['school_id'];
        }
        if (isset($filters['active']) && $filters['active'] !== '') {
            $where[] = 'u.active = :active';
            $params['active'] = (int) $filters['active'];
        }
        $w = ' WHERE ' . implode(' AND ', $where);
        $pdo = Database::get();
        $st = $pdo->prepare('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id' . $w);
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $st = $pdo->prepare(self::SELECT . $w . ' ORDER BY u.name LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $st->execute($params);
        return ['itens' => $st->fetchAll(), 'total' => $total, 'pagina' => $page, 'por_pagina' => $perPage, 'paginas' => (int) max(1, ceil($total / $perPage))];
    }

    /** Valida os campos do formulário de usuário (sem senha). @throws InvalidArgumentException */
    public static function validate(array $in, ?int $exceptId = null): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Informe o nome completo (3 a 120 caracteres).');
        }
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            throw new InvalidArgumentException('Informe um e-mail válido.');
        }
        if (self::emailExists($email, $exceptId)) {
            throw new InvalidArgumentException('Já existe um usuário com este e-mail.');
        }
        $roleSlug = (string) ($in['role'] ?? '');
        $roleId = Role::idBySlug($roleSlug);
        if ($roleId === null) {
            throw new InvalidArgumentException('Escolha um perfil válido.');
        }
        $schoolId = (int) ($in['school_id'] ?? 0) ?: null;
        if ($roleSlug !== Role::ADMIN && $schoolId === null) {
            throw new InvalidArgumentException('Professores e coordenadores precisam estar vinculados a uma escola.');
        }
        if ($schoolId !== null && School::find($schoolId) === null) {
            throw new InvalidArgumentException('Escola inexistente.');
        }
        return ['name' => $name, 'email' => $email, 'role_id' => $roleId, 'school_id' => $schoolId, 'active' => !empty($in['active']) ? 1 : 0];
    }

    /** @throws InvalidArgumentException */
    public static function validatePassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException('A senha precisa ter pelo menos ' . self::MIN_PASSWORD . ' caracteres.');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new InvalidArgumentException('A senha precisa ter letras e números.');
        }
    }

    public static function create(array $data, string $password, bool $mustChange = true): int
    {
        $pdo = Database::get();
        $st = $pdo->prepare('INSERT INTO users (school_id, role_id, name, email, password_hash, active, must_change_pass)
            VALUES (:school_id, :role_id, :name, :email, :hash, :active, :must)');
        $st->execute([
            'school_id' => $data['school_id'], 'role_id' => $data['role_id'], 'name' => $data['name'], 'email' => $data['email'],
            'hash' => password_hash($password, PASSWORD_DEFAULT), 'active' => $data['active'], 'must' => $mustChange ? 1 : 0,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $st = Database::get()->prepare('UPDATE users SET school_id = :school_id, role_id = :role_id, name = :name, email = :email, active = :active WHERE id = :id');
        $st->execute($data + ['id' => $id]);
    }

    public static function updatePassword(int $id, string $password, bool $mustChange = false): void
    {
        $st = Database::get()->prepare('UPDATE users SET password_hash = :h, must_change_pass = :m, failed_attempts = 0, locked_until = NULL WHERE id = :id');
        $st->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'm' => $mustChange ? 1 : 0, 'id' => $id]);
    }

    public static function registerFailure(int $id, int $attempts, ?int $lockMinutes): void
    {
        $st = Database::get()->prepare('UPDATE users SET failed_attempts = :a, locked_until = :l WHERE id = :id');
        $st->execute(['a' => $attempts, 'l' => $lockMinutes === null ? null : date('Y-m-d H:i:s', time() + $lockMinutes * 60), 'id' => $id]);
    }

    public static function registerLogin(int $id, string $ip): void
    {
        $st = Database::get()->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = :ip WHERE id = :id');
        $st->execute(['ip' => mb_substr($ip, 0, 45) ?: null, 'id' => $id]);
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        $st = Database::get()->prepare('SELECT id FROM users WHERE email = :e AND id <> :x');
        $st->execute(['e' => mb_strtolower(trim($email)), 'x' => $exceptId ?? 0]);
        return $st->fetchColumn() !== false;
    }

    /** Senha provisória legível (ex.: "Aula-7Kp3Qw"). */
    public static function temporaryPassword(): string
    {
        $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $s = '';
        for ($i = 0; $i < 8; $i++) {
            $s .= $alpha[random_int(0, strlen($alpha) - 1)];
        }
        return 'Aula-' . $s;
    }
}
