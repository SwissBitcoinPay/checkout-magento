<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\BaconQrCode\Renderer;

use Sattleme\Payment\Vendor\BaconQrCode\Encoder\QrCode;
interface RendererInterface
{
    public function render(QrCode $qrCode): string;
}
