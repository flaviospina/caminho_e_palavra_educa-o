<?php
// app/models/Project.php
// Projetos (apostilas, cartazes, planos). Guarda a versão gerada pela IA
// (content_json) e a revisada pelo professor (reviewed_json) separadamente.
declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Project
{
    public const STATUS = ['rascunho' => 'Rascunho', 'revisado' => 'Revisado', 'gerando' => 'Gerando imagens', 'pronto' => 'Pronto', 'erro' => 'Erro'];

    private const SELECT = 'SELECT p.*, u.name AS user_name, s.name AS school_name FROM projects p JOIN users u ON u.id = p.user_id LEFT JOIN schools s ON s.id = p.school_id';

    public static function find(int $id): ?array
    {
        $st = Database::get()->prepare(self::SELECT . ' WHERE p.id = :id AND p.deleted_at IS NULL');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Conteúdo vigente: o revisado, se existir, senão o gerado. */
    public static function currentContent(array $project): array
    {
        $json = $project['reviewed_json'] ?: $project['content_json'];
        $doc = json_decode((string) $json, true);
        return is_array($doc) ? $doc : [];
    }

    public static function generatedContent(array $project): array
    {
        $doc = json_decode((string) $project['content_json'], true);
        return is_array($doc) ? $doc : [];
    }

    /** O usuário pode ver/editar este projeto? Professor: só os seus; coordenador: escola; admin: todos. */
    public static function canAccess(array $project, array $user): bool
    {
        $role = (string) $user['role_slug'];
        if ($role === 'admin') {
            return true;
        }
        if ((int) $project['user_id'] === (int) $user['id']) {
            return true;
        }
        if ($role === 'coordenador' && $user['school_id'] !== null && (int) $project['school_id'] === (int) $user['school_id']) {
            return true;
        }
        return false;
    }

    /** Só o autor (ou admin) edita o conteúdo; coordenador só visualiza. */
    public static function canEdit(array $project, array $user): bool
    {
        return (string) $user['role_slug'] === 'admin' || (int) $project['user_id'] === (int) $user['id'];
    }

    /**
     * Lista visível ao usuário, com filtros (q, componente, ano, habilidade, status) e paginação.
     * @return array{itens:array,total:int,pagina:int,por_pagina:int,paginas:int}
     */
    public static function paginateFor(array $user, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        $role = (string) $user['role_slug'];
        if ($role === 'professor') {
            $where[] = 'p.user_id = :uid';
            $params['uid'] = (int) $user['id'];
        } elseif ($role === 'coordenador') {
            $where[] = '(p.user_id = :uid OR p.school_id = :sid)';
            $params['uid'] = (int) $user['id'];
            $params['sid'] = (int) ($user['school_id'] ?? 0);
        }
        if (!empty($filters['q'])) {
            $where[] = '(p.title LIKE :q1 OR p.theme LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['componente'])) {
            $where[] = 'p.component = :comp';
            $params['comp'] = $filters['componente'];
        }
        if (!empty($filters['ano'])) {
            $where[] = 'p.year = :ano';
            $params['ano'] = $filters['ano'];
        }
        if (!empty($filters['habilidade'])) {
            $where[] = 'FIND_IN_SET(:hab, p.skill_codes) > 0';
            $params['hab'] = BnccSkill::normalizeCode((string) $filters['habilidade']);
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = :status';
            $params['status'] = $filters['status'];
        }
        $w = ' WHERE ' . implode(' AND ', $where);
        $pdo = Database::get();
        $st = $pdo->prepare('SELECT COUNT(*) FROM projects p' . $w);
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $page = max(1, $page);
        $st = $pdo->prepare(self::SELECT . $w . ' ORDER BY p.updated_at DESC, p.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $st->execute($params);
        return ['itens' => $st->fetchAll(), 'total' => $total, 'pagina' => $page, 'por_pagina' => $perPage, 'paginas' => (int) max(1, ceil($total / $perPage))];
    }

    /** Cria o projeto com o conteúdo gerado e sincroniza as páginas. */
    public static function create(array $user, array $doc, array $meta = []): int
    {
        $pdo = Database::get();
        $codes = implode(',', array_map(fn($h) => BnccSkill::normalizeCode((string) $h['codigo']), $doc['habilidades']));
        $st = $pdo->prepare('INSERT INTO projects (user_id, school_id, type, title, stage, year, component, skill_codes, theme, purpose, style_slug, output_mode, status, content_json)
            VALUES (:user_id, :school_id, :type, :title, :stage, :year, :component, :skill_codes, :theme, :purpose, :style_slug, :output_mode, :status, :content_json)');
        $st->execute([
            'user_id'     => (int) $user['id'],
            'school_id'   => $user['school_id'] !== null ? (int) $user['school_id'] : null,
            'type'        => $doc['tipo'],
            'title'       => mb_substr((string) ($doc['capa']['titulo'] ?? $doc['tema']), 0, 191),
            'stage'       => $doc['etapa'],
            'year'        => (string) $doc['ano'],
            'component'   => $doc['componente'],
            'skill_codes' => mb_substr($codes, 0, 255),
            'theme'       => mb_substr((string) $doc['tema'], 0, 191),
            'purpose'     => $doc['finalidade'] ?? null,
            'style_slug'  => $doc['estilo'],
            'output_mode' => $meta['output_mode'] ?? 'imagem',
            'status'      => 'rascunho',
            'content_json' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $id = (int) $pdo->lastInsertId();
        Page::syncFromContent($id, $doc);
        return $id;
    }

    /** Grava a versão revisada pelo professor (a gerada fica intacta). */
    public static function saveReview(int $id, array $doc): void
    {
        $codes = implode(',', array_map(fn($h) => BnccSkill::normalizeCode((string) $h['codigo']), $doc['habilidades']));
        $st = Database::get()->prepare('UPDATE projects SET reviewed_json = :j, reviewed_at = NOW(), status = :s, title = :t, theme = :theme, skill_codes = :codes WHERE id = :id');
        $st->execute([
            'j' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 's' => 'revisado',
            't' => mb_substr((string) ($doc['capa']['titulo'] ?? $doc['tema']), 0, 191), 'theme' => mb_substr((string) $doc['tema'], 0, 191),
            'codes' => mb_substr($codes, 0, 255), 'id' => $id,
        ]);
        Page::syncFromContent($id, $doc);
    }

    /** Descarta a revisão e volta à versão gerada. */
    public static function revertReview(int $id): void
    {
        Database::get()->prepare("UPDATE projects SET reviewed_json = NULL, reviewed_at = NULL, status = 'rascunho' WHERE id = :id")->execute(['id' => $id]);
        $p = self::find($id);
        if ($p !== null) {
            Page::syncFromContent($id, self::generatedContent($p));
        }
    }

    /** Renomeia o projeto na lista (não altera o conteúdo JSON nem a arte). */
    public static function rename(int $id, string $title): void
    {
        Database::get()->prepare('UPDATE projects SET title = :t WHERE id = :id')->execute(['t' => mb_substr($title, 0, 191), 'id' => $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::get()->prepare('UPDATE projects SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $id]);
    }

    /** Recalcula o status do projeto a partir das páginas (gerando → pronto/erro). */
    public static function refreshStatusFromPages(int $id): string
    {
        $pages = Page::forProject($id);
        $status = 'pronto';
        foreach ($pages as $p) {
            if (in_array($p['status'], ['pendente', 'gerando', 'conferindo'], true)) {
                $status = 'gerando';
                break;
            }
            if ($p['status'] === 'erro') {
                $status = 'erro';
            }
        }
        if ($pages === []) {
            $status = 'revisado';
        }
        self::setStatus($id, $status);
        return $status;
    }

    public static function softDelete(int $id): void
    {
        Database::get()->prepare('UPDATE projects SET deleted_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    }

    /** Componentes e anos existentes nos projetos visíveis (para os filtros). */
    public static function filterOptions(array $user): array
    {
        $role = (string) $user['role_slug'];
        $sql = 'SELECT DISTINCT component, year FROM projects p WHERE deleted_at IS NULL';
        $params = [];
        if ($role === 'professor') {
            $sql .= ' AND user_id = :uid';
            $params['uid'] = (int) $user['id'];
        } elseif ($role === 'coordenador') {
            $sql .= ' AND (user_id = :uid OR school_id = :sid)';
            $params['uid'] = (int) $user['id'];
            $params['sid'] = (int) ($user['school_id'] ?? 0);
        }
        $st = Database::get()->prepare($sql . ' ORDER BY component, year');
        $st->execute($params);
        $comp = [];
        $anos = [];
        foreach ($st->fetchAll() as $r) {
            $comp[$r['component']] = true;
            $anos[$r['year']] = true;
        }
        return ['componentes' => array_keys($comp), 'anos' => array_keys($anos)];
    }
}
