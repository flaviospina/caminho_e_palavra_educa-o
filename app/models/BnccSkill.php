<?php
// app/models/BnccSkill.php
// Consulta e validação das habilidades BNCC (tabela bncc_skills).
declare(strict_types=1);

namespace App\Models;

use App\Database;

final class BnccSkill
{
    public const STAGES = [
        'EI'               => 'Educação Infantil',
        'EF_ANOS_INICIAIS' => 'Ensino Fundamental – Anos Iniciais',
        'EF_ANOS_FINAIS'   => 'Ensino Fundamental – Anos Finais',
        'EM'               => 'Ensino Médio',
    ];

    /** Rótulos dos "anos" da Educação Infantil (grupos etários). */
    public const EI_GROUPS = [
        'bebes'                 => 'Bebês (0 a 1 ano e 6 meses)',
        'criancas_bem_pequenas' => 'Crianças bem pequenas (1 ano e 7 meses a 3 anos e 11 meses)',
        'criancas_pequenas'     => 'Crianças pequenas (4 anos a 5 anos e 11 meses)',
    ];

    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    public static function find(string $code): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM bncc_skills WHERE code = :c');
        $st->execute(['c' => self::normalizeCode($code)]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function exists(string $code): bool
    {
        return self::find($code) !== null;
    }

    public static function count(): int
    {
        return (int) Database::get()->query('SELECT COUNT(*) FROM bncc_skills')->fetchColumn();
    }

    /** @return array<int,array{stage:string,source:string,total:int}> */
    public static function countsByStage(): array
    {
        return Database::get()->query('SELECT stage, source, COUNT(*) AS total FROM bncc_skills GROUP BY stage, source ORDER BY FIELD(stage, "EI", "EF_ANOS_INICIAIS", "EF_ANOS_FINAIS", "EM"), source')->fetchAll();
    }

    /** Componentes disponíveis em uma etapa (e opcionalmente em um ano). @return string[] */
    public static function components(string $stage, ?string $year = null): array
    {
        $sql = 'SELECT DISTINCT component FROM bncc_skills WHERE stage = :s';
        $params = ['s' => $stage];
        if ($year !== null && $year !== '') {
            $sql .= ' AND FIND_IN_SET(:y, years) > 0';
            $params['y'] = $year;
        }
        $st = Database::get()->prepare($sql . ' ORDER BY component');
        $st->execute($params);
        return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Habilidades de um recorte etapa/ano/componente (para o assistente). */
    public static function search(string $stage, ?string $year, ?string $component, string $q = '', int $limit = 200): array
    {
        $sql = 'SELECT code, years, component, thematic_unit, knowledge_object, description FROM bncc_skills WHERE stage = :s';
        $params = ['s' => $stage];
        if ($year !== null && $year !== '') {
            $sql .= ' AND FIND_IN_SET(:y, years) > 0';
            $params['y'] = $year;
        }
        if ($component !== null && $component !== '') {
            $sql .= ' AND component = :c';
            $params['c'] = $component;
        }
        if ($q !== '') {
            $sql .= ' AND (code LIKE :q1 OR description LIKE :q2 OR knowledge_object LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . $q . '%';
        }
        $st = Database::get()->prepare($sql . ' ORDER BY code LIMIT ' . max(1, min(500, $limit)));
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * Valida se o código existe e pertence à etapa/ano/componente escolhidos.
     * @return array{ok:bool, motivo:string, skill:?array}
     */
    public static function validateFor(string $code, string $stage, string $year, string $component): array
    {
        $skill = self::find($code);
        $norm = self::normalizeCode($code);
        if ($skill === null) {
            return ['ok' => false, 'motivo' => "O código {$norm} não existe na base BNCC oficial.", 'skill' => null];
        }
        if ($skill['stage'] !== $stage) {
            return ['ok' => false, 'motivo' => "A habilidade {$norm} é da etapa " . (self::STAGES[$skill['stage']] ?? $skill['stage']) . ', não de ' . (self::STAGES[$stage] ?? $stage) . '.', 'skill' => $skill];
        }
        $years = array_map('trim', explode(',', (string) $skill['years']));
        if ($year !== '' && !in_array($year, $years, true)) {
            return ['ok' => false, 'motivo' => "A habilidade {$norm} não é do " . self::yearLabel($stage, $year) . ' (vale para: ' . self::yearsLabel($stage, $years) . ').', 'skill' => $skill];
        }
        if ($component !== '' && mb_strtolower($skill['component']) !== mb_strtolower($component)) {
            return ['ok' => false, 'motivo' => "A habilidade {$norm} é de {$skill['component']}, não de {$component}.", 'skill' => $skill];
        }
        return ['ok' => true, 'motivo' => '', 'skill' => $skill];
    }

    public static function yearLabel(string $stage, string $year): string
    {
        if ($stage === 'EI') {
            return self::EI_GROUPS[$year] ?? $year;
        }
        if ($stage === 'EM') {
            return $year . 'ª série';
        }
        return $year . 'º ano';
    }

    /** @param string[] $years */
    public static function yearsLabel(string $stage, array $years): string
    {
        return implode(', ', array_map(fn($y) => self::yearLabel($stage, $y), $years));
    }
}
