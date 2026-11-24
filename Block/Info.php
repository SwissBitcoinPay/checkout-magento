<?php

declare(strict_types=1);

namespace Sattleme\Payment\Block;

use Magento\Framework\Phrase;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Block\Info as PaymentInfo;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Sattleme\Payment\Model\Amounts;
use Sattleme\Payment\Model\Attempt;
use Sattleme\Payment\Model\AttemptRepository;
use Sattleme\Payment\Model\ClockInterface;
use Sattleme\Payment\Model\Orders;
use Sattleme\Payment\Sdk\Errors;

/**
 * The payment's information: in the admin's order view, each attempt to pay the order with
 * bitcoin (its reference, amount and exchange rate, address and transactions linked to
 * mempool.space, proof of payment, last and next check, error in plain words); to the customer
 * (their account, emails, PDFs), the method's title alone.
 */
class Info extends PaymentInfo
{
    /**
     * @var string
     */
    protected $_template = 'Sattleme_Payment::info.phtml';

    /**
     * @param Context $context The block's context.
     * @param AttemptRepository $attempts The attempts.
     * @param ClockInterface $clock The clock.
     * @param Amounts $amounts Amounts in sats.
     * @param Errors $errors The SDK's errors.
     * @param Orders $orders The orders.
     * @param array<string,mixed> $data The block's data.
     */
    public function __construct(
        Context $context,
        private readonly AttemptRepository $attempts,
        private readonly ClockInterface $clock,
        private readonly Amounts $amounts,
        private readonly Errors $errors,
        private readonly Orders $orders,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The order's attempts, the latest first (in the admin only).
     *
     * @return list<Attempt>
     */
    public function getAttempts(): array
    {
        $info = $this->getInfo();
        $order = $info instanceof OrderPayment ? $info->getOrder() : null;
        // Never in an email nor a PDF (Magento sets their secure mode), only in the admin's order
        // view (its template: the store's shows the method's title only).
        $secure = $this->hasData('is_secure_mode') && (bool) $this->getData('is_secure_mode');
        if ($secure || !$order instanceof Order || $this->orders->idOf($order) === 0) {
            return [];
        }

        return $this->attempts->forOrder($this->orders->idOf($order), 20);
    }

    /**
     * $sats, formatted.
     *
     * @param string $sats The sats.
     * @return string
     */
    public function formatSats(string $sats): string
    {
        return $this->amounts->sats($sats, 'en_US');
    }

    /**
     * A Unix time as the admin reads it (in the admin's time zone), or "–".
     *
     * @param int|null $time The time.
     * @return string
     */
    public function formatUnixTime(?int $time): string
    {
        if ($time === null || $time <= 0) {
            return '–';
        }

        return $this->formatDate(gmdate('Y-m-d H:i:s', $time), \IntlDateFormatter::MEDIUM, true);
    }

    /**
     * Where $attempt is, in the merchant's words.
     *
     * @param Attempt $attempt The attempt.
     * @return Phrase
     */
    public function describeState(Attempt $attempt): Phrase
    {
        if ($attempt->outcome === Attempt::OUTCOME_PAID) {
            return match ($attempt->method()) {
                'lightning' => __('Paid in Lightning'),
                'onchain' => __('Paid on-chain'),
                default => __('Paid on-chain and in Lightning'),
            };
        }
        if ($attempt->outcome === Attempt::OUTCOME_EXPIRED) {
            return $attempt->hasReceived() ? __('Ended underpaid') : __('Expired unpaid');
        }
        if ($attempt->outcome === Attempt::OUTCOME_ABANDONED) {
            return __('Checks stopped after 90 days');
        }
        if (!$attempt->hasInvoice()) {
            return $attempt->state === Attempt::STATE_FAILED ? __('No invoice') : __('Invoice being made');
        }

        if ($attempt->statusState() === null && $attempt->checks === 0 && $attempt->error !== '') {
            return __('Not known yet: its first check failed');
        }

        return match ($attempt->statusState()) {
            'unconfirmed' => __('Being confirmed (%1 of 3 confirmations)', (string) (int) $attempt->confirmations()),
            'paid' => __('Paid, being recorded'),
            'expired' => __('Ended, being recorded'),
            default => $this->clock->now() < (int) $attempt->expiresAt
                ? __('Waiting for payment')
                : __('Ended, checking for a payment'),
        };
    }

    /**
     * The merchant's words for an error code, or null.
     *
     * @param string $code The code.
     * @return Phrase|null
     */
    public function explainError(string $code): ?Phrase
    {
        return $this->errors->explain($code);
    }

    /**
     * The payment hash a preimage proves.
     *
     * Its SHA-256 (BOLT 11), in hex; null for a text that is not 32 bytes of hex.
     *
     * @param string $preimage The preimage, in hex.
     * @return string|null
     */
    public function paymentHashOf(string $preimage): ?string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $preimage) !== 1) {
            return null;
        }
        $bytes = hex2bin($preimage);

        return $bytes === false ? null : hash('sha256', $bytes);
    }

    /**
     * The link of a transaction on mempool.space.
     *
     * @param string $txid The transaction.
     * @return string
     */
    public function transactionUrl(string $txid): string
    {
        return 'https://mempool.space/tx/' . rawurlencode($txid);
    }

    /**
     * The link of an address on mempool.space.
     *
     * @param string $address The address.
     * @return string
     */
    public function addressUrl(string $address): string
    {
        return 'https://mempool.space/address/' . rawurlencode($address);
    }
}
