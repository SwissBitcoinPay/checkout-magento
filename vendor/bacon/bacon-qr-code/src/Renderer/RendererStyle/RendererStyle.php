<?php

declare (strict_types=1);
namespace Sattleme\Payment\Vendor\BaconQrCode\Renderer\RendererStyle;

use Sattleme\Payment\Vendor\BaconQrCode\Renderer\Eye\EyeInterface;
use Sattleme\Payment\Vendor\BaconQrCode\Renderer\Eye\ModuleEye;
use Sattleme\Payment\Vendor\BaconQrCode\Renderer\Module\ModuleInterface;
use Sattleme\Payment\Vendor\BaconQrCode\Renderer\Module\SquareModule;
class RendererStyle
{
    private ModuleInterface $module;
    private EyeInterface|null $eye;
    private Fill $fill;
    public function __construct(private int $size, private int $margin = 4, ?ModuleInterface $module = null, ?EyeInterface $eye = null, ?Fill $fill = null)
    {
        $this->module = $module ?: SquareModule::instance();
        $this->eye = $eye ?: new ModuleEye($this->module);
        $this->fill = $fill ?: Fill::default();
    }
    public function withSize(int $size): self
    {
        $style = clone $this;
        $style->size = $size;
        return $style;
    }
    public function withMargin(int $margin): self
    {
        $style = clone $this;
        $style->margin = $margin;
        return $style;
    }
    public function getSize(): int
    {
        return $this->size;
    }
    public function getMargin(): int
    {
        return $this->margin;
    }
    public function getModule(): ModuleInterface
    {
        return $this->module;
    }
    public function getEye(): EyeInterface
    {
        return $this->eye;
    }
    public function getFill(): Fill
    {
        return $this->fill;
    }
}
