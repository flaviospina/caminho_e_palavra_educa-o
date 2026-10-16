<?php
// app/controllers/api/BnccApiController.php
// Consultas JSON usadas pelo assistente (componentes e habilidades do recorte).
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\BnccSkill;
use App\Request;
use App\Response;

final class BnccApiController
{
    /** GET /api/bncc/componentes?etapa=&ano= */
    public function components(): Response
    {
        $etapa = Request::get('etapa');
        if (!isset(BnccSkill::STAGES[$etapa])) {
            return Response::json(['ok' => false, 'data' => [], 'mensagem' => 'Etapa inválida.'], 422);
        }
        return Response::json(['ok' => true, 'data' => BnccSkill::components($etapa, Request::get('ano') ?: null), 'mensagem' => '']);
    }

    /** GET /api/bncc/habilidades?etapa=&ano=&componente=&q= */
    public function skills(): Response
    {
        $etapa = Request::get('etapa');
        if (!isset(BnccSkill::STAGES[$etapa])) {
            return Response::json(['ok' => false, 'data' => [], 'mensagem' => 'Etapa inválida.'], 422);
        }
        $rows = BnccSkill::search($etapa, Request::get('ano') ?: null, Request::get('componente') ?: null, Request::get('q'), 150);
        $data = array_map(fn($r) => [
            'codigo' => $r['code'], 'anos' => $r['years'], 'componente' => $r['component'],
            'unidade' => $r['thematic_unit'], 'objeto' => $r['knowledge_object'], 'texto' => $r['description'],
        ], $rows);
        return Response::json(['ok' => true, 'data' => $data, 'mensagem' => $data === [] ? 'Nenhuma habilidade encontrada para esse recorte.' : '']);
    }
}
