<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Model;

use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Model\Qr;
use Sattleme\Payment\Test\Support\Sdk;
use Sattleme\Payment\Vendor\BaconQrCode\Common\ErrorCorrectionLevel;
use Sattleme\Payment\Vendor\BaconQrCode\Encoder\Encoder;

class QrTest extends TestCase
{
    public function testTheSvgDrawsExactlyTheModulesOfTheCode(): void
    {
        $uri = 'bitcoin:' . Sdk::ADDRESS . '?amount=0.00049&lightning=' . Sdk::BOLT11;
        $svg = $this->qr()->svg($uri, 'QR code');
        $this->assertIsString($svg);
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($svg));
        $root = $document->documentElement;
        $this->assertNotNull($root);
        $this->assertSame('svg', $root->nodeName);
        $this->assertSame('img', $root->getAttribute('role'));
        $matrix = Encoder::encode($uri, ErrorCorrectionLevel::M(), 'ISO-8859-1')->getMatrix();
        $width = $matrix->getWidth();
        $size = $width + 2 * Qr::QUIET_ZONE;
        $this->assertSame('0 0 ' . $size . ' ' . $size, $root->getAttribute('viewBox'));
        $path = $document->getElementsByTagName('path')->item(0);
        $this->assertNotNull($path);
        $drawn = [];
        $found = preg_match_all('/M(\d+) (\d+)h(\d+)v1h-\3z/', $path->getAttribute('d'), $runs, PREG_SET_ORDER);
        $this->assertGreaterThan(0, $found);
        foreach ($runs as $run) {
            for ($x = (int) $run[1]; $x < (int) $run[1] + (int) $run[3]; $x++) {
                $drawn[($x - Qr::QUIET_ZONE) . ',' . ((int) $run[2] - Qr::QUIET_ZONE)] = true;
            }
        }
        $this->assertSame('', preg_replace('/M\d+ \d+h(\d+)v1h-\1z/', '', $path->getAttribute('d')));
        for ($y = 0; $y < $width; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $this->assertSame($matrix->get($x, $y) === 1, isset($drawn[$x . ',' . $y]), "module {$x},{$y}");
            }
        }
    }

    public function testTheEncoderIsTheCopyThePackageEmbeds(): void
    {
        // The module installed as Composer installs its package: vendor/sattleme/module-payment.
        $file = (string) (new \ReflectionClass(Encoder::class))->getFileName();

        $this->assertStringEndsWith(
            '/sattleme/module-payment/vendor/bacon/bacon-qr-code/src/Encoder/Encoder.php',
            str_replace('\\', '/', $file)
        );
        $this->assertStringStartsWith('Sattleme\\Payment\\Vendor\\BaconQrCode\\', Encoder::class);
    }

    public function testItsLabelIsEscaped(): void
    {
        $svg = (string) $this->qr()->svg('lightning:lnbc1', '"><script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringContainsString('aria-label="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $svg);
    }

    public function testATextTooLongForAQrCodeGivesNone(): void
    {
        $this->assertNull($this->qr()->svg(str_repeat('x', 5000), 'QR code'));
    }

    /**
     * The QR codes, with an escaper as Magento's escapes attributes.
     *
     * @return Qr
     */
    private function qr(): Qr
    {
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtmlAttr')->willReturnCallback(
            static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );

        return new Qr($escaper);
    }
}
