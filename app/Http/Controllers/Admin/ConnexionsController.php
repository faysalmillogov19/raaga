<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Journal des connexions au back-office (SUPER_ADMIN).
 */
class ConnexionsController extends Controller
{
    public function index(): View
    {
        return view('admin.connexions', [
            'connexions' => DB::table('connexions_backoffice')
                ->leftJoin('users', 'users.id', '=', 'connexions_backoffice.user_id')
                ->select('connexions_backoffice.*', 'users.name as nom_utilisateur')
                ->orderByDesc('connexions_backoffice.id')
                ->paginate(50),
        ]);
    }
}
