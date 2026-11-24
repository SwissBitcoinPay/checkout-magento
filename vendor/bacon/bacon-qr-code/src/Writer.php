<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\BaconQrCode;

use Sattleme\Payment\Vendor\BaconQrCode\Common\ErrorCorrectionLevel;
use Sattleme\Payment\Vendor\BaconQrCode\Common\Version;
use Sattleme\Payment\Vendor\BaconQrCode\Encoder\Encoder;
use Sattleme\Payment\Vendor\BaconQrCode\Exception\InvalidArgumentException;
use Sattleme\Payment\Vendor\BaconQrCode\Renderer\RendererInterface;
/**
 * QR code writer.
 */
class Writer
{
    /**
     * Creates a new writer with a specific renderer.
     */
    public function __construct(private readonly RendererInterface $renderer)
    {
    }
    /**
     * Writes QR code and returns it as string.
     *
     * Content is a string which *should* be encoded in UTF-8, in case there are
     * non ASCII-characters present.
     *
     * @throws InvalidArgumentException if the content is empty
     */
    public function writeString(string $content, string $encoding = Encoder::DEFAULT_BYTE_MODE_ENCODING, ?ErrorCorrectionLevel $ecLevel = null, ?Version $forcedVersion = null): string
    {
        if (strlen($content) === 0) {
            throw new InvalidArgumentException('Found empty contents');
        }
        if (null === $ecLevel) {
            $ecLevel = ErrorCorrectionLevel::L();
        }
        return $this->renderer->render(Encoder::encode($content, $ecLevel, $encoding, $forcedVersion));
    }
    /**
     * Writes QR code to a file.
     *
     * @see Writer::writeString()
     */
    public function writeFile(string $content, string $filename, string $encoding = Encoder::DEFAULT_BYTE_MODE_ENCODING, ?ErrorCorrectionLevel $ecLevel = null, ?Version $forcedVersion = null): void
    {
        file_put_contents($filename, $this->writeString($content, $encoding, $ecLevel, $forcedVersion));
    }
}
