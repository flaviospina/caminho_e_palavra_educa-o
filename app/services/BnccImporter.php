<?php
// app/services/BnccImporter.php
// Importa a base BNCC do dataset aberto bncc-dados (data/bncc/) para a tabela
// bncc_skills. Idempotente: INSERT … ON DUPLICATE KEY UPDATE por código.
declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;
use RuntimeException;

final class BnccImporter
{
    private const EI_GROUPS = ['ei-grupo-01' => 'bebes', 'ei-grupo-02' => 'criancas_bem_pequenas', 'ei-grupo-03' => 'criancas_pequenas'];

    private array $names = [];      // id → nome (componentes, áreas, campos, contextos, eixos, objetos)
    private array $components = []; // id → ['nome', 'area']

    public function __construct(private ?string $dataDir = null, private ?PDO $pdo = null)
    {
        $this->dataDir ??= ROOT_PATH . '/data/bncc';
        $this->pdo ??= Database::get();
    }

    /** Executa a importação completa. @return array{total:int, por_arquivo:array<string,int>, inseridos:int, atualizados:int, segundos:float} */
    public function run(): array
    {
        $start = microtime(true);
        $this->loadStructure();
        $rows = [];
        $porArquivo = [];

        $ei = $this->read('bncc-2018/educacao-infantil.json');
        foreach ($ei['objetivos'] ?? [] as $o) {
            $rows[] = $this->eiRow($o, 'bncc-dados');
        }
        $porArquivo['educacao-infantil'] = count($ei['objetivos'] ?? []);

        $ef = $this->read('bncc-2018/ensino-fundamental.json');
        $this->indexContexts($ef['contextos_organizacao'] ?? []);
        foreach ($ef['habilidades'] ?? [] as $h) {
            $rows[] = $this->efRow($h, 'bncc-dados');
        }
        $porArquivo['ensino-fundamental'] = count($ef['habilidades'] ?? []);

        $em = $this->read('bncc-2018/ensino-medio.json');
        $this->indexContexts($em['contextos_organizacao'] ?? []);
        foreach ($em['habilidades'] ?? [] as $h) {
            $rows[] = $this->emRow($h, 'bncc-dados');
        }
        $porArquivo['ensino-medio'] = count($em['habilidades'] ?? []);

        $compFile = $this->dataDir . '/computacao-2022/computacao.json';
        if (is_file($compFile)) {
            $c = $this->read('computacao-2022/computacao.json');
            foreach ($c['eixos'] ?? [] as $e) {
                $this->names[$e['id']] = $e['nome'] ?? $e['id'];
            }
            foreach ($c['objetos_conhecimento'] ?? [] as $o) {
                $this->names[$o['id']] = $o['nome'] ?? $o['id'];
            }
            $n = 0;
            foreach ($c['objetivos_ei'] ?? [] as $o) {
                $o['campo_experiencias'] = null;
                $rows[] = $this->eiRow($o, 'computacao-2022', 'Computação', $this->names[$o['eixo'] ?? ''] ?? null);
                $n++;
            }
            foreach ($c['habilidades_ef'] ?? [] as $h) {
                $rows[] = $this->compEfRow($h);
                $n++;
            }
            foreach ($c['habilidades_em'] ?? [] as $h) {
                $rows[] = $this->compEmRow($h);
                $n++;
            }
            $porArquivo['computacao'] = $n;
        }

        [$inseridos, $atualizados] = $this->upsert($rows);
        return [
            'total'       => count($rows),
            'por_arquivo' => $porArquivo,
            'inseridos'   => $inseridos,
            'atualizados' => $atualizados,
            'segundos'    => round(microtime(true) - $start, 2),
        ];
    }

    private function read(string $rel): array
    {
        $file = $this->dataDir . '/' . $rel;
        $json = @file_get_contents($file);
        if ($json === false) {
            throw new RuntimeException("Arquivo da base BNCC não encontrado: {$rel}. Confira a pasta data/bncc/.");
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException("Arquivo da base BNCC inválido: {$rel}.");
        }
        return $data;
    }

    private function loadStructure(): void
    {
        $e = $this->read('bncc-2018/estrutura.json');
        foreach ($e['areas_conhecimento'] ?? [] as $a) {
            $this->names[$a['id']] = $a['nome'];
        }
        foreach ($e['componentes_curriculares'] ?? [] as $c) {
            $this->names[$c['id']] = $c['nome'];
            $this->components[$c['id']] = ['nome' => $c['nome'], 'area' => $this->names[$c['area'] ?? ''] ?? null];
        }
        foreach ($e['campos_experiencias'] ?? [] as $c) {
            $this->names[$c['id']] = $c['nome'];
        }
        foreach ($e['recortes_temporais'] ?? [] as $r) {
            $this->names[$r['id']] = trim(($r['nome'] ?? '') . (isset($r['faixa']) ? ' (' . $r['faixa'] . ')' : ''));
        }
        foreach ($e['competencias_especificas'] ?? [] as $c) {
            $this->names[$c['id']] = (string) ($c['texto'] ?? '');
        }
    }

    private function indexContexts(array $contexts): void
    {
        foreach ($contexts as $c) {
            if (isset($c['id'])) {
                $this->names[$c['id']] = $c['nome'] ?? $c['id'];
            }
        }
    }

    private function n(?string $id): ?string
    {
        return $id === null ? null : ($this->names[$id] ?? $id);
    }

    private function eiRow(array $o, string $source, ?string $component = null, ?string $unit = null): array
    {
        $group = (string) ($o['grupo_etario'] ?? '');
        return [
            'code'             => $o['codigo'],
            'stage'            => 'EI',
            'years'            => self::EI_GROUPS[$group] ?? $group,
            'component'        => $component ?? ($this->n($o['campo_experiencias'] ?? null) ?? 'Campo de experiência'),
            'area'             => 'Educação Infantil',
            'thematic_unit'    => $unit ?? $this->n($group),
            'knowledge_object' => null,
            'description'      => $o['texto'],
            'source'           => $source,
            'extra'            => json_encode(['documento' => $o['documento'] ?? null, 'fonte' => $o['fonte']['localizador_pdf'] ?? null], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function efStage(array $anos): string
    {
        $anos = array_map('intval', $anos);
        return $anos !== [] && min($anos) >= 6 ? 'EF_ANOS_FINAIS' : 'EF_ANOS_INICIAIS';
    }

    private function efRow(array $h, string $source): array
    {
        $org = $h['organizacao'] ?? [];
        $unit = match ($org['tipo'] ?? '') {
            'unidade_tematica' => $this->n($org['unidade_tematica'] ?? null),
            'campo_pratica'    => implode(' · ', array_filter([implode(', ', array_map(fn($c) => (string) $this->n($c), $org['campos_atuacao'] ?? [])), $this->n($org['pratica_linguagem'] ?? null)])),
            'eixo'             => implode(' · ', array_filter([$this->n($org['eixo'] ?? null), $this->n($org['unidade_tematica'] ?? null)])),
            default            => null,
        };
        $comp = $this->components[$h['componente']] ?? ['nome' => $this->n($h['componente']), 'area' => null];
        return [
            'code'             => $h['codigo'],
            'stage'            => $this->efStage($h['anos'] ?? []),
            'years'            => implode(',', array_map('strval', $h['anos'] ?? [])),
            'component'        => $comp['nome'],
            'area'             => $comp['area'],
            'thematic_unit'    => $unit !== null ? mb_substr($unit, 0, 191) : null,
            'knowledge_object' => implode('; ', array_map(fn($o) => (string) $this->n($o), $h['objetos_conhecimento'] ?? [])) ?: null,
            'description'      => $h['texto'],
            'source'           => $source,
            'extra'            => json_encode(['documento' => $h['documento'] ?? null, 'organizacao' => $org, 'fonte' => $h['fonte']['localizador_pdf'] ?? null], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function emRow(array $h, string $source): array
    {
        $area = $this->n($h['area'] ?? null);
        $comp = isset($h['componente']) && $h['componente'] !== null ? $this->n($h['componente']) : $area;
        $campos = array_map(fn($c) => (string) $this->n($c), $h['campos_atuacao_social'] ?? []);
        $competencias = array_map(fn($c) => (string) $this->n($c), $h['competencias_especificas'] ?? []);
        return [
            'code'             => $h['codigo'],
            'stage'            => 'EM',
            'years'            => '1,2,3',
            'component'        => (string) $comp,
            'area'             => $area,
            'thematic_unit'    => $campos !== [] ? mb_substr(implode(', ', $campos), 0, 191) : null,
            'knowledge_object' => $competencias !== [] ? implode(' | ', $competencias) : null,
            'description'      => $h['texto'],
            'source'           => $source,
            'extra'            => json_encode(['documento' => $h['documento'] ?? null, 'competencias' => $h['competencias_especificas'] ?? [], 'fonte' => $h['fonte']['localizador_pdf'] ?? null], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function compEfRow(array $h): array
    {
        return [
            'code'             => $h['codigo'],
            'stage'            => $this->efStage($h['anos'] ?? []),
            'years'            => implode(',', array_map('strval', $h['anos'] ?? [])),
            'component'        => 'Computação',
            'area'             => 'Computação (complemento à BNCC)',
            'thematic_unit'    => $this->n($h['eixo'] ?? null),
            'knowledge_object' => implode('; ', array_map(fn($o) => (string) $this->n($o), $h['objetos_conhecimento'] ?? [])) ?: null,
            'description'      => $h['texto'],
            'source'           => 'computacao-2022',
            'extra'            => json_encode(['documento' => 'computacao-2022', 'eixo' => $h['eixo'] ?? null], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function compEmRow(array $h): array
    {
        return [
            'code'             => $h['codigo'],
            'stage'            => 'EM',
            'years'            => '1,2,3',
            'component'        => 'Computação',
            'area'             => 'Computação (complemento à BNCC)',
            'thematic_unit'    => $this->n($h['eixo'] ?? null),
            'knowledge_object' => isset($h['competencia']) ? 'Competência ' . $h['competencia'] : null,
            'description'      => $h['texto'],
            'source'           => 'computacao-2022',
            'extra'            => json_encode(['documento' => 'computacao-2022', 'competencia' => $h['competencia'] ?? null], JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @return array{0:int,1:int} [inseridos, atualizados] */
    private function upsert(array $rows): array
    {
        $sql = 'INSERT INTO bncc_skills (code, stage, years, component, area, thematic_unit, knowledge_object, description, source, extra)
                VALUES (:code, :stage, :years, :component, :area, :thematic_unit, :knowledge_object, :description, :source, :extra)
                ON DUPLICATE KEY UPDATE stage = VALUES(stage), years = VALUES(years), component = VALUES(component), area = VALUES(area),
                    thematic_unit = VALUES(thematic_unit), knowledge_object = VALUES(knowledge_object), description = VALUES(description),
                    source = VALUES(source), extra = VALUES(extra)';
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM bncc_skills')->fetchColumn();
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare($sql);
            foreach ($rows as $r) {
                $st->execute($r);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Falha ao gravar a base BNCC: ' . $e->getMessage(), 0, $e);
        }
        $after = (int) $this->pdo->query('SELECT COUNT(*) FROM bncc_skills')->fetchColumn();
        $inseridos = $after - $before;
        return [$inseridos, count($rows) - $inseridos];
    }
}
