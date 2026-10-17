<?php

namespace Tests;

use App\Services\Documents\GenerateurPdf;
use App\Services\Medias\ConversionWebp;
use App\Support\GardeFouBase;
use Database\Seeders\ParametresPlateformeSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\ConversionWebpFactice;
use Tests\Support\PdfFactice;

abstract class TestCase extends BaseTestCase
{
    /**
     * Les tests qui rafraîchissent la base chargent les paramètres de la plateforme initiaux
     * (identité, numéro vert…), comme une installation réelle.
     */
    protected bool $seed = true;

    protected string $seeder = ParametresPlateformeSeeder::class;

    /** Base protégée : les tests refusent de s'exécuter sur une base qui ne se termine pas par « _test ». */
    public function createApplication()
    {
        $app = parent::createApplication();
        GardeFouBase::exigerBaseDeTest();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Doublures rapides (conversion WebP, mise en page PDF) ; les vrais traitements sont testés une fois chacun.
        $this->app->instance(ConversionWebp::class, new ConversionWebpFactice);
        $this->app->instance(GenerateurPdf::class, new PdfFactice);
    }

    /** Vraie conversion WebP (test de bout en bout). */
    protected function conversionWebpReelle(): void
    {
        $this->app->instance(ConversionWebp::class, new ConversionWebp);
    }

    /** Vrai PDF DomPDF (test de bout en bout). */
    protected function pdfReel(): void
    {
        $this->app->instance(GenerateurPdf::class, new GenerateurPdf);
    }
}
