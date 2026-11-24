<?php
/**
 * The package Adobe Commerce Marketplace takes: a ZIP of the module, composer.json at its root.
 *
 *   php tools/build.php build [<version>]
 *       Writes build/dist/sattleme-module-payment-<version>.zip of the files git tracks (never an
 *       untracked file), as the working tree holds them: those of the module, without its tests,
 *       tools, pinned SDK and CI; and, in vendor/, the libraries it embeds, prefixed, as
 *       tools/embed.php builds them from tools/embed/composer.lock. A clean checkout of a commit
 *       gives the same bytes (sorted entries, HEAD's date, fixed permissions). With <version> (a
 *       tag's, "1.0.0"), it fails unless composer.json and CHANGELOG.md (## 1.0.0 - <date>) are of
 *       that version. Then
 *       it checks the archive (below).
 *   php tools/build.php check <zip>
 *       Whether the archive is what the Marketplace's technical review takes: composer.json
 *       (name, type, version, license, autoload, no extra.map nor magento-root-dir, no source nor
 *       dist, no dependency it refuses, no "*" on magento/*, no inline alias, PHP versions not
 *       narrower than Magento's), etc/module.xml and registration.php naming the same module, no
 *       TODO nor FIXME, no file outside the module's and its embedded libraries', at most 30 MB;
 *       and the embedding: composer.json requiring nothing but PHP, its extensions and Magento's
 *       modules, and autoloading each embedded library from its vendor/ directory, and no code
 *       (PHP, templates, XML) naming the libraries' namespaces without the prefix.
 *
 * It needs PHP (with zip), git, Composer and curl (tools/embed.php).
 */

declare(strict_types=1);

namespace Sattleme\Payment\Tools;

/**
 * Builds and checks the archive.
 */
class Package
{
    public const NAME = 'sattleme/module-payment';

    public const MODULE = 'Sattleme_Payment';

    /**
     * What the archive holds: these files and directories of the repository.
     */
    public const CONTENTS = [
        'Block/', 'CHANGELOG.md', 'Console/', 'Controller/', 'Cron/', 'Embedded/', 'Gateway/', 'LICENSE', 'Model/',
        'Observer/', 'Plugin/', 'README.md', 'SECURITY.md', 'Sdk/', 'ViewModel/', 'composer.json', 'etc/', 'i18n/',
        'registration.php', 'view/',
    ];

    /**
     * Where the archive holds the embedded libraries (tools/embed.php): vendor/<package>/.
     */
    public const EMBEDDED = 'vendor/';

    /**
     * The Marketplace's limit, in bytes.
     */
    public const MAX_BYTES = 30 * 1024 * 1024;

    /**
     * Packages the Marketplace refuses as dependencies.
     */
    private const REFUSED = [
        'magento/magento-composer-installer', 'magento/magento2-base', 'magento/product-community-edition',
        'magento/magento2-ee-base', 'magento/product-enterprise-edition',
    ];

    /**
     * The PHP versions of the Magento versions the module claims (2.4.7: 8.2, 8.3; 2.4.8: 8.3, 8.4;
     * 2.4.9: 8.4, 8.5): its constraint takes each of them.
     */
    private const PHP_VERSIONS = ['8.2.0', '8.3.0', '8.4.0', '8.5.0'];

    /**
     * @param string $root The repository.
     */
    public function __construct(private readonly string $root)
    {
    }

    /**
     * Builds the archive of HEAD into $dir: its path.
     *
     * @param string $dir Where to write it.
     * @param string|null $version The version it must be of.
     * @return string
     */
    public function build(string $dir, ?string $version): string
    {
        $composer = $this->json($this->read('composer.json'));
        $own = is_string($composer['version'] ?? null) ? $composer['version'] : '';
        if ($version !== null && $version !== $own) {
            throw new \RuntimeException("composer.json is of version {$own}, not {$version}");
        }
        $entry = '/\n## ' . preg_quote($version ?? '', '/') . '( - \d{4}-\d{2}-\d{2})?\n/';
        if ($version !== null && preg_match($entry, $this->read('CHANGELOG.md')) !== 1) {
            throw new \RuntimeException("CHANGELOG.md has no entry ## {$version} (- its date)");
        }
        $time = (int) trim($this->git(['log', '-1', '--format=%ct', 'HEAD']));
        // Each file of the archive: where its content is.
        $files = [];
        foreach (explode("\0", $this->git(['ls-files', '-z', '--cached'])) as $file) {
            if ($file !== '' && $this->isContent($file)) {
                $files[$file] = $this->root . '/' . $file;
            }
        }
        require_once __DIR__ . '/embed.php';
        $embedded = new Embedded($this->root);
        [$libraries] = $embedded->build();
        foreach ($embedded->filesUnder($libraries . '/' . rtrim(self::EMBEDDED, '/')) as $file) {
            $files[self::EMBEDDED . $file] = $libraries . '/' . self::EMBEDDED . $file;
        }
        ksort($files, SORT_STRING);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot make {$dir}");
        }
        $path = $dir . '/sattleme-module-payment-' . $own . '.zip';
        if (is_file($path)) {
            unlink($path);
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException("Cannot write {$path}");
        }
        foreach ($files as $file => $source) {
            $content = file_get_contents($source);
            if ($content === false) {
                throw new \RuntimeException("Cannot read {$source}");
            }
            $zip->addFromString($file, $content);
            $index = $zip->locateName($file);
            if ($index === false) {
                throw new \RuntimeException("Cannot add {$file}");
            }
            $zip->setMtimeIndex($index, $time);
            $zip->setExternalAttributesIndex($index, \ZipArchive::OPSYS_UNIX, 0100644 << 16);
            $zip->setCompressionIndex($index, \ZipArchive::CM_DEFLATE, 9);
        }
        $zip->close();

        return $path;
    }

    /**
     * What is wrong with the archive at $path: empty when the Marketplace's review takes it.
     *
     * @param string $path The archive.
     * @return list<string>
     */
    public function problems(string $path): array
    {
        $problems = [];
        if (!is_file($path) || filesize($path) > self::MAX_BYTES) {
            return ["{$path} is missing, or larger than 30 MB"];
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return ["{$path} is not a ZIP archive"];
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        // The code, whose references to the embedded libraries are checked below.
        $code = [];
        foreach ($names as $name) {
            $known = $this->isContent($name) || $this->isEmbedded($name);
            if (!$known || str_contains($name, '..') || str_starts_with($name, '/')) {
                $problems[] = "{$name} is no file of the module";
                continue;
            }
            $text = (string) $zip->getFromName($name);
            if (preg_match('/\b(TODO|FIXME)\b/', $text) === 1) {
                $problems[] = "{$name} has a TODO or FIXME";
            }
            if (preg_match('/\.(php|phtml|xml)$/D', $name) === 1) {
                $code[$name] = $text;
            }
        }
        require_once __DIR__ . '/embed.php';
        $requiredFiles = ['composer.json', 'registration.php', 'etc/module.xml', 'LICENSE', 'README.md'];
        foreach (array_keys(Embedded::PACKAGES) as $package) {
            $requiredFiles[] = self::EMBEDDED . $package . '/LICENSE';
        }
        foreach ($requiredFiles as $required) {
            if (!in_array($required, $names, true)) {
                $problems[] = "The archive has no {$required}";
            }
        }
        if ($problems !== []) {
            return $problems;
        }
        $composer = $this->json((string) $zip->getFromName('composer.json'));
        $problems = array_merge($problems, $this->composerProblems($composer), $this->embeddingProblems($composer));
        foreach ((new Embedded($this->root))->unprefixedReferences($code) as $reference) {
            $problems[] = "{$reference}: an embedded library named without its prefix";
        }
        $module = (string) $zip->getFromName('etc/module.xml');
        if (preg_match('/<module name="' . self::MODULE . '"/', $module) !== 1) {
            $problems[] = 'etc/module.xml does not declare ' . self::MODULE;
        }
        $registration = (string) $zip->getFromName('registration.php');
        if (!str_contains($registration, "ComponentRegistrar::MODULE, '" . self::MODULE . "'")) {
            $problems[] = 'registration.php does not register ' . self::MODULE;
        }
        $zip->close();

        return $problems;
    }

    /**
     * What the Marketplace's review refuses in $composer.
     *
     * @param array<mixed> $composer The package's composer.json.
     * @return list<string>
     */
    public function composerProblems(array $composer): array
    {
        $problems = [];
        if (($composer['name'] ?? null) !== self::NAME) {
            $problems[] = 'composer.json is not named ' . self::NAME;
        }
        if (($composer['type'] ?? null) !== 'magento2-module') {
            $problems[] = 'composer.json is not of type magento2-module';
        }
        if (!is_string($composer['version'] ?? null) || preg_match('/^\d+\.\d+\.\d+$/D', $composer['version']) !== 1) {
            $problems[] = 'composer.json has no version x.y.z';
        }
        if (($composer['license'] ?? null) !== 'MIT') {
            $problems[] = 'composer.json is not of license MIT';
        }
        $autoload = is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        $psr4 = $autoload['psr-4'] ?? null;
        if (($autoload['files'] ?? null) !== ['registration.php'] || !is_array($psr4) || $psr4 === []) {
            $problems[] = 'composer.json does not autoload registration.php and a PSR-4 namespace';
        }
        $extra = is_array($composer['extra'] ?? null) ? $composer['extra'] : [];
        foreach (['map', 'magento-root-dir'] as $key) {
            if (array_key_exists($key, $extra)) {
                $problems[] = "composer.json has extra.{$key}";
            }
        }
        foreach (['source', 'dist'] as $key) {
            if (array_key_exists($key, $composer)) {
                $problems[] = "composer.json has {$key}";
            }
        }
        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        foreach ($require as $package => $constraint) {
            $package = (string) $package;
            $constraint = is_string($constraint) ? $constraint : '';
            if (in_array($package, self::REFUSED, true)) {
                $problems[] = "composer.json requires {$package}";
            }
            if (str_starts_with($package, 'magento/') && trim($constraint) === '*') {
                $problems[] = "composer.json takes any version of {$package}";
            }
            if (preg_match('/\sas\s/', $constraint) === 1) {
                $problems[] = "composer.json has an inline alias for {$package}";
            }
        }
        $php = is_string($require['php'] ?? null) ? $require['php'] : '';
        foreach (self::PHP_VERSIONS as $version) {
            if (!$this->takes($php, $version)) {
                $problems[] = "composer.json's PHP constraint does not take PHP {$version}";
            }
        }

        return $problems;
    }

    /**
     * What keeps $composer from installing the module with its libraries embedded: a requirement
     * other than PHP, its extensions and Magento's modules (Composer would install it beside the
     * embedded copy, from a repository the Marketplace may not have), or an embedded library its
     * autoloader does not find in the package's vendor/.
     *
     * @param array<mixed> $composer The package's composer.json.
     * @return list<string>
     */
    public function embeddingProblems(array $composer): array
    {
        $problems = [];
        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        foreach (array_keys($require) as $package) {
            $package = (string) $package;
            if ($package !== 'php' && !str_starts_with($package, 'ext-') && !str_starts_with($package, 'magento/')) {
                $problems[] = "composer.json requires {$package}, which the package embeds or does not need";
            }
        }
        $autoload = is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        $psr4 = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];
        require_once __DIR__ . '/embed.php';
        foreach (Embedded::PACKAGES as $package => $namespace) {
            if (($psr4[Embedded::PREFIX . $namespace] ?? null) !== self::EMBEDDED . $package . '/src/') {
                $problems[] = 'composer.json does not autoload ' . Embedded::PREFIX . $namespace . ' from '
                    . self::EMBEDDED . $package . '/src/';
            }
        }

        return $problems;
    }

    /**
     * Whether $file of the archive is one of an embedded library: its LICENSE, or under its src/.
     *
     * @param string $file The file, relative to the archive.
     * @return bool
     */
    private function isEmbedded(string $file): bool
    {
        require_once __DIR__ . '/embed.php';
        foreach (array_keys(Embedded::PACKAGES) as $package) {
            $dir = self::EMBEDDED . $package . '/';
            if ($file === $dir . 'LICENSE' || (str_starts_with($file, $dir . 'src/') && str_ends_with($file, '.php'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $file of the repository goes in the archive.
     *
     * @param string $file The file, relative to the repository.
     * @return bool
     */
    private function isContent(string $file): bool
    {
        foreach (self::CONTENTS as $entry) {
            if ($file === $entry || (str_ends_with($entry, '/') && str_starts_with($file, $entry))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the constraint $constraint (alternatives of "~X.Y.0" or "^X.Y") takes $version.
     *
     * @param string $constraint The constraint.
     * @param string $version The version.
     * @return bool
     */
    private function takes(string $constraint, string $version): bool
    {
        $alternatives = preg_split('/\s*\|\|?\s*/', trim($constraint));
        foreach ($alternatives === false ? [] : $alternatives as $alternative) {
            if (preg_match('/^~(\d+)\.(\d+)(?:\.(\d+))?$/D', $alternative, $m) === 1) {
                $low = $m[1] . '.' . $m[2] . '.' . ($m[3] ?? '0');
                $high = isset($m[3]) ? $m[1] . '.' . ((int) $m[2] + 1) . '.0' : ((int) $m[1] + 1) . '.0.0';
            } elseif (preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?$/D', $alternative, $m) === 1) {
                $low = $m[1] . '.' . $m[2] . '.' . ($m[3] ?? '0');
                $high = ((int) $m[1] + 1) . '.0.0';
            } else {
                continue;
            }
            if (version_compare($version, $low, '>=') && version_compare($version, $high, '<')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The content of $file of the working tree.
     *
     * @param string $file The file, relative to the repository.
     * @return string
     */
    private function read(string $file): string
    {
        $content = is_file($this->root . '/' . $file) ? file_get_contents($this->root . '/' . $file) : false;
        if ($content === false) {
            throw new \RuntimeException("Cannot read {$file}");
        }

        return $content;
    }

    /**
     * Runs git in the repository: its output.
     *
     * @param list<string> $args The arguments.
     * @return string
     */
    private function git(array $args): string
    {
        $command = array_merge(['git', '-C', $this->root], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot run git');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ' failed: ' . trim($err));
        }

        return $out;
    }

    /**
     * JSON text as an array.
     *
     * @param string $text The text.
     * @return array<mixed>
     */
    private function json(string $text): array
    {
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Not a JSON object');
        }

        return $data;
    }
}

// Loaded by the tests for its class alone.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $package = new Package(dirname(__DIR__));
    $command = $argv[1] ?? '';
    try {
        if ($command === 'build' && count($argv) <= 3) {
            $version = isset($argv[2]) ? ltrim($argv[2], 'v') : null;
            $path = $package->build(dirname(__DIR__) . '/build/dist', $version);
        } elseif ($command === 'check' && count($argv) === 3) {
            $path = $argv[2];
        } else {
            fwrite(STDERR, "Usage: php tools/build.php build [<version>] | check <zip>\n");
            exit(2);
        }
        $problems = $package->problems($path);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    foreach ($problems as $problem) {
        fwrite(STDERR, $problem . "\n");
    }
    if ($problems !== []) {
        exit(1);
    }
    echo $path, ' (', filesize($path), ' bytes, SHA-256 ', hash_file('sha256', $path), ")\n";
    exit(0);
}
