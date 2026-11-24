<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\BaconQrCode\Renderer\RendererStyle;

use Sattleme\Payment\Vendor\BaconQrCode\Renderer\Color\ColorInterface;
class Gradient
{
    public function __construct(private readonly ColorInterface $startColor, private readonly ColorInterface $endColor, private readonly GradientType $type)
    {
    }
    public function getStartColor(): ColorInterface
    {
        return $this->startColor;
    }
    public function getEndColor(): ColorInterface
    {
        return $this->endColor;
    }
    public function getType(): GradientType
    {
        return $this->type;
    }
}
