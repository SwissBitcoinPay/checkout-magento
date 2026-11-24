<?php
/**
 * The module's phrases and their translations (i18n/<locale>.csv, Magento's format).
 *
 *   php tools/i18n.php phrases   The phrases of the sources, one per line.
 *   php tools/i18n.php check     Whether each dictionary translates exactly the phrases of the
 *                                sources, each with the same placeholders (%1, %2…), and en_US.csv
 *                                is the sources' (Test/Unit/Tools/TranslationsTest.php runs it).
 *   php tools/i18n.php en        Writes i18n/en_US.csv from the sources.
 *
 * The phrases are those Magento's own collector (bin/magento i18n:collect-phrases) finds: the
 * literal of each __() of the PHP and the templates (concatenated literals joined), the texts of
 * the XML elements a translate attribute names (system.xml's labels and comments), and the $t()
 * and i18n bindings of the scripts and the checkout's templates. It needs PHP only.
 */

declare(strict_types=1);

namespace Sattleme\Payment\Tools;

/**
 * Collects and checks the phrases.
 */
class Translations
{
    /**
     * The locales the module is translated into: English (the sources), German, French and
     * Italian, in their countries and in Switzerland (Magento reads a locale's file only).
     */
    public const LOCALES = ['de_CH', 'de_DE', 'en_US', 'fr_CH', 'fr_FR', 'it_CH', 'it_IT'];

    /**
     * The phrases written the same in every language (names, figures).
     */
    private const UNCHANGED = ['1 BTC = %1 %2 (%3)', 'Bitcoin (sattle.me)'];

    /**
     * The directories of the module's sources.
     */
    private const SOURCES = [
        'Block', 'Console', 'Controller', 'Cron', 'Gateway', 'Model', 'Observer', 'Plugin', 'Sdk', 'ViewModel', 'etc',
        'view',
    ];

    /**
     * Reads the module at $root.
     *
     * @param string $root The module's directory.
     */
    public function __construct(private readonly string $root)
    {
    }

    /**
     * The phrases of the sources, sorted, each once.
     *
     * @return list<string>
     */
    public function phrases(): array
    {
        $phrases = [];
        foreach (self::SOURCES as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $text = (string) file_get_contents($path);
                $found = match ($file->getExtension()) {
                    'php', 'phtml' => $this->ofPhp($text),
                    'xml' => $this->ofXml($text),
                    'js', 'html' => $this->ofScript($text),
                    default => [],
                };
                foreach ($found as $phrase) {
                    $phrases[$phrase] = true;
                }
            }
        }
        $list = array_map('strval', array_keys($phrases));
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * The phrases of PHP $code: the literals of its __() calls.
     *
     * @param string $code The code.
     * @return list<string>
     */
    public function ofPhp(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn($token): bool => !is_array($token)
                || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $phrases = [];
        foreach ($tokens as $i => $token) {
            $isCall = is_array($token) && $token[0] === T_STRING && $token[1] === '__';
            if (!$isCall || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            $phrase = '';
            $j = $i + 2;
            while (true) {
                $part = $tokens[$j] ?? null;
                if (!is_array($part) || $part[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    break;
                }
                $phrase .= $this->literal($part[1]);
                $next = $tokens[$j + 1] ?? null;
                if ($next !== '.') {
                    break;
                }
                $j += 2;
            }
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }

        return $phrases;
    }

    /**
     * The phrases of XML $xml: the texts of the elements its translate attributes name.
     *
     * @param string $xml The XML.
     * @return list<string>
     */
    public function ofXml(string $xml): array
    {
        $document = new \DOMDocument();
        $errors = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        if (!$loaded) {
            return [];
        }
        $phrases = [];
        $xpath = new \DOMXPath($document);
        $elements = $xpath->query('//*[@translate]');
        foreach ($elements === false ? [] : $elements as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }
            $names = preg_split('/\s+/', trim($element->getAttribute('translate')));
            foreach ($names === false ? [] : $names as $name) {
                foreach ($element->childNodes as $child) {
                    $isText = $child instanceof \DOMElement && $child->nodeName === $name;
                    if ($isText && trim($child->textContent) !== '') {
                        $phrases[] = trim($child->textContent);
                    }
                }
            }
        }

        return $phrases;
    }

    /**
     * The phrases of a script or a knockout template: its $t('…') calls and i18n: '…' bindings.
     *
     * @param string $text The script.
     * @return list<string>
     */
    public function ofScript(string $text): array
    {
        preg_match_all('/(?:\$t\(\s*|i18n:\s*)\'((?:[^\'\\\\]|\\\\.)*)\'/', $text, $matches);

        return array_map(static fn(string $phrase): string => stripcslashes($phrase), $matches[1]);
    }

    /**
     * The dictionary of $locale: phrase => translation.
     *
     * @param string $locale The locale.
     * @return array<string,string>
     */
    public function dictionary(string $locale): array
    {
        $path = $this->root . '/i18n/' . $locale . '.csv';
        $dictionary = [];
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            return $dictionary;
        }
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($row) >= 2 && is_string($row[0]) && is_string($row[1])) {
                $dictionary[$row[0]] = $row[1];
            }
        }
        fclose($handle);

        return $dictionary;
    }

    /**
     * What is wrong with the dictionaries: empty when each translates exactly the sources' phrases.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $phrases = $this->phrases();
        $problems = [];
        foreach (self::LOCALES as $locale) {
            $dictionary = $this->dictionary($locale);
            if ($dictionary === []) {
                $problems[] = "i18n/{$locale}.csv is missing or empty";
                continue;
            }
            foreach ($phrases as $phrase) {
                if (!isset($dictionary[$phrase])) {
                    $problems[] = "{$locale}: no translation of \"{$phrase}\"";
                } elseif ($this->placeholders($phrase) !== $this->placeholders($dictionary[$phrase])) {
                    $problems[] = "{$locale}: the placeholders of \"{$phrase}\" differ";
                } elseif ($locale !== 'en_US'
                    && $dictionary[$phrase] === $phrase
                    && strlen($phrase) > 12
                    && !in_array($phrase, self::UNCHANGED, true)
                ) {
                    $problems[] = "{$locale}: \"{$phrase}\" is not translated";
                } elseif (str_starts_with($locale, 'de_CH') && str_contains($dictionary[$phrase], 'ß')) {
                    $problems[] = "{$locale}: German in Switzerland writes ss, never ß: \"{$dictionary[$phrase]}\"";
                }
            }
            foreach (array_keys($dictionary) as $phrase) {
                if (!in_array($phrase, $phrases, true)) {
                    $problems[] = "{$locale}: \"{$phrase}\" is no phrase of the sources";
                }
            }
            $listed = array_values(array_unique(array_map('strval', array_keys($dictionary))));
            if ($locale === 'en_US' && $listed !== $phrases) {
                $problems[] = 'en_US.csv is not the sources\' phrases, sorted (php tools/i18n.php en)';
            }
        }

        return $problems;
    }

    /**
     * Writes i18n/en_US.csv from the sources.
     *
     * @return void
     */
    public function writeEnglish(): void
    {
        $handle = fopen($this->root . '/i18n/en_US.csv', 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write i18n/en_US.csv');
        }
        foreach ($this->phrases() as $phrase) {
            fputcsv($handle, [$phrase, $phrase], ',', '"', '\\');
        }
        fclose($handle);
    }

    /**
     * The placeholders of $text (%1, %2…), sorted.
     *
     * @param string $text The text.
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/%\d+/', $text, $matches);
        $found = $matches[0];
        sort($found, SORT_STRING);

        return $found;
    }

    /**
     * The value of a PHP string literal (single- or double-quoted, without interpolation).
     *
     * @param string $literal The literal, with its quotes.
     * @return string
     */
    private function literal(string $literal): string
    {
        $quote = $literal[0];
        $body = substr($literal, 1, -1);

        return $quote === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body) : stripcslashes($body);
    }
}

// Loaded by the tests for its class alone.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $translations = new Translations(dirname(__DIR__));
    $command = $argv[1] ?? '';
    if ($command === 'phrases') {
        echo implode("\n", $translations->phrases()), "\n";
        exit(0);
    }
    if ($command === 'en') {
        $translations->writeEnglish();
        exit(0);
    }
    if ($command === 'check') {
        $problems = $translations->problems();
        foreach ($problems as $problem) {
            fwrite(STDERR, $problem . "\n");
        }
        if ($problems === []) {
            echo count($translations->phrases()), ' phrases, translated in ',
                implode(', ', Translations::LOCALES), "\n";
        }
        exit($problems === [] ? 0 : 1);
    }
    fwrite(STDERR, "Usage: php tools/i18n.php phrases | check | en\n");
    exit(2);
}
