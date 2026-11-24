<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\BaconQrCode\Renderer\RendererStyle;

use Sattleme\Payment\Vendor\DASPRiD\Enum\AbstractEnum;
/**
 * @method static self VERTICAL()
 * @method static self HORIZONTAL()
 * @method static self DIAGONAL()
 * @method static self INVERSE_DIAGONAL()
 * @method static self RADIAL()
 */
class GradientType extends AbstractEnum
{
    protected const VERTICAL = null;
    protected const HORIZONTAL = null;
    protected const DIAGONAL = null;
    protected const INVERSE_DIAGONAL = null;
    protected const RADIAL = null;
}
