<?php
/**
 * The libraries the package embeds: the SDK pinned in sdk/ (sattleme/checkout), the QR code
 * encoder (bacon/bacon-qr-code) and its one dependency (dasprid/enum), at the versions
 * tools/embed/composer.lock locks, prefixed with Sattleme\Payment\Vendor so that no other module
 * can load another version of them in their place (PORTING.md, "Packages that depend on an SDK"),
 * as checkout-prestashop embeds them: with PHP-Scoper, in vendor/<package>/src/ with each one's
 * LICENSE in vendor/<package>/.
 *
 *   php tools/embed.php build
 *       Installs the lock in build/embed/vendor (Composer: `composer` on PATH, or COMPOSER_BIN),
 *       prefixes it into build/embed/scoped (tools/embed/scoper.inc.php, PHP-Scoper's PHAR,
 *       downloaded once into build/tools/ and checked against its SHA-256), then writes
 *       build/embed/package/vendor/, patched as below. tools/build.php runs it for the package.
 *   php tools/embed.php check
 *       Whether that build is the locked libraries: each of their files compared with the
 *       original (sdk/ for the SDK, the lock's for the others) token by token, nothing changed but
 *       the prefix and the patches below, where they are bound; and its QR code encoder draws the
 *       same codes as the locked one, of texts of every kind.
 *
 * Adobe Commerce Marketplace's code sniffer (`phpcs --standard=Magento2 --extensions=php,phtml
 * --error-severity=10 --ignore-annotations`) refuses a package with one error anywhere in it,
 * embedded libraries included, and ignores annotations. The libraries hold code that its rules
 * refuse; the build changes it, in ways that change nothing the code does:
 *
 * - Magento2.PHP.FinalImplementation, `final` (the three libraries): the keyword is removed. It
 *   only forbids extending a class or overriding a method, which the code, loading as it is, never
 *   does; the one reflection the libraries use (dasprid/enum's constants(): isProtected(),
 *   getValue()) never reads it.
 * - Magento2.Security.InsecureFunction, `assert()` (the SDK): each assert() statement is removed.
 *   It is what PHP does on a production server (zend.assertions = -1, php.ini-production's): it
 *   does not even compile them. Each removed one only reads variables, properties, array elements
 *   and constants, and calls the is_*() type checks: nothing it does is missed.
 * - Magento2.Legacy.RestrictedCode, `ArrayObject` (the SDK's relay reads, two files): ArrayIterator
 *   instead. PHP implements both classes with the same object handlers and the same functions for
 *   what the SDK does with them (ext/spl/spl_array.c and spl_array.stub.php: new, [] =, count(),
 *   getArrayCopy()); the rule's concern, unserializing such an object, does not arise, as the SDK
 *   never serializes them.
 * - Generic.PHP.NoSilencedErrors, `@` (the SDK's check of PCRE, three of BaconQrCode's iconv()
 *   calls): `@f(...)` becomes `\Sattleme\Payment\Embedded\Silence::call(static fn() => f(...))`,
 *   which masks the errors as the operator does (Embedded/Silence.php).
 * - Magento2.Security.InsecureFunction, `serialize()` and `unserialize()` (dasprid/enum's
 *   EnumMap.php): the file is left out (tools/embed/scoper.inc.php). BaconQrCode, the library's only
 *   user, never uses it at the locked version; the build fails if anything refers to it.
 *
 * Each patch is bound to what it was proven for: a new version of a library that has these
 * constructs elsewhere, or in another form, fails the build until its patch is proven again.
 */

declare(strict_types=1);

namespace Sattleme\Payment\Tools;

/**
 * Builds the embedded libraries.
 */
class Embedded
{
    /**
     * The prefix of their namespaces.
     */
    public const PREFIX = 'Sattleme\\Payment\\Vendor\\';

    /**
     * The libraries, by package: the namespace each one's src/ holds.
     */
    public const PACKAGES = [
        'sattleme/checkout' => 'Sattleme\\Checkout\\',
        'bacon/bacon-qr-code' => 'BaconQrCode\\',
        'dasprid/enum' => 'DASPRiD\\Enum\\',
    ];

    /**
     * Files of theirs the package leaves out (tools/embed/scoper.inc.php), and the class each one
     * declares (its short name), which nothing may refer to.
     */
    public const LEFT_OUT = ['dasprid/enum' => ['src/EnumMap.php' => 'EnumMap']];

    /**
     * PHP-Scoper: its release's PHAR, the version checkout-prestashop uses.
     */
    public const SCOPER_URL = 'https://github.com/humbug/php-scoper/releases/download/0.18.20/php-scoper.phar';

    public const SCOPER_SHA256 = '5e35e4a89ea53b2c70a19bfa8a856e4f9080aa12eb13a5bce650bb69d08eb122';

    /**
     * The class whose call replaces "@".
     */
    public const SILENCE = '\\' . \Sattleme\Payment\Embedded\Silence::class;

    /**
     * Where the libraries use ArrayObject (package => file => times), and what for: new, [] =,
     * count() and getArrayCopy() of the relays' events, in one read.
     */
    private const ARRAY_OBJECTS = [
        'sattleme/checkout' => ['src/Internal/Nostr/PagedRead.php' => 2, 'src/Internal/Nostr/RelayPool.php' => 2],
    ];

    /**
     * Where the libraries use "@" (package => file => times), each time on a call of a function.
     */
    private const SILENCED = [
        'sattleme/checkout' => ['src/Internal/Environment.php' => 1],
        'bacon/bacon-qr-code' => ['src/Encoder/Encoder.php' => 3],
    ];

    /**
     * The only functions a removed assertion may call: type checks, which do nothing else.
     */
    private const TYPE_CHECKS = ['is_array', 'is_bool', 'is_float', 'is_int', 'is_numeric', 'is_string'];

    /**
     * What a removed assertion may hold besides "!", ",", brackets and the type checks' calls:
     * names, variables, literals, reads of properties, elements and constants, comparisons.
     */
    private const ASSERTION_TOKENS = [
        T_BOOLEAN_AND, T_BOOLEAN_OR, T_CONSTANT_ENCAPSED_STRING, T_DNUMBER, T_DOUBLE_COLON, T_INSTANCEOF, T_ISSET,
        T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_LNUMBER, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED,
        T_NULLSAFE_OBJECT_OPERATOR, T_OBJECT_OPERATOR, T_STRING, T_VARIABLE, T_WHITESPACE,
    ];

    /**
     * @param string $root The repository.
     */
    public function __construct(private readonly string $root)
    {
    }

    /**
     * Builds the libraries into build/embed/package.
     *
     * That directory (its vendor/ is what the package holds), and how many of each patch it made.
     *
     * @return array{string, array<string, int>}
     */
    public function build(): array
    {
        $build = $this->root . '/build/embed';
        $this->remove($build);
        $this->composer(['validate', '--no-check-publish', '--no-interaction', '--working-dir=tools/embed']);
        $this->composer(
            [
                'install', '--working-dir=tools/embed', '--no-dev', '--no-interaction', '--no-progress',
                '--no-scripts', '--no-plugins',
            ],
            ['COMPOSER_VENDOR_DIR' => $build . '/vendor']
        );
        $installed = json_decode($this->read($build . '/vendor/composer/installed.json'), true);
        $names = [];
        $packages = is_array($installed) && is_array($installed['packages'] ?? null) ? $installed['packages'] : [];
        foreach ($packages as $package) {
            $names[] = is_array($package) && is_string($package['name'] ?? null) ? $package['name'] : '';
        }
        sort($names);
        $expected = array_keys(self::PACKAGES);
        sort($expected);
        if ($names !== $expected) {
            throw new \RuntimeException(
                'tools/embed/composer.lock installs ' . implode(', ', $names) . ', not ' . implode(', ', $expected)
            );
        }
        $this->run([PHP_BINARY, $this->scoper(), 'add-prefix', '--config=tools/embed/scoper.inc.php', '--force',
            '--no-interaction', '--quiet']);
        $counts = ['final' => 0, 'assert' => 0, 'ArrayObject' => 0, '@' => 0, 'left out' => 0];
        foreach (self::PACKAGES as $package => $namespace) {
            $scoped = $build . '/scoped/' . $package;
            $target = $build . '/package/vendor/' . $package;
            $files = $this->filesUnder($scoped);
            if (!in_array('LICENSE', $files, true) || !in_array('src/' . $this->firstClass($package), $files, true)) {
                throw new \RuntimeException("PHP-Scoper wrote no LICENSE or no source of {$package}");
            }
            foreach (array_keys(self::LEFT_OUT[$package] ?? []) as $file) {
                if (in_array($file, $files, true)) {
                    throw new \RuntimeException("{$package}'s {$file} is not left out");
                }
                $counts['left out']++;
            }
            foreach ($files as $file) {
                $code = $this->read($scoped . '/' . $file);
                if (str_ends_with($file, '.php')) {
                    $code = $this->patch($package, $file, $code, $counts);
                }
                $this->write($target . '/' . $file, $code);
            }
            $this->checkPatchedAsBound($package, $target);
        }
        $this->checkNothingRefersToWhatIsLeftOut($build . '/package/vendor');

        return [$build . '/package', $counts];
    }

    /**
     * What differs between the embedded libraries and the locked ones.
     *
     * Of the libraries `tools/embed.php build` wrote, and the locked ones it built them from:
     * empty when the build changed nothing they do. Each file is the original's token by token
     * but for the prefix and the patches (tokenProblems()), and the QR code encoder draws the same
     * codes as the locked one. Adds what it compared to $counts.
     *
     * @param array<string,int> $counts What was compared, by kind.
     * @return list<string>
     */
    public function problems(array &$counts = []): array
    {
        $build = $this->root . '/build/embed';
        if (!is_dir($build . '/package/vendor') || !is_file($build . '/vendor/autoload.php')) {
            return ['build/embed holds no build: run php tools/embed.php build (or tools/build.php build) first'];
        }
        $problems = $this->tokenProblems($counts);
        foreach ($this->qrDifferences($build . '/vendor/autoload.php', $build . '/package/vendor') as $difference) {
            $problems[] = "The embedded QR code encoder draws another code than the locked one: {$difference}";
        }
        $counts['QR codes'] = count($this->qrTexts());

        return $problems;
    }

    /**
     * Where the embedded libraries differ from the locked ones beyond the prefix and the patches
     * (above): none, when each file of theirs is the original's, token by token.
     *
     * The original of the SDK is sdk/ (PIN.json's files); those of the others, what Composer
     * installed of the lock in build/embed/vendor. PHP-Scoper prints each file again from its
     * syntax tree (its printer, php-parser's standard one, is its only one), which writes
     * parentheses, numbers and strings its own way; so each original is printed by that same
     * parser and printer first (the PHAR's own, for PHP 8.2 as tools/embed/scoper.inc.php has it),
     * after a check that the reprint has the original's syntax tree, and the embedded file is
     * compared with that reprint, token by token, spaces aside. The only differences allowed:
     *
     * - prefix: Sattleme\Payment\Vendor\ before a name (or a class name in a string) of the
     *   libraries' namespaces;
     * - qualified: a leading "\" on one of PHP's own constants (\true, \PHP_INT_MAX), which names
     *   the same constant;
     * - and each patch, where it is bound to: `final` removed, assert() statements removed (each
     *   one only reading, as the build checks), \ArrayObject as \ArrayIterator, `@f(...)` as
     *   Silence::call(static fn() => f(...)).
     *
     * Adds how many of each it found to $counts.
     *
     * @param array<string,int> $counts What was found, by kind.
     * @return list<string>
     */
    public function tokenProblems(array &$counts): array
    {
        $build = $this->root . '/build/embed';
        [$parse, $print, $dump] = $this->scoperPrinting();
        $problems = [];
        foreach (array_keys(self::PACKAGES) as $package) {
            $original = $package === 'sattleme/checkout' ? $this->root . '/sdk' : $build . '/vendor/' . $package;
            $embedded = $build . '/package/vendor/' . $package;
            $expected = array_values(array_filter(
                $this->filesUnder($original),
                static fn(string $file): bool => $file === 'LICENSE'
                    || (str_starts_with($file, 'src/') && str_ends_with($file, '.php'))
            ));
            $expected = array_values(array_diff($expected, array_keys(self::LEFT_OUT[$package] ?? [])));
            $actual = $this->filesUnder($embedded);
            foreach (array_diff($expected, $actual) as $file) {
                $problems[] = "{$package}: the package lacks {$file}";
            }
            foreach (array_diff($actual, $expected) as $file) {
                $problems[] = "{$package}: the package holds {$file}, which the original has not";
            }
            foreach (array_intersect($expected, $actual) as $file) {
                $before = $this->read($original . '/' . $file);
                $after = $this->read($embedded . '/' . $file);
                if ($file === 'LICENSE') {
                    if ($before !== $after) {
                        $problems[] = "{$package}: its LICENSE differs";
                    }
                    continue;
                }
                $counts['files'] = ($counts['files'] ?? 0) + 1;
                $reprint = $print($parse($before));
                if ($dump($parse($reprint)) !== $dump($parse($before))) {
                    $problems[] = "{$package}'s {$file}: PHP-Scoper's printer does not give back its syntax tree";
                    continue;
                }
                $problem = $this->tokenDifference(
                    $package,
                    $file,
                    $this->tokens($reprint),
                    $this->tokens($after),
                    $counts
                );
                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }
        }

        return $problems;
    }

    /**
     * Where $after differs from $before beyond what tokenProblems() allows: null when nowhere.
     *
     * $after is the embedded file's tokens, $before the original's, reprinted.
     *
     * @param string $package The library.
     * @param string $file The file.
     * @param list<\PhpToken> $before The original's tokens, spaces left out.
     * @param list<\PhpToken> $after The embedded file's tokens, spaces left out.
     * @param array<string,int> $counts What was found, by kind.
     * @return string|null
     */
    public function tokenDifference(
        string $package,
        string $file,
        array $before,
        array $after,
        array &$counts
    ): ?string {
        $where = "{$package}'s {$file}";
        $found = [
            'final' => 0, 'assert' => 0, 'ArrayObject' => 0, '@' => 0, 'prefix' => 0, 'qualified' => 0, 'same' => 0,
        ];
        $i = 0;
        $j = 0;
        $countBefore = count($before);
        $countAfter = count($after);
        while ($i < $countBefore || $j < $countAfter) {
            $a = $before[$i] ?? null;
            $b = $after[$j] ?? null;
            if ($a !== null && $a->id === T_FINAL && $b?->id !== T_FINAL) {
                $found['final']++;
                $i++;
                continue;
            }
            if ($a !== null && $this->isAssert($before, $i) && $b?->text !== $a->text) {
                try {
                    $i = $this->assertionEnd($before, $i, $where) + 1;
                } catch (\RuntimeException $e) {
                    return $e->getMessage();
                }
                $found['assert']++;
                continue;
            }
            if ($a !== null && $a->text === '@') {
                $call = ['::', 'call', '(', 'static', 'fn', '(', ')', '=>'];
                $written = array_map(static fn(\PhpToken $t): string => $t->text, array_slice($after, $j, 9));
                if ($written !== array_merge([self::SILENCE], $call)) {
                    return "{$where}, line {$a->line}: \"@\" is not written as " . self::SILENCE . '::call()';
                }
                $j += 9;
                $i++;
                // The call silenced, as the original's, then the closing parenthesis.
                $depth = 0;
                do {
                    $a = $before[$i] ?? null;
                    $b = $after[$j] ?? null;
                    $kind = $a === null || $b === null ? null : $this->sameToken($package, $file, $a, $b);
                    if ($kind === null || $kind === 'ArrayObject') {
                        return "{$where}, line {$a?->line}: the call \"@\" silenced is not the original's";
                    }
                    $found[$kind]++;
                    $depth += $a->text === '(' ? 1 : ($a->text === ')' ? -1 : 0);
                    $i++;
                    $j++;
                } while ($depth > 0 || ($before[$i - 1]->text !== ')'));
                if (($after[$j] ?? null)?->text !== ')') {
                    return "{$where}, line {$a->line}: Silence::call() is not closed after the call";
                }
                $j++;
                $found['@']++;
                continue;
            }
            if ($a === null || $b === null) {
                return "{$where}: the embedded file " . ($a === null ? 'goes on' : 'ends') . ' where the original '
                    . ($a === null ? 'ends' : 'goes on') . ' (line ' . ($a ?? $b)?->line . ')';
            }
            $kind = $this->sameToken($package, $file, $a, $b);
            if ($kind === null) {
                return "{$where}, line {$b->line}: {$a->text} became {$b->text}";
            }
            $found[$kind]++;
            $i++;
            $j++;
        }
        if ($found['ArrayObject'] !== (self::ARRAY_OBJECTS[$package][$file] ?? 0)) {
            return "{$where}: ArrayObject became ArrayIterator {$found['ArrayObject']} times, its patch is bound to "
                . (self::ARRAY_OBJECTS[$package][$file] ?? 0);
        }
        if ($found['@'] !== (self::SILENCED[$package][$file] ?? 0)) {
            return "{$where}: \"@\" became Silence::call() {$found['@']} times, its patch is bound to "
                . (self::SILENCED[$package][$file] ?? 0);
        }
        foreach ($found as $kind => $times) {
            $counts[$kind] = ($counts[$kind] ?? 0) + $times;
        }

        return null;
    }

    /**
     * How $after (embedded) is $before (original), or null when it is another token.
     *
     * "same", "prefix", "qualified" or "ArrayObject".
     *
     * @param string $package The library.
     * @param string $file The file.
     * @param \PhpToken $before The original's token.
     * @param \PhpToken $after The embedded one.
     * @return string|null
     */
    private function sameToken(string $package, string $file, \PhpToken $before, \PhpToken $after): ?string
    {
        if ($before->id === $after->id && $before->text === $after->text) {
            return 'same';
        }
        $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
        if (in_array($before->id, $names, true) && in_array($after->id, $names, true)) {
            $leading = str_starts_with($after->text, '\\') ? '\\' : '';
            $rest = substr($after->text, strlen($leading));
            if (str_starts_with($rest, self::PREFIX) && $leading . substr($rest, strlen(self::PREFIX)) === $before->text
                && $this->isEmbeddedName(substr($rest, strlen(self::PREFIX)))
            ) {
                return 'prefix';
            }
            if ($before->id === T_STRING && $after->id === T_NAME_FULLY_QUALIFIED
                && $after->text === '\\' . $before->text
                && $this->isPhpConstant($before->text)
            ) {
                return 'qualified';
            }
            if ($before->text === '\\ArrayObject' && $after->text === '\\ArrayIterator'
                && isset(self::ARRAY_OBJECTS[$package][$file])
            ) {
                return 'ArrayObject';
            }

            return null;
        }
        if ($before->id === T_CONSTANT_ENCAPSED_STRING && $after->id === T_CONSTANT_ENCAPSED_STRING) {
            $escaped = str_replace('\\', '\\\\', self::PREFIX);
            foreach ([self::PREFIX, $escaped] as $prefix) {
                $parts = explode($prefix, $after->text);
                if (count($parts) > 1 && implode('', $parts) === $before->text) {
                    foreach (array_slice($parts, 1) as $part) {
                        if (!$this->isEmbeddedName(str_replace('\\\\', '\\', $part))) {
                            return null;
                        }
                    }

                    return 'prefix';
                }
            }
        }

        return null;
    }

    /**
     * Whether $name (unprefixed) begins with one of the libraries' namespaces.
     *
     * @param string $name The name.
     * @return bool
     */
    private function isEmbeddedName(string $name): bool
    {
        foreach (self::PACKAGES as $namespace) {
            if (str_starts_with($name, $namespace) || $name === rtrim($namespace, '\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $name is one of PHP's own constants: \true is true, wherever it is written.
     *
     * @param string $name The constant.
     * @return bool
     */
    private function isPhpConstant(string $name): bool
    {
        if (in_array(strtolower($name), ['true', 'false', 'null'], true)) {
            return true;
        }
        $constants = get_defined_constants(true);
        unset($constants['user']);
        foreach ($constants as $group) {
            if (array_key_exists($name, $group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * $code's tokens, spaces left out.
     *
     * @param string $code The code.
     * @return list<\PhpToken>
     */
    private function tokens(string $code): array
    {
        return array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn(\PhpToken $token): bool => $token->id !== T_WHITESPACE
        ));
    }

    /**
     * PHP-Scoper's own parsing and printing, for PHP 8.2 (tools/embed/scoper.inc.php), from its
     * PHAR: a function that parses code into its syntax tree, one that prints a tree as PHP-Scoper
     * prints the files it writes, and one that dumps a tree without its positions and comments.
     *
     * @return array{\Closure(string): array<mixed>, \Closure(array<mixed>): string, \Closure(array<mixed>): string}
     */
    private function scoperPrinting(): array
    {
        $phar = 'phar://' . $this->scoper();
        // The PHAR's classes are prefixed with a name of its own build: its container's namespace.
        $container = $this->read($phar . '/src/Container.php');
        if (preg_match('/^namespace (_HumbugBox[0-9a-f]+)\\\\Humbug\\\\PhpScoper;$/m', $container, $box) !== 1) {
            throw new \RuntimeException('PHP-Scoper\'s PHAR has no container of its own');
        }
        require_once $phar . '/vendor/autoload.php';
        $version = $this->call($box[1] . '\\PhpParser\\PhpVersion', 'fromString', ['8.2']);
        $factory = $box[1] . '\\Humbug\\PhpScoper\\PhpParser\\';
        $parser = $this->call($this->make($factory . 'Parser\\StandardParserFactory'), 'createParser', [$version]);
        $printer = $this->call($this->make($factory . 'Printer\\StandardPrinterFactory'), 'createPrinter', [$version]);
        if (!is_object($parser) || !is_object($printer)) {
            throw new \RuntimeException('PHP-Scoper\'s PHAR made no parser or no printer');
        }
        $dumper = $this->make(
            $box[1] . '\\PhpParser\\NodeDumper',
            [['dumpComments' => false, 'dumpPositions' => false]]
        );
        $parse = function (string $code) use ($parser): array {
            $statements = $this->call($parser, 'parse', [$code]);
            if (!is_array($statements)) {
                throw new \RuntimeException('PHP-Scoper\'s parser parses nothing');
            }

            return $statements;
        };
        $print = function (array $statements) use ($parser, $printer): string {
            $tokens = $this->call($parser, 'getTokens');
            $printed = $this->call($printer, 'print', [$statements, $statements, $tokens]);

            return is_string($printed) ? $printed : '';
        };
        $dump = function (array $statements) use ($dumper): string {
            $dumped = $this->call($dumper, 'dump', [$statements]);

            return is_string($dumped) ? $dumped : '';
        };

        return [$parse, $print, $dump];
    }

    /**
     * A new $class, from PHP-Scoper's PHAR.
     *
     * @param string $class The class.
     * @param list<mixed> $arguments Its constructor's arguments.
     * @return object
     */
    private function make(string $class, array $arguments = []): object
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("PHP-Scoper's PHAR has no {$class}");
        }

        return new $class(...$arguments);
    }

    /**
     * $method of $target (an object, or a class for a static method) called with $arguments.
     *
     * @param object|string $target The object or the class.
     * @param string $method The method.
     * @param list<mixed> $arguments Its arguments.
     * @return mixed
     */
    private function call(object|string $target, string $method, array $arguments = []): mixed
    {
        $callable = [$target, $method];
        if (!is_callable($callable)) {
            $class = is_string($target) ? $target : get_class($target);
            throw new \RuntimeException("PHP-Scoper's PHAR has no {$class}::{$method}()");
        }

        return $callable(...$arguments);
    }

    /**
     * References to the embedded libraries' namespaces without the prefix, in the package's code.
     *
     * None, when the module and the libraries only use the copies the package holds. In PHP and
     * templates, the names and the strings' values (never the comments); in XML (Magento's
     * configuration names classes), the text. A namespace is referred to by a name that is it or
     * begins with it, or by a string or text naming a class of it ("BaconQrCode\\Writer", never
     * "Creator: BaconQrCode").
     *
     * @param array<string,string> $files The package's files: name => content.
     * @return list<string>
     */
    public function unprefixedReferences(array $files): array
    {
        $namespaces = [];
        foreach (self::PACKAGES as $namespace) {
            $namespaces[] = preg_quote(rtrim($namespace, '\\'), '/');
        }
        $theirs = '(?:' . implode('|', $namespaces) . ')';
        // A name that is a namespace of theirs, or begins with one.
        $inName = '/^\\\\?' . $theirs . '(?:\\\\|$)/';
        // A class of theirs named in a text: after neither a name's character nor a backslash (a
        // prefixed name has "Vendor\" before it).
        $inText = '/(?<![A-Za-z0-9_\\\\])' . $theirs . '\\\\[A-Za-z_]/';
        $found = [];
        foreach ($files as $name => $content) {
            if (str_ends_with($name, '.xml')) {
                $text = (string) preg_replace('/<!--.*?-->/s', '', $content);
                if (preg_match_all($inText, $text, $matches) > 0) {
                    foreach ($matches[0] as $reference) {
                        $found[] = "{$name}: {$reference}";
                    }
                }
                continue;
            }
            if (!str_ends_with($name, '.php') && !str_ends_with($name, '.phtml')) {
                continue;
            }
            foreach (\PhpToken::tokenize($content) as $token) {
                $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
                if (in_array($token->id, $names, true) && preg_match($inName, $token->text) === 1) {
                    $found[] = "{$name}, line {$token->line}: {$token->text}";
                } elseif ($token->id === T_CONSTANT_ENCAPSED_STRING
                    && preg_match($inText, $this->stringValue($token->text), $match) === 1
                ) {
                    $found[] = "{$name}, line {$token->line}: {$match[0]}";
                }
            }
        }

        return $found;
    }

    /**
     * The value of a string literal of PHP's: '…' or "…" (without variables).
     *
     * @param string $literal The literal.
     * @return string
     */
    private function stringValue(string $literal): string
    {
        $inner = substr($literal, 1, -1);

        return $literal[0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $inner) : stripcslashes($inner);
    }

    /**
     * $code, a file of $package as PHP-Scoper wrote it, patched (above): `final` removed, assert()
     * statements removed, ArrayObject as ArrayIterator, "@" as a call of Silence. Adds what it did
     * to $counts.
     *
     * @param string $package The library.
     * @param string $file Its file, relative to it.
     * @param string $code The file's code.
     * @param array<string,int> $counts The patches made so far.
     * @return string
     * @throws \RuntimeException When the code holds what no patch was proven for.
     */
    public function patch(string $package, string $file, string $code, array &$counts): string
    {
        $tokens = array_values(\PhpToken::tokenize($code));
        // The code written, token by token: [its text, whether it is space].
        $out = [];
        $arrayObjects = 0;
        $silenced = 0;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->id === T_FINAL) {
                // The keyword and the space after it.
                if (($tokens[$i + 1] ?? null)?->id === T_WHITESPACE) {
                    $i++;
                }
                $counts['final'] = ($counts['final'] ?? 0) + 1;
                continue;
            }
            if ($this->isAssert($tokens, $i)) {
                $i = $this->assertionEnd($tokens, $i, "{$package}'s {$file}");
                // The statement, and its line: the space before it up to its line's start.
                $last = count($out) - 1;
                if ($last >= 0 && $out[$last][1] && str_contains($out[$last][0], "\n")) {
                    $out[$last][0] = substr($out[$last][0], 0, (int) strrpos($out[$last][0], "\n"));
                }
                $counts['assert'] = ($counts['assert'] ?? 0) + 1;
                continue;
            }
            if (in_array($token->id, [T_NAME_FULLY_QUALIFIED, T_STRING], true)
                && ltrim($token->text, '\\') === 'ArrayObject'
            ) {
                $out[] = ['\\ArrayIterator', false];
                $arrayObjects++;
                continue;
            }
            if ($token->text === '@') {
                [$call, $i] = $this->silencedCall($tokens, $i, "{$package}'s {$file}");
                $out[] = [self::SILENCE . '::call(static fn() => ' . $call . ')', false];
                $silenced++;
                continue;
            }
            $out[] = [$token->text, $token->id === T_WHITESPACE];
        }
        if ($arrayObjects !== (self::ARRAY_OBJECTS[$package][$file] ?? 0)) {
            throw new \RuntimeException(
                "{$package}'s {$file} uses ArrayObject {$arrayObjects} times: its patch is proven for "
                . (self::ARRAY_OBJECTS[$package][$file] ?? 0)
            );
        }
        if ($silenced !== (self::SILENCED[$package][$file] ?? 0)) {
            throw new \RuntimeException(
                "{$package}'s {$file} uses \"@\" {$silenced} times: its patch is proven for "
                . (self::SILENCED[$package][$file] ?? 0)
            );
        }
        $counts['ArrayObject'] = ($counts['ArrayObject'] ?? 0) + $arrayObjects;
        $counts['@'] = ($counts['@'] ?? 0) + $silenced;

        return implode('', array_column($out, 0));
    }

    /**
     * Where QR codes the embedded encoder (under $vendor) draws differ from those the locked one
     * (its Composer autoloader $autoload) draws: none, when the same texts give the same modules.
     *
     * Texts of every mode (numeric, alphanumeric, bytes in ISO-8859-1 and UTF-8, kanji in
     * Shift_JIS) and every error correction level, from one character to the largest codes, and
     * texts the encoder refuses (refused alike).
     *
     * @param string $autoload The locked libraries' autoloader.
     * @param string $vendor The embedded libraries' vendor/.
     * @return list<string>
     */
    public function qrDifferences(string $autoload, string $vendor): array
    {
        require_once $autoload;
        $this->autoloadEmbedded($vendor);
        $levels = ['L', 'M', 'Q', 'H'];
        $differences = [];
        foreach ($this->qrTexts() as $index => [$text, $encoding]) {
            $level = $levels[$index % 4];
            try {
                $code = \BaconQrCode\Encoder\Encoder::encode(
                    $text,
                    \BaconQrCode\Common\ErrorCorrectionLevel::valueOf($level),
                    $encoding
                );
                $matrix = $code->getMatrix();
                $locked = $code->getVersion()->getVersionNumber() . '/' . $code->getMaskPattern() . '/'
                    . $code->getMode()->name() . ':'
                    . $this->modules($matrix->getWidth(), $matrix->getHeight(), $matrix->get(...));
            } catch (\Throwable $e) {
                $locked = 'refused: ' . substr((string) strrchr(get_class($e), '\\'), 1) . ': ' . $e->getMessage();
            }
            try {
                $code = \Sattleme\Payment\Vendor\BaconQrCode\Encoder\Encoder::encode(
                    $text,
                    \Sattleme\Payment\Vendor\BaconQrCode\Common\ErrorCorrectionLevel::valueOf($level),
                    $encoding
                );
                $matrix = $code->getMatrix();
                $embedded = $code->getVersion()->getVersionNumber() . '/' . $code->getMaskPattern() . '/'
                    . $code->getMode()->name() . ':'
                    . $this->modules($matrix->getWidth(), $matrix->getHeight(), $matrix->get(...));
            } catch (\Throwable $e) {
                $embedded = 'refused: ' . substr((string) strrchr(get_class($e), '\\'), 1) . ': ' . $e->getMessage();
            }
            if ($locked !== $embedded) {
                $differences[] = 'text ' . $index . ' (' . strlen($text) . " bytes, {$encoding}, {$level})";
            }
        }

        return $differences;
    }

    /**
     * A code's modules, row by row: "1" dark, "0" light.
     *
     * @param int $width Its width.
     * @param int $height Its height.
     * @param callable $module Its module at x, y.
     * @phpstan-param callable(int,int): int $module
     * @return string
     */
    private function modules(int $width, int $height, callable $module): string
    {
        $drawn = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $drawn .= (string) $module($x, $y);
            }
            $drawn .= "\n";
        }

        return $drawn;
    }

    /**
     * The texts qrDifferences() encodes, with their encodings.
     *
     * @return list<array{string, string}>
     */
    public function qrTexts(): array
    {
        $texts = [];
        $seed = 'sattle.me';
        // A payment URI as the SDK makes them (BIP-21 with a Lightning invoice), and others.
        $texts[] = [
            'bitcoin:bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq?amount=0.00049&lightning=lnbc490u1p5',
            'ISO-8859-1',
        ];
        $texts[] = ['lightning:LNBC490U1P5XYZ', 'ISO-8859-1'];
        $texts[] = ['BITCOIN:BC1QAR0SRRR7XFKVY5L643LYDNW9RE59GTZZWF5MDQ?AMOUNT=0.00049', 'ISO-8859-1'];
        foreach ([1, 2, 7, 17, 41, 100, 300, 700, 1200, 1800, 2300, 2900] as $length) {
            $seed = hash('sha256', $seed);
            $digits = substr(str_repeat((string) hexdec(substr($seed, 0, 12)), 300), 0, min($length * 2, 7000));
            $texts[] = [$digits, 'ISO-8859-1'];
            $alphanumeric = substr(str_repeat(strtoupper(substr($seed, 0, 20)) . ' $%*+-./:', 300), 0, $length);
            $texts[] = [$alphanumeric, 'ISO-8859-1'];
            $bytes = '';
            while (strlen($bytes) < $length) {
                $seed = hash('sha256', $seed);
                $bytes .= preg_replace('/[^\x20-\x7e]/', '', base64_encode((string) hex2bin($seed)));
            }
            $texts[] = [substr($bytes, 0, $length), 'ISO-8859-1'];
            $texts[] = [substr($bytes, 0, $length), 'UTF-8'];
            $texts[] = [str_repeat('é', max(1, intdiv($length, 2))), 'ISO-8859-1'];
            $texts[] = [str_repeat('日本語', max(1, intdiv($length, 9))), 'SHIFT-JIS'];
        }
        // Refused alike: a character ISO-8859-1 lacks, a text too long for any code.
        $texts[] = ['lightning:€', 'ISO-8859-1'];
        $texts[] = [str_repeat('x', 5000), 'ISO-8859-1'];

        return $texts;
    }

    /**
     * Registers an autoloader of the embedded libraries under $vendor.
     *
     * As the package's composer.json maps them, with the module's Embedded\ classes they call.
     *
     * @param string $vendor The embedded libraries' vendor/.
     * @return void
     */
    public function autoloadEmbedded(string $vendor): void
    {
        $root = $this->root;
        spl_autoload_register(static function (string $class) use ($vendor, $root): void {
            $paths = ['Sattleme\\Payment\\Embedded\\' => $root . '/Embedded/'];
            foreach (self::PACKAGES as $package => $namespace) {
                $paths[self::PREFIX . $namespace] = $vendor . '/' . $package . '/src/';
            }
            foreach ($paths as $prefix => $dir) {
                if (str_starts_with($class, $prefix)) {
                    $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }

                    return;
                }
            }
        });
    }

    /**
     * Whether $tokens[$i] begins an assert() call.
     *
     * @param list<\PhpToken> $tokens The file's tokens.
     * @param int $i The token.
     * @return bool
     */
    private function isAssert(array $tokens, int $i): bool
    {
        $text = $tokens[$i]->text;
        if (!($tokens[$i]->id === T_NAME_FULLY_QUALIFIED && strtolower($text) === '\\assert')
            && !($tokens[$i]->id === T_STRING && strtolower($text) === 'assert')
        ) {
            return false;
        }
        $previous = $this->significant($tokens, $i, -1);
        if ($previous !== null && in_array($tokens[$previous]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true)
        ) {
            return false;
        }
        $next = $this->significant($tokens, $i, 1);

        return $next !== null && $tokens[$next]->text === '(';
    }

    /**
     * The last token of the assert() statement that begins at $tokens[$i]: its ";".
     *
     * @param list<\PhpToken> $tokens The file's tokens.
     * @param int $i The assert.
     * @param string $where The file, for the error.
     * @return int
     * @throws \RuntimeException When it is not a statement of its own, or does more than read.
     */
    private function assertionEnd(array $tokens, int $i, string $where): int
    {
        $line = $tokens[$i]->line;
        $previous = $this->significant($tokens, $i, -1);
        if ($previous === null || !in_array($tokens[$previous]->text, [';', '{', '}'], true)) {
            throw new \RuntimeException("{$where}, line {$line}: an assert() that is not a statement of its own");
        }
        $open = (int) $this->significant($tokens, $i, 1);
        $depth = 0;
        $count = count($tokens);
        $j = $open;
        for (; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif (!in_array($tokens[$j]->id, self::ASSERTION_TOKENS, true) && !in_array($text, ['!', ','], true)) {
                throw new \RuntimeException("{$where}, line {$line}: an assert() that does more than read ({$text})");
            }
            if (in_array($tokens[$j]->id, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                $after = $this->significant($tokens, $j, 1);
                $before = $this->significant($tokens, $j, -1);
                $isCall = $after !== null && $tokens[$after]->text === '(';
                $isMember = $before !== null
                    && in_array($tokens[$before]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
                $isTypeCheck = in_array(strtolower(ltrim($tokens[$j]->text, '\\')), self::TYPE_CHECKS, true);
                if ($isCall && ($isMember || !$isTypeCheck)) {
                    throw new \RuntimeException(
                        "{$where}, line {$line}: an assert() that calls {$tokens[$j]->text}(), not a type check"
                    );
                }
            }
        }
        $end = $this->significant($tokens, $j, 1);
        if ($depth !== 0 || $end === null || $tokens[$end]->text !== ';') {
            throw new \RuntimeException("{$where}, line {$line}: an assert() that is not a statement of its own");
        }

        return $end;
    }

    /**
     * The call "@" silences at $tokens[$i], and its last token.
     *
     * The call (a function's name and its arguments) as code.
     *
     * @param list<\PhpToken> $tokens The file's tokens.
     * @param int $i The "@".
     * @param string $where The file, for the error.
     * @return array{string, int}
     * @throws \RuntimeException When "@" silences anything but a function's call.
     */
    private function silencedCall(array $tokens, int $i, string $where): array
    {
        $name = $i + 1;
        $open = $name + 1;
        if (!in_array($tokens[$name]->id ?? null, [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            || ($tokens[$open]->text ?? '') !== '('
        ) {
            throw new \RuntimeException("{$where}, line {$tokens[$i]->line}: \"@\" on what is not a function's call");
        }
        $depth = 0;
        $code = $tokens[$name]->text;
        $count = count($tokens);
        for ($j = $open; $j < $count; $j++) {
            $code .= $tokens[$j]->text;
            if ($tokens[$j]->text === '@') {
                throw new \RuntimeException("{$where}, line {$tokens[$i]->line}: \"@\" within \"@\"");
            }
            if ($tokens[$j]->text === '(') {
                $depth++;
            } elseif ($tokens[$j]->text === ')' && --$depth === 0) {
                return [$code, $j];
            }
        }
        throw new \RuntimeException("{$where}, line {$tokens[$i]->line}: an unbalanced call");
    }

    /**
     * The token before or after $i that is neither space nor comment.
     *
     * Its index, or null; $step is -1 for the one before, 1 for the one after.
     *
     * @param list<\PhpToken> $tokens The tokens.
     * @param int $i The token.
     * @param int $step -1 or 1.
     * @return int|null
     */
    private function significant(array $tokens, int $i, int $step): ?int
    {
        for ($j = $i + $step; isset($tokens[$j]); $j += $step) {
            if (!$tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /**
     * Fails unless each file of $package bound to a patch (ArrayObject, "@") is there.
     *
     * @param string $package The library.
     * @param string $target Where it was written.
     * @return void
     */
    private function checkPatchedAsBound(string $package, string $target): void
    {
        foreach ([self::ARRAY_OBJECTS, self::SILENCED] as $bound) {
            foreach (array_keys($bound[$package] ?? []) as $file) {
                if (!is_file($target . '/' . $file)) {
                    throw new \RuntimeException("{$package} has no {$file} any more: its patch is proven for it");
                }
            }
        }
    }

    /**
     * Fails when a file under $vendor refers to a class left out of it.
     *
     * @param string $vendor The embedded libraries' vendor/.
     * @return void
     */
    private function checkNothingRefersToWhatIsLeftOut(string $vendor): void
    {
        foreach (self::LEFT_OUT as $files) {
            foreach ($files as $class) {
                foreach ($this->filesUnder($vendor) as $file) {
                    if (preg_match('/\b' . preg_quote($class, '/') . '\b/', $this->read($vendor . '/' . $file)) === 1) {
                        throw new \RuntimeException("{$file} refers to {$class}, which the package leaves out");
                    }
                }
            }
        }
    }

    /**
     * A class file each library has: the check that PHP-Scoper wrote its sources.
     *
     * @param string $package The library.
     * @return string
     */
    private function firstClass(string $package): string
    {
        return match ($package) {
            'sattleme/checkout' => 'Client.php',
            'bacon/bacon-qr-code' => 'Encoder/Encoder.php',
            default => 'AbstractEnum.php',
        };
    }

    /**
     * PHP-Scoper's PHAR, in build/tools/: downloaded once, its SHA-256 checked each time.
     *
     * @return string
     */
    private function scoper(): string
    {
        $phar = $this->root . '/build/tools/php-scoper-' . basename(dirname(self::SCOPER_URL)) . '.phar';
        if (!is_file($phar) || hash_file('sha256', $phar) !== self::SCOPER_SHA256) {
            $this->write($phar . '.download', '');
            $this->run(['curl', '-fsSL', '--retry', '3', '-o', $phar . '.download', self::SCOPER_URL]);
            if (hash_file('sha256', $phar . '.download') !== self::SCOPER_SHA256) {
                unlink($phar . '.download');
                throw new \RuntimeException('PHP-Scoper\'s PHAR is not the one of SHA-256 ' . self::SCOPER_SHA256);
            }
            rename($phar . '.download', $phar);
        }

        return $phar;
    }

    /**
     * Runs Composer (COMPOSER_BIN, or `composer`) in the repository.
     *
     * @param list<string> $args Its arguments.
     * @param array<string,string> $env More environment.
     * @return void
     */
    private function composer(array $args, array $env = []): void
    {
        $composer = getenv('COMPOSER_BIN');
        $this->run(array_merge([is_string($composer) && $composer !== '' ? $composer : 'composer'], $args), $env);
    }

    /**
     * Runs $command in the repository; fails unless it succeeds.
     *
     * Its output goes to this one's error output.
     *
     * @param list<string> $command The command.
     * @param array<string,string> $env More environment.
     * @return void
     */
    private function run(array $command, array $env = []): void
    {
        $environment = getenv();
        foreach ($env as $name => $value) {
            $environment[$name] = $value;
        }
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR];
        $process = proc_open($command, $descriptors, $pipes, $this->root, $environment);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new \RuntimeException(implode(' ', $command) . ' failed');
        }
    }

    /**
     * Every file under $dir, relative to it, sorted.
     *
     * @param string $dir The directory.
     * @return list<string>
     */
    public function filesUnder(string $dir): array
    {
        $files = [];
        if (is_dir($dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                }
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The content of $path.
     *
     * @param string $path The file.
     * @return string
     */
    private function read(string $path): string
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new \RuntimeException("Cannot read {$path}");
        }

        return $content;
    }

    /**
     * Writes $content to $path, its directory made.
     *
     * @param string $path The file.
     * @param string $content Its content.
     * @return void
     */
    private function write(string $path, string $content): void
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Cannot make ' . dirname($path));
        }
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Cannot write {$path}");
        }
    }

    /**
     * Removes $dir and everything under it.
     *
     * @param string $dir The directory.
     * @return void
     */
    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}

// Loaded by the tests and tools/build.php for its class alone.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $embedded = new Embedded(dirname(__DIR__));
    $command = $argv[1] ?? '';
    try {
        if ($command === 'build' && count($argv) === 2) {
            [$dir, $counts] = $embedded->build();
            $made = [];
            foreach ($counts as $patch => $times) {
                $made[] = "{$patch}: {$times}";
            }
            echo "The embedded libraries are in {$dir}/vendor (patches: " . implode(', ', $made) . ")\n";
            exit(0);
        }
        if ($command === 'check' && count($argv) === 2) {
            $counts = [];
            $problems = $embedded->problems($counts);
            foreach ($problems as $problem) {
                fwrite(STDERR, $problem . "\n");
            }
            if ($problems === []) {
                echo "The embedded libraries are the locked ones: {$counts['files']} files compared token by token"
                    . " ({$counts['same']} the same, {$counts['prefix']} prefixed, {$counts['qualified']} PHP constants"
                    . " written with a leading \\), and patched only as bound: {$counts['final']} final,"
                    . " {$counts['assert']} assert(), {$counts['ArrayObject']} ArrayObject, {$counts['@']} \"@\";"
                    . " {$counts['QR codes']} texts drawn as the same QR codes\n";
            }
            exit($problems === [] ? 0 : 1);
        }
    } catch (\RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    fwrite(STDERR, "Usage: php tools/embed.php build | check\n");
    exit(2);
}
