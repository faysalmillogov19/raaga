<?php

namespace Tests;

use Database\Seeders\ParametresPlateformeSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Les tests qui rafraîchissent la base chargent les paramètres de la plateforme initiaux
     * (identité, numéro vert…), comme une installation réelle.
     */
    protected bool $seed = true;

    protected string $seeder = ParametresPlateformeSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
