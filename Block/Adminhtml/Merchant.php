<?php

declare(strict_types=1);

namespace Sattleme\Payment\Block\Adminhtml;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Sattleme\Payment\Model\Amounts;
use Sattleme\Payment\Model\MerchantInfo;

/**
 * The settings' line "Connected to": what the key's check found of the merchant. Read only: it
 * posts nothing, so that saving the settings never changes it.
 */
class Merchant extends Field
{
    /**
     * Shows the merchant.
     *
     * @param \Magento\Backend\Block\Template\Context $context The block's context.
     * @param MerchantInfo $merchantInfo The merchant found.
     * @param Amounts $amounts Amounts in sats.
     * @param array<string,mixed> $data The block's data.
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        private readonly MerchantInfo $merchantInfo,
        private readonly Amounts $amounts,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The line, without an input nor the scope's checkbox.
     *
     * @param AbstractElement $element The field.
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $label = $element->getLabel();

        return '<tr id="row_' . $this->_escaper->escapeHtmlAttr($element->getHtmlId()) . '">'
            . '<td class="label">' . $this->_escaper->escapeHtml(is_scalar($label) ? (string) $label : '') . '</td>'
            . '<td class="value">' . $this->_getElementHtml($element) . '</td><td></td></tr>';
    }

    /**
     * What the check found, or that no key is checked.
     *
     * @param AbstractElement $element The field.
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $merchant = $this->merchantInfo->decode($element->getData('value'));
        if ($merchant === null) {
            return $this->_escaper->escapeHtml(__('No key checked yet.'));
        }

        return $this->_escaper->escapeHtml(
            __(
                '%1. Lightning: %2 to %3 sats. Deposit addresses: %4. Checked on %5.',
                $merchant['businessName'],
                $this->amounts->sats($merchant['minSats']),
                $this->amounts->sats($merchant['maxSats']),
                (string) $merchant['onchainAddresses'],
                $this->formatDate(gmdate('Y-m-d H:i:s', $merchant['checkedAt']), \IntlDateFormatter::MEDIUM, true)
            )
        );
    }
}
