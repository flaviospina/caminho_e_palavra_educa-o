<?php
// app/controllers/admin/BnccController.php
// Admin: importação e consulta da base BNCC.
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Csrf;
use App\Models\AuditLog;
use App\Models\BnccSkill;
use App\Request;
use App\Response;
use App\Services\BnccImporter;
use App\View;
use Throwable;

final class BnccController
{
    public function index(): Response
    {
        $codigo = Request::get('codigo');
        $etapa = Request::get('etapa');
        $ano = Request::get('ano');
        $componente = Request::get('componente');
        $busca = Request::get('q');
        $consulta = null;
        $resultados = [];
        if ($codigo !== '') {
            $consulta = BnccSkill::validateFor($codigo, $etapa, $ano, $componente);
        } elseif ($etapa !== '') {
            $resultados = BnccSkill::search($etapa, $ano ?: null, $componente ?: null, $busca, 100);
        }
        return Response::html(View::render('admin/bncc', [
            'titulo'      => 'Base BNCC',
            'total'       => BnccSkill::count(),
            'por_etapa'   => BnccSkill::countsByStage(),
            'etapas'      => BnccSkill::STAGES,
            'componentes' => $etapa !== '' ? BnccSkill::components($etapa, $ano ?: null) : [],
            'filtro'      => compact('codigo', 'etapa', 'ano', 'componente', 'busca'),
            'consulta'    => $consulta,
            'resultados'  => $resultados,
            'arquivos_ok' => is_file(ROOT_PATH . '/data/bncc/bncc-2018/ensino-fundamental.json'),
        ]));
    }

    /** POST /admin/bncc/importar */
    public function import(): Response
    {
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/admin/bncc'));
        }
        @set_time_limit(300);
        try {
            $r = (new BnccImporter())->run();
            AuditLog::record('bncc_importada', 'bncc_skills', null, $r);
            Request::flash('ok', sprintf('Base BNCC importada em %.1f s: %d registros (%d novos, %d atualizados).', $r['segundos'], $r['total'], $r['inseridos'], $r['atualizados']));
        } catch (Throwable $e) {
            \App\Log::error('Importação BNCC falhou', ['erro' => $e->getMessage()]);
            Request::flash('erro', 'A importação falhou: ' . $e->getMessage());
        }
        return Response::redirect(url('/admin/bncc'));
    }
}
