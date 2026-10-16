<?php
// app/services/GenerationService.php
// Enfileira a geração das páginas de um projeto (capa + páginas) ou de uma
// página específica. A requisição web só cria jobs; o worker faz o resto.
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\GenerationJob;
use App\Models\Page;
use App\Models\Project;
use InvalidArgumentException;

final class GenerationService
{
    /** Cria um job por página. @return int[] ids dos jobs */
    public static function enqueueProject(array $project, int $userId): array
    {
        if (!in_array($project['status'], ['revisado', 'pronto', 'erro'], true)) {
            throw new InvalidArgumentException('Revise e salve o conteúdo (passo 4) antes de gerar as imagens.');
        }
        $doc = Project::currentContent($project);
        Page::syncFromContent((int) $project['id'], $doc);
        $ids = [];
        foreach (Page::forProject((int) $project['id']) as $page) {
            Page::setStatus((int) $page['id'], 'pendente');
            $ids[] = GenerationJob::enqueue((int) $project['id'], (int) $page['id'], $userId);
        }
        Project::setStatus((int) $project['id'], 'gerando');
        AuditLog::record('geracao_enfileirada', 'projects', (int) $project['id'], ['jobs' => count($ids)], $userId);
        return $ids;
    }

    /** Reenfileira uma única página (regenerar). */
    public static function enqueuePage(array $project, int $pageNumber, int $userId): int
    {
        $page = Page::findByNumber((int) $project['id'], $pageNumber);
        if ($page === null) {
            throw new InvalidArgumentException('Página não encontrada neste projeto.');
        }
        if (in_array($page['status'], ['gerando', 'conferindo'], true)) {
            throw new InvalidArgumentException('Esta página já está sendo gerada. Aguarde.');
        }
        Page::setStatus((int) $page['id'], 'pendente');
        $id = GenerationJob::enqueue((int) $project['id'], (int) $page['id'], $userId);
        Project::setStatus((int) $project['id'], 'gerando');
        AuditLog::record('pagina_reenfileirada', 'pages', (int) $page['id'], ['numero' => $pageNumber], $userId);
        return $id;
    }

    /** Estado das páginas para a tela (consulta a cada 5 s). */
    public static function status(array $project): array
    {
        $pages = [];
        foreach (Page::forProject((int) $project['id']) as $p) {
            $check = $p['check_result'] ? json_decode((string) $p['check_result'], true) : null;
            $pages[] = [
                'numero'     => (int) $p['number'],
                'tipo'       => $p['kind'],
                'formato'    => $p['format'] ?? null,
                'status'     => $p['status'],
                'rotulo'     => Page::STATUS[$p['status']] ?? $p['status'],
                'tentativas' => (int) $p['attempts'],
                'imagem'     => Page::imageFile($p) !== null ? url('/projetos/' . $project['id'] . '/pagina/' . $p['number'] . '.png?v=' . (int) $p['attempts']) : null,
                'problemas'  => is_array($check) ? ($check['problemas'] ?? []) : [],
            ];
        }
        $fila = GenerationJob::pendingForProject((int) $project['id']);
        $projeto = Project::find((int) $project['id']);
        return [
            'status'     => $projeto['status'] ?? $project['status'],
            'rotulo'     => Project::STATUS[$projeto['status'] ?? ''] ?? '',
            'concluido'  => $fila === 0,
            'na_fila'    => $fila,
            'paginas'    => $pages,
            'prontas'    => count(array_filter($pages, fn($p) => $p['status'] === 'pronto')),
            'total'      => count($pages),
        ];
    }
}
