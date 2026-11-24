<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Embedded;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Embedded\Silence;

/**
 * Silence::call(), which the embedded libraries' build writes where they used "@": the same as
 * the operator, side by side with it, as error handlers, PHP and the caller see it.
 *
 * phpcs:disable Generic.PHP.NoSilencedErrors -- the operator itself, to compare with
 */
class SilenceTest extends TestCase
{
    /**
     * @var int
     */
    private int $reporting;

    protected function setUp(): void
    {
        $this->reporting = error_reporting();
    }

    protected function tearDown(): void
    {
        error_reporting($this->reporting);
        parent::tearDown();
    }

    public function testAWarningReachesTheHandlerMaskedAsUnderTheOperator(): void
    {
        foreach ([E_ALL, E_ALL & ~E_WARNING, E_ERROR | E_WARNING, 0] as $level) {
            $operator = $this->traced($level, static fn(): mixed => @iconv('utf-8', 'ISO-8859-1', "\u{20ac}"));
            $silence = $this->traced(
                $level,
                static fn(): mixed => Silence::call(static fn() => iconv('utf-8', 'ISO-8859-1', "\u{20ac}"))
            );

            $this->assertSame($operator, $silence, "error_reporting {$level}");
            $this->assertFalse($silence['value']);
        }
        $traced = $this->traced(
            E_ALL,
            static fn(): mixed => Silence::call(static fn() => iconv('utf-8', 'ISO-8859-1', "\u{20ac}"))
        );
        $this->assertSame([['notice', E_ALL & Silence::FATAL]], $traced['handled']);
    }

    public function testTheValueIsTheExpressionsAndTheMaskComesBack(): void
    {
        error_reporting(E_ALL);

        $this->assertSame('caf' . "\xe9", Silence::call(static fn() => iconv('utf-8', 'ISO-8859-1', "caf\u{e9}")));
        $this->assertSame(E_ALL, error_reporting());
        $this->assertSame(E_ALL & Silence::FATAL, Silence::call(static fn(): int => error_reporting()));
        $this->assertSame(@error_reporting(), Silence::call(static fn(): int => error_reporting()));
        $this->assertSame(E_ALL, error_reporting());
    }

    public function testAnExpressionThatThrowsLeavesTheMaskAsTheOperatorDoes(): void
    {
        error_reporting(E_ALL);
        $expression = static function (bool $throw): string {
            if ($throw) {
                throw new \LogicException('thrown');
            }

            return 'not thrown';
        };
        try {
            Silence::call(static fn(): string => $expression(true));
            $this->fail('Nothing thrown');
        } catch (\LogicException $e) {
            $this->assertSame('thrown', $e->getMessage());
        }
        $this->assertSame(E_ALL, error_reporting());
    }

    public function testAMaskTheExpressionSetsItselfStaysAsWithTheOperator(): void
    {
        error_reporting(E_ALL);
        @error_reporting(E_ALL & ~E_NOTICE);
        $operator = error_reporting();
        error_reporting(E_ALL);
        Silence::call(static fn(): int => error_reporting(E_ALL & ~E_NOTICE));

        $this->assertSame($operator, error_reporting());
        $this->assertSame(E_ALL & ~E_NOTICE, error_reporting());
    }

    /**
     * What error handlers, PHP and the caller see of $expression run with error_reporting $level:
     * each error the handler got (its kind, and error_reporting() then), the mask inside and
     * after, the expression's value, the last error PHP recorded.
     *
     * @param int $level The mask before.
     * @param \Closure $expression The expression.
     * @phpstan-param \Closure(): mixed $expression
     * @return array{handled: list<array{string, int}>, after: int, value: mixed, last: string|null}
     */
    private function traced(int $level, \Closure $expression): array
    {
        $handled = [];
        set_error_handler(static function (int $number, string $message) use (&$handled): bool {
            $handled[] = [$number === E_NOTICE ? 'notice' : (string) $number, error_reporting()];

            return false;
        });
        error_clear_last();
        error_reporting($level);
        $value = $expression();
        $after = error_reporting();
        error_reporting(E_ALL);
        restore_error_handler();
        $last = error_get_last();

        return [
            'handled' => $handled,
            'after' => $after,
            'value' => $value,
            'last' => $last === null ? null : $last['type'] . ' ' . $last['message'],
        ];
    }
}
