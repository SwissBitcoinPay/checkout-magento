<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\BaconQrCode\Renderer\Path;

interface OperationInterface
{
    /**
     * Translates the operation's coordinates.
     */
    public function translate(float $x, float $y): self;
    /**
     * Rotates the operation's coordinates.
     */
    public function rotate(int $degrees): self;
}
