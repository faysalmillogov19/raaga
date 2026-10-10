<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_format_fcfa_utilise_des_espaces_insecables_et_aucune_decimale(): void
    {
        $this->assertSame("250\u{00A0}000\u{00A0}FCFA", format_fcfa(250_000));
        $this->assertSame("10\u{00A0}000\u{00A0}FCFA", format_fcfa(10_000));
        $this->assertSame("0\u{00A0}FCFA", format_fcfa(0));
    }

    public function test_format_nombre(): void
    {
        $this->assertSame("1\u{00A0}284\u{00A0}650", format_nombre(1_284_650));
        $this->assertSame('856', format_nombre(856));
    }

    public function test_pourcentage_lisible(): void
    {
        $this->assertSame("60\u{00A0}%", pourcentage_lisible(11_230, 18_742));
        $this->assertSame("<\u{00A0}1\u{00A0}%", pourcentage_lisible(72, 18_742));
        $this->assertSame("0\u{00A0}%", pourcentage_lisible(0, 18_742));
        $this->assertSame("0\u{00A0}%", pourcentage_lisible(5, 0));
    }

    public function test_format_md_et_pourcentage(): void
    {
        $this->assertSame("3\u{00A0}Md", format_md(3_000_000_000));
        $this->assertSame("16,245\u{00A0}Md", format_md(16_245_000_000));
        $this->assertSame("6,45\u{00A0}Md", format_md(6_454_000_000, 2));
        $this->assertSame("3,00\u{00A0}Md", format_md(3_000_000_000, 2));
        $this->assertSame("42,8\u{00A0}%", format_pourcentage(1_284_650_000, 3_000_000_000));
        $this->assertSame("99,9\u{00A0}%", format_pourcentage(2_999_999_999, 3_000_000_000));
    }

    public function test_format_md_fcfa(): void
    {
        $this->assertSame("16,245\u{00A0}Md\u{00A0}FCFA", format_md_fcfa(16_245_000_000, 3));
        $this->assertSame("3,00\u{00A0}Md\u{00A0}FCFA", format_md_fcfa(3_000_000_000));
    }
}
