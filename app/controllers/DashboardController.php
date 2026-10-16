<?php
// app/controllers/DashboardController.php
// Painel inicial após o login: atalhos conforme o perfil.
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Models\BnccSkill;
use App\Models\Consent;
use App\Response;
use App\View;

final class DashboardController
{
    public function index(): Response
    {
        $user = Auth::user();
        return Response::html(View::render('painel', [
            'titulo'     => 'Painel',
            'usuario'    => $user,
            'consent'    => Consent::latest((int) $user['id']),
            'bncc_total' => BnccSkill::count(),
        ]));
    }
}
