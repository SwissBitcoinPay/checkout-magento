<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Model\CustomerMessages;
use Sattleme\Payment\Model\PaymentFailure;

class CustomerMessagesTest extends TestCase
{
    public function testTheCustomerReadsWhatToDoNeverTheSdksInternals(): void
    {
        $messages = new CustomerMessages();
        $kinds = [
            'network' => PaymentFailure::TRANSIENT,
            'timeout' => PaymentFailure::TRANSIENT,
            'rate' => PaymentFailure::TRANSIENT,
            'onchain_unavailable' => PaymentFailure::TRANSIENT,
            'mismatch' => PaymentFailure::TRANSIENT,
            'invalid_key' => PaymentFailure::UNAVAILABLE,
            'revoked' => PaymentFailure::UNAVAILABLE,
            'channel_not_found' => PaymentFailure::UNAVAILABLE,
            'invalid_invoice' => PaymentFailure::UNAVAILABLE,
            'environment' => PaymentFailure::UNAVAILABLE,
            'no_key' => PaymentFailure::UNAVAILABLE,
            'invalid_order' => PaymentFailure::ORDER,
            'unsupported_currency' => PaymentFailure::ORDER,
            'price' => PaymentFailure::ORDER,
            'deadline' => PaymentFailure::TOO_LATE,
        ];
        foreach ($kinds as $code => $kind) {
            $this->assertSame($kind, $messages->kindOf($code), $code);
        }
        $texts = [];
        $kinds = [
            PaymentFailure::TRANSIENT,
            PaymentFailure::UNAVAILABLE,
            PaymentFailure::ORDER,
            PaymentFailure::TOO_LATE,
            PaymentFailure::NOT_PAYABLE,
        ];
        foreach ($kinds as $kind) {
            $text = (string) $messages->message($kind);
            $this->assertStringNotContainsString('_', $text, 'No code of the SDK: ' . $text);
            $texts[$text] = true;
        }
        $this->assertCount(5, $texts);
        $this->assertSame(PaymentFailure::ORDER, (new PaymentFailure(PaymentFailure::ORDER))->getKind());
    }
}
