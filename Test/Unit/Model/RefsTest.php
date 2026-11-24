<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Model\Refs;

class RefsTest extends TestCase
{
    public function testEachAttemptHasARandomReferenceOf128Bits(): void
    {
        $refs = new Refs();
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $ref = $refs->newRef('000000042');
            $this->assertMatchesRegularExpression('/^m2-000000042-[0-9a-f]{32}$/D', $ref);
            $this->assertTrue($refs->isRef($ref));
            $seen[$ref] = true;
        }
        $this->assertCount(200, $seen);
    }

    public function testTheOrdersNumberKeepsOnlyWhatTheSdkTakes(): void
    {
        $refs = new Refs();
        $this->assertMatchesRegularExpression('/^m2-ABC12_x\.y:z-[0-9a-f]{32}$/D', $refs->newRef('ABC/12_x.y:z'));
        $this->assertMatchesRegularExpression('/^m2-[0-9a-f]{32}$/D', $refs->newRef('«»'));
        $long = $refs->newRef(str_repeat('9', 80));
        $this->assertLessThanOrEqual(64, strlen($long));
        $this->assertTrue($refs->isRef($long));
        $this->assertFalse($refs->isRef(''));
        $this->assertFalse($refs->isRef(str_repeat('a', 65)));
        $this->assertFalse($refs->isRef('a b'));
    }

    public function testTheNoteIsTheShopsNameAndTheOrderWithinTheSdksRules(): void
    {
        $refs = new Refs();
        $this->assertSame('Test Coffee · Order 000000001', $refs->note('Test Coffee', 'Order 000000001'));
        $this->assertSame('Order 7', $refs->note('', 'Order 7'));
        $this->assertSame('A B · Order 7', $refs->note("A\n\tB\x7f", 'Order 7'));
        $note = $refs->note(str_repeat('é', 200), 'Order 000000001');
        $this->assertSame(100, mb_strlen($note, 'UTF-8'));
        $this->assertStringEndsWith('… · Order 000000001', $note);
        $this->assertSame(1, preg_match('/^[^\x00-\x1F\x7F]*$/u', $refs->note("\x00Shop\x1F", "Order\x00 1")));
    }
}
