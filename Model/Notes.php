<?php

declare(strict_types=1);

namespace Sattleme\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\File\Csv;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Phrase;
use Magento\Store\Model\ScopeInterface;

/**
 * The module's notes on an order (its history, read by the merchant), in the language of the
 * order's store, whichever process writes them: the customer's request, the cron, the admin.
 *
 * Magento's own translation follows the current area and store (and the cron loads none): a note
 * written by the cron would be in English, one written by the page in the customer's store's
 * language. The module translates its notes itself, from its own dictionaries (i18n/*.csv), for
 * the store's locale (general/locale/code), without touching Magento's global translation state.
 */
class Notes
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $dictionaries = [];

    /**
     * @param ScopeConfigInterface $scopeConfig Magento's configuration.
     * @param ComponentRegistrarInterface $components Where the module is.
     * @param Csv $csv Magento's CSV reader.
     * @param File $files Magento's file system driver.
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ComponentRegistrarInterface $components,
        private readonly Csv $csv,
        private readonly File $files
    ) {
    }

    /**
     * $phrase in the language of store $storeId, its arguments in place.
     *
     * @param int $storeId The store.
     * @param Phrase $phrase The phrase (the module's).
     * @return string
     */
    public function render(int $storeId, Phrase $phrase): string
    {
        $text = $phrase->getText();
        $translated = $this->dictionary($this->locale($storeId))[$text] ?? $text;
        $arguments = [];
        foreach ($phrase->getArguments() as $key => $argument) {
            $arguments['%' . (is_int($key) ? $key + 1 : $key)] = $argument instanceof Phrase
                ? $this->render($storeId, $argument)
                : (is_scalar($argument) ? (string) $argument : '');
        }
        // Longest first: %10 before %1.
        uksort($arguments, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return strtr($translated, $arguments);
    }

    /**
     * The time zone of store $storeId (general/locale/timezone, Magento's setting: Stores →
     * Configuration → General → Locale Options → Timezone), such as "Europe/Zurich"; UTC when it
     * names none PHP knows.
     *
     * @param int $storeId The store.
     * @return string
     */
    public function timeZone(int $storeId): string
    {
        $zone = $this->scopeConfig->getValue('general/locale/timezone', ScopeInterface::SCOPE_STORE, $storeId);

        return is_string($zone) && in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }

    /**
     * Unix time $time in store $storeId's time zone (timeZone()), as "2026-11-23 14:05:00".
     *
     * @param int $storeId The store.
     * @param int $time The time, in Unix seconds.
     * @return string
     */
    public function localTime(int $storeId, int $time): string
    {
        return (new \DateTimeImmutable('@' . $time))
            ->setTimezone(new \DateTimeZone($this->timeZone($storeId)))
            ->format('Y-m-d H:i:s');
    }

    /**
     * The locale of store $storeId (general/locale/code), such as "de_CH".
     *
     * @param int $storeId The store.
     * @return string
     */
    public function locale(int $storeId): string
    {
        $locale = $this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId);

        return is_string($locale) && preg_match('/^[a-z]{2,3}_[A-Z]{2}$/D', $locale) === 1 ? $locale : 'en_US';
    }

    /**
     * The module's dictionary of $locale.
     *
     * Its own file, else that of another country of its language, else none (English, the
     * source).
     *
     * @param string $locale The locale.
     * @return array<string, string>
     */
    private function dictionary(string $locale): array
    {
        if (isset($this->dictionaries[$locale])) {
            return $this->dictionaries[$locale];
        }
        $dir = $this->components->getPath(ComponentRegistrar::MODULE, 'Sattleme_Payment') . '/i18n';
        $file = null;
        try {
            $candidates = $this->files->readDirectory($dir);
        } catch (\Exception) {
            $candidates = [];
        }
        sort($candidates, SORT_STRING);
        $language = substr($locale, 0, (int) strpos($locale, '_'));
        // The locale's own file, else its language's main one (de_DE for de_AT), else another.
        $wanted = [$locale . '.csv', $language . '_' . strtoupper($language) . '.csv'];
        foreach ($wanted as $name) {
            foreach ($candidates as $candidate) {
                if ($file === null && $this->nameOf($candidate) === $name) {
                    $file = $candidate;
                }
            }
        }
        foreach ($candidates as $candidate) {
            $name = $this->nameOf($candidate);
            if ($file === null && str_starts_with($name, $language . '_') && str_ends_with($name, '.csv')) {
                $file = $candidate;
            }
        }
        $dictionary = [];
        if ($file !== null) {
            try {
                foreach ($this->csv->getData($file) as $row) {
                    if (is_array($row) && isset($row[0], $row[1]) && is_string($row[0]) && is_string($row[1])) {
                        $dictionary[$row[0]] = $row[1];
                    }
                }
            } catch (\Exception) {
                $dictionary = [];
            }
        }

        return $this->dictionaries[$locale] = $dictionary;
    }

    /**
     * The name of the file at $path, without its directory.
     *
     * @param string $path The path.
     * @return string
     */
    private function nameOf(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? $path : substr($path, $slash + 1);
    }
}
