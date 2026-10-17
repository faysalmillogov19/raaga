<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TypeCarte;
use App\Http\Controllers\Controller;
use App\Services\Cartes\Cartes;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** « Ma carte » du personnel (back-office) : carte PERSONNEL émise par le SUPER_ADMIN. */
class MaCarteController extends Controller
{
    public function show(Request $request): View
    {
        return view('admin.carte', ['carte' => Cartes::courante($request->user(), TypeCarte::Personnel)]);
    }
}
