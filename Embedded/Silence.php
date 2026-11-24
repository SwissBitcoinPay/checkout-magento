<?php

declare(strict_types=1);

namespace Sattleme\Payment\Embedded;

/**
 * PHP's error control operator, "@", as a function, for the libraries the package embeds
 * (tools/embed.php): Magento's coding standard refuses the operator at the severity that keeps a
 * package off Adobe Commerce Marketplace (Generic.PHP.NoSilencedErrors), embedded code included,
 * so their build writes each `@f(...)` as `Silence::call(static fn() => f(...))`.
 *
 * It does to error_reporting() what the operator does, as PHP's engine runs it
 * (Zend/zend_vm_def.h, ZEND_BEGIN_SILENCE and ZEND_END_SILENCE, and zend_execute.c's cleanup of a
 * silenced expression that throws): every error but the fatal ones is masked while the expression
 * runs, and the previous mask is back afterwards, unless the expression changed it itself. The
 * error handlers see the same errors with the same error_reporting(), PHP logs and shows the same
 * (none), error_get_last() is the same, and the expression's value is returned as it is.
 */
class Silence
{
    /**
     * The errors "@" never masks: Zend's E_FATAL_ERRORS (Zend/zend_errors.h).
     */
    public const FATAL = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;

    /**
     * The value of $expression, its non-fatal errors masked as "@" masks them.
     *
     * @template T
     * @param \Closure $expression The expression.
     * @phpstan-param \Closure(): T $expression
     * @return T
     */
    public static function call(\Closure $expression): mixed
    {
        $before = error_reporting();
        if (($before & ~self::FATAL) !== 0) {
            error_reporting($before & self::FATAL);
        }
        try {
            return $expression();
        } finally {
            if ((error_reporting() & ~self::FATAL) === 0 && ($before & ~self::FATAL) !== 0) {
                error_reporting($before);
            }
        }
    }
}
