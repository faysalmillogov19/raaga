<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Zone d'un site (bloc A, pôle boucherie-poissonnerie…), utilisée par les capacités prévues. */
#[Table(name: 'zones_sites')]
#[Fillable(['code', 'libelle'])]
class ZoneSite extends Model {}
