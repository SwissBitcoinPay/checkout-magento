<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

/**
 * A row of the table sattleme_payment_attempt: one attempt to pay an order with bitcoin, its
 * random reference, the invoice the SDK made for it (its JSON, checkout-spec SPEC.md §3) and what
 * its checks found.
 *
 */
class Attempt
{
    /**
     * The SDK reports a payment made in an invoice's last seconds up to this long after its end.
     */
    public const GRACE_SECONDS = 120;

    /**
     * Stored, createInvoice under way or interrupted.
     */
    public const STATE_CREATING = 'creating';

    /**
     * It failed, and an invoice may exist for the reference: the next try asks for it again.
     */
    public const STATE_RETRY = 'retry';

    /**
     * No invoice, for good.
     */
    public const STATE_FAILED = 'failed';

    /**
     * The SDK's invoice is stored.
     */
    public const STATE_INVOICE = 'invoice';

    public const OUTCOME_PAID = 'paid';

    public const OUTCOME_EXPIRED = 'expired';

    public const OUTCOME_ABANDONED = 'abandoned';

    /**
     * @param int $id The attempt.
     * @param int $orderId Its order.
     * @param int $storeId Its order's store.
     * @param string $ref Its reference.
     * @param string $state Where it is (self::STATE_*).
     * @param array<string,mixed>|null $invoice The SDK's invoice (an Invoice, or a DeclaredInvoice).
     * @param array<string,mixed>|null $status The last status a check found (a PaymentStatus).
     * @param int|null $expiresAt When the invoice ends (Unix seconds).
     * @param bool $isFinal Whether it is final: paid, expired, failed or given up on.
     * @param string $outcome How it ended (paid, expired, abandoned).
     * @param string $error The code of its last error.
     * @param int $errors Failed checks in a row.
     * @param int $createdAt When it was made (Unix seconds).
     * @param int|null $checkedAt When it was last checked (Unix seconds).
     * @param int $checks How many checks found a status.
     * @param int|null $nextCheckAt When it is checked next in the background (Unix seconds).
     * @param int|null $leaseUntil Until when a check holds it (Unix seconds).
     * @param bool $shredded Whether the customer's personal data was removed (or is, once final).
     */
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly int $storeId,
        public readonly string $ref,
        public readonly string $state,
        public readonly ?array $invoice,
        public readonly ?array $status,
        public readonly ?int $expiresAt,
        public readonly bool $isFinal,
        public readonly string $outcome,
        public readonly string $error,
        public readonly int $errors,
        public readonly int $createdAt,
        public readonly ?int $checkedAt,
        public readonly int $checks,
        public readonly ?int $nextCheckAt,
        public readonly ?int $leaseUntil,
        public readonly bool $shredded
    ) {
    }

    /**
     * Whether the SDK made an invoice for this attempt (it may still be paid, or was).
     *
     * @return bool
     */
    public function hasInvoice(): bool
    {
        return $this->state === self::STATE_INVOICE && $this->invoice !== null;
    }

    /**
     * Whether the invoice is one the SDK handed out again.
     *
     * InvoicePending gives a DeclaredInvoice, an earlier one of this reference: shown only once a
     * check found it open.
     *
     * @return bool
     */
    public function isDeclared(): bool
    {
        return is_int($this->invoice['declaredAt'] ?? null);
    }

    /**
     * The invoice's amount, in sats (a string of digits), or "0" without an invoice.
     *
     * @return string
     */
    public function sats(): string
    {
        return $this->digitsOf($this->invoice['sats'] ?? null);
    }

    /**
     * When the SDK reports its payment as final at the latest (Unix seconds).
     *
     * Its end and the grace period; 0 without an invoice.
     *
     * @return int
     */
    public function end(): int
    {
        return $this->expiresAt === null ? 0 : $this->expiresAt + self::GRACE_SECONDS;
    }

    /**
     * The invoice's BOLT11 Lightning invoice, if it has one.
     *
     * @return string|null
     */
    public function bolt11(): ?string
    {
        return $this->stringMember($this->invoice, 'bolt11');
    }

    /**
     * The invoice's payment hash (hex), if it has a Lightning invoice.
     *
     * @return string|null
     */
    public function paymentHash(): ?string
    {
        return $this->stringMember($this->invoice, 'paymentHash');
    }

    /**
     * The deposit address the order leased, if it has one.
     *
     * @return string|null
     */
    public function onchainAddress(): ?string
    {
        return $this->stringMember($this->invoice, 'onchainAddress');
    }

    /**
     * What to show as one QR code: a bitcoin: or lightning: URI.
     *
     * @return string|null
     */
    public function paymentUri(): ?string
    {
        return $this->stringMember($this->invoice, 'paymentUri');
    }

    /**
     * The invoice's price: amount and currency, or null.
     *
     * @return array{amount: string, currency: string}|null
     */
    public function fiat(): ?array
    {
        $fiat = $this->invoice['fiat'] ?? null;
        if (!is_array($fiat) || !is_string($fiat['amount'] ?? null) || !is_string($fiat['currency'] ?? null)) {
            return null;
        }

        return ['amount' => $fiat['amount'], 'currency' => $fiat['currency']];
    }

    /**
     * The rate the price was converted at: its source and the price of one bitcoin, or null.
     *
     * @return array{source: string, price: string, currency: string}|null
     */
    public function rate(): ?array
    {
        $rate = $this->invoice['rate'] ?? null;
        if (!is_array($rate)
            || !is_string($rate['source'] ?? null)
            || !is_string($rate['price'] ?? null)
            || !is_string($rate['currency'] ?? null)
        ) {
            return null;
        }

        return ['source' => $rate['source'], 'price' => $rate['price'], 'currency' => $rate['currency']];
    }

    /**
     * The state of the last status (open, unconfirmed, paid, expired), or null before the first.
     *
     * @return string|null
     */
    public function statusState(): ?string
    {
        return $this->stringMember($this->status, 'state');
    }

    /**
     * How a paid invoice was paid: lightning, onchain or mixed.
     *
     * @return string|null
     */
    public function method(): ?string
    {
        return $this->stringMember($this->status, 'method');
    }

    /**
     * What came in time, in sats (on-chain, and by invoices of the rest): "0" when nothing did.
     *
     * @return string
     */
    public function received(): string
    {
        return $this->digitsOf($this->status['received'] ?? null);
    }

    /**
     * Whether something came in time.
     *
     * @return bool
     */
    public function hasReceived(): bool
    {
        return $this->received() !== '0';
    }

    /**
     * Whether bitcoin came for this attempt: it was paid (in Lightning, on-chain or both), or
     * something came in time (an underpayment, a payment being confirmed), which reached the
     * merchant's wallet whatever the invoice's end.
     *
     * @return bool
     */
    public function hasCome(): bool
    {
        return $this->outcome === self::OUTCOME_PAID || $this->statusState() === 'paid' || $this->hasReceived();
    }

    /**
     * What is left to pay of an on-chain underpayment, and how: sats, bolt11, paymentUri.
     *
     * @return array{sats: string, bolt11: ?string, paymentUri: ?string}|null
     */
    public function rest(): ?array
    {
        $rest = $this->status['rest'] ?? null;
        if (!is_array($rest)) {
            return null;
        }
        $sats = $this->digitsOf($rest['sats'] ?? null);
        if ($sats === '0') {
            return null;
        }

        return [
            'sats' => $sats,
            'bolt11' => $this->stringMember($rest, 'bolt11'),
            'paymentUri' => $this->stringMember($rest, 'paymentUri'),
        ];
    }

    /**
     * The confirmations of an on-chain payment being confirmed, if any.
     *
     * @return int|null
     */
    public function confirmations(): ?int
    {
        $confirmations = $this->status['confirmations'] ?? null;

        return is_int($confirmations) ? $confirmations : null;
    }

    /**
     * The transactions that paid the invoice, as the last status lists them (hex txids).
     *
     * @return list<string>
     */
    public function txids(): array
    {
        return $this->hexList($this->status['txids'] ?? null);
    }

    /**
     * The proofs of the Lightning payments: the preimage of the invoice, or those of its rest's.
     *
     * @return list<string>
     */
    public function preimages(): array
    {
        $preimages = $this->hexList($this->status['preimages'] ?? null);
        $preimage = $this->stringMember($this->status, 'preimage');
        if ($preimage !== null && preg_match('/^[0-9a-f]{64}$/D', $preimage) === 1) {
            array_unshift($preimages, $preimage);
        }

        return $preimages;
    }

    /**
     * Whether the invoice may still be paid, or its payment be found or confirmed: until its final
     * status is known and applied. Its time being over is not enough: a payment of its last
     * seconds, or an on-chain one made in time, is found by a check made after its end (the SDK
     * reports `expired` only then). While it is live, its order takes no other invoice nor
     * another method, and is not cancelled.
     *
     * @param int $now The time, in Unix seconds.
     * @return bool
     */
    public function isLive(int $now): bool
    {
        if ($this->isFinal) {
            return false;
        }
        if (!$this->hasInvoice()) {
            // Being made by another request right now: it may give an invoice in a moment.
            return $this->state === self::STATE_CREATING && $now < $this->createdAt + 60;
        }

        return $this->statusState() !== 'expired';
    }

    /**
     * Whether the invoice can still be paid by the customer: live, and before its end.
     *
     * The page shows its QR code only then.
     *
     * @param int $now The time, in Unix seconds.
     * @return bool
     */
    public function isPayable(int $now): bool
    {
        return $this->hasInvoice()
            && !$this->isFinal
            && $this->expiresAt !== null
            && $now < $this->expiresAt
            && in_array($this->statusState(), [null, 'open'], true);
    }

    /**
     * A string member of $data, or null.
     *
     * @param array<mixed>|null $data The data.
     * @param string $name The member.
     * @return string|null
     */
    private function stringMember(?array $data, string $name): ?string
    {
        $value = $data[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A string of digits without leading zeros ("0" for zero), else "0".
     *
     * @param mixed $value The value.
     * @return string
     */
    private function digitsOf(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9]{1,18}$/D', $value) !== 1) {
            return '0';
        }
        $value = ltrim($value, '0');

        return $value === '' ? '0' : $value;
    }

    /**
     * A list of lowercase 32-byte hex strings, else empty.
     *
     * @param mixed $value The value.
     * @return list<string>
     */
    private function hexList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $list = [];
        foreach ($value as $item) {
            if (is_string($item) && preg_match('/^[0-9a-f]{64}$/D', $item) === 1) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
