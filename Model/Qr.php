<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\Escaper;
use Sattleme\Payment\Vendor\BaconQrCode\Common\ErrorCorrectionLevel;
use Sattleme\Payment\Vendor\BaconQrCode\Encoder\Encoder;

/**
 * A text as a QR code, made on the server by BaconQrCode, drawn as an inline SVG image: no
 * script, no request, nothing loaded from elsewhere. BaconQrCode is the copy the package embeds
 * (tools/embed.php), prefixed: never the one another module or Magento's two-factor
 * authentication installs.
 */
class Qr
{
    /**
     * Modules of white around the code, as the QR code standard asks for.
     */
    public const QUIET_ZONE = 4;

    /**
     * @param Escaper $escaper Magento's escaper.
     */
    public function __construct(private readonly Escaper $escaper)
    {
    }

    /**
     * $text as an SVG image labelled $label: dark modules as one path of horizontal runs, on a
     * white background of its own, so that it scans whatever the page's colours. Null when the
     * text is too long for a QR code (the page shows it as text then).
     *
     * @param string $text The text.
     * @param string $label The label.
     * @return string|null
     */
    public function svg(string $text, string $label): ?string
    {
        try {
            // Error correction M (15 %), as wallets' own codes; byte mode, the URI's ASCII as it is.
            $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), 'ISO-8859-1')->getMatrix();
        } catch (\Throwable) {
            return null;
        }
        $width = $matrix->getWidth();
        $size = $width + 2 * self::QUIET_ZONE;
        $runs = '';
        for ($y = 0; $y < $width; $y++) {
            $x = 0;
            while ($x < $width) {
                if ($matrix->get($x, $y) !== 1) {
                    ++$x;
                    continue;
                }
                $start = $x;
                while ($x < $width && $matrix->get($x, $y) === 1) {
                    ++$x;
                }
                $length = $x - $start;
                $runs .= 'M' . ($start + self::QUIET_ZONE) . ' ' . ($y + self::QUIET_ZONE)
                    . 'h' . $length . 'v1h-' . $length . 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" role="img"'
            . ' aria-label="' . $this->escaper->escapeHtmlAttr($label) . '" shape-rendering="crispEdges">'
            . '<rect width="' . $size . '" height="' . $size . '" fill="#fff"/>'
            . '<path fill="#000" d="' . $runs . '"/>'
            . '</svg>';
    }
}
