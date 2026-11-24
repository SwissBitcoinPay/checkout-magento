<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Sattleme\Payment\Model\Durations;

/**
 * The time to pay: a whole number of minutes, 1 to 60.
 */
class Minutes extends Value
{
    /**
     * Refuses anything else.
     *
     * @return $this
     * @throws LocalizedException When it is not 1 to 60.
     */
    public function beforeSave()
    {
        $value = $this->getValue();
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '') {
            $this->setValue((string) Durations::DEFAULT_MINUTES);
        } elseif (preg_match('/^[0-9]{1,2}$/D', $value) !== 1
            || (int) $value < Durations::MIN_MINUTES
            || (int) $value > Durations::MAX_MINUTES
        ) {
            throw new LocalizedException(__('The time to pay is a number of minutes from 1 to 60.'));
        } else {
            $this->setValue((string) (int) $value);
        }

        return parent::beforeSave();
    }
}
