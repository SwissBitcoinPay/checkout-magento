<?php
/**
 * The pinned copy of the PHP SDK (sattleme/checkout) in sdk/: the files its package is made of at
 * one commit of https://github.com/sattleme/checkout-php, unchanged, and sdk/PIN.json saying which
 * commit, which version, and the SHA-256 of each file.
 *
 * The package embeds it, prefixed (tools/embed.php): tools/embed/composer.lock takes it from sdk/
 * (a Composer path repository), at the version PIN.json names, which tools/embed/composer.json
 * says too.
 *
 *   php tools/sdk.php check
 *       Whether sdk/ is PIN.json's, file for file (Test/Unit/Tools/SdkPinTest.php runs it too).
 *   php tools/sdk.php import <checkout-php clone> [<commit>]
 *       Replaces sdk/ with the SDK's package at <commit> (the clone's HEAD by default), as git
 *       holds it at that commit (never the clone's working tree), writes PIN.json, and sets the
 *       version in tools/embed/composer.json. The files are those `git archive` gives (the SDK's
 *       .gitattributes's export-ignore applied: what Composer installs from a release), but its
 *       Markdown documentation, and the test vectors of SdkPin::VECTORS. Then
 *       tools/embed/composer.lock is locked again (README.md, Development).
 *
 * It needs PHP and git only; it never loads Magento.
 */

declare(strict_types=1);

namespace Sattleme\Payment\Tools;

/**
 * The pinned copy, its check and its import.
 */
class SdkPin
{
    public const REPOSITORY = 'https://github.com/sattleme/checkout-php';

    public const PIN = 'PIN.json';

    public const PACKAGE = 'sattleme/checkout';

    /**
     * Test data of the SDK's repository that no package holds, copied with the package's files for
     * the module's tests of the embedded SDK (Test/Unit/Sdk/EmbeddedSdkTest.php): the conformance
     * vectors that need no network (prices in a currency, integration keys, NIP-44), the pin of
     * the specification they come from, and the BIP-340 vectors with their source.
     */
    public const VECTORS = [
        'spec/PIN.json',
        'spec/vectors/fiat.json',
        'spec/vectors/integration-keys.json',
        'spec/vectors/nip44/nip44.vectors.json',
        'tests/fixtures/bip340/SOURCE.md',
        'tests/fixtures/bip340/test-vectors.csv',
    ];

    /**
     * What differs between $copy and its PIN.json: empty when the copy is the pinned one.
     *
     * @param string $copy The pinned copy (sdk/).
     * @return list<string>
     */
    public function problems(string $copy): array
    {
        $pinPath = $copy . '/' . self::PIN;
        if (!is_file($pinPath)) {
            return [self::PIN . ' is missing'];
        }
        $pin = json_decode((string) file_get_contents($pinPath), true);
        if (!is_array($pin)) {
            return [self::PIN . ' is not JSON'];
        }
        $problems = [];
        if (array_keys($pin) !== ['repository', 'commit', 'version', 'files']) {
            $problems[] = self::PIN . ': its members are repository, commit, version and files, in that order';
        }
        if (($pin['repository'] ?? null) !== self::REPOSITORY) {
            $problems[] = self::PIN . ': repository is not ' . self::REPOSITORY;
        }
        if (!is_string($pin['commit'] ?? null) || preg_match('/^[0-9a-f]{40}$/D', $pin['commit']) !== 1) {
            $problems[] = self::PIN . ': commit is not a commit';
        }
        if (!is_string($pin['version'] ?? null) || preg_match('/^\d+\.\d+\.\d+$/D', $pin['version']) !== 1) {
            $problems[] = self::PIN . ': version is not a version';
        }
        $files = $pin['files'] ?? null;
        if (!is_array($files) || $files === []) {
            return array_merge($problems, [self::PIN . ': files is empty']);
        }
        foreach ($files as $file => $hash) {
            $path = $copy . '/' . $file;
            if (!is_file($path)) {
                $problems[] = "{$file} is missing";
            } elseif (hash_file('sha256', $path) !== $hash) {
                $problems[] = "{$file} differs from " . self::PIN;
            }
        }
        foreach ($this->filesUnder($copy) as $file) {
            if ($file !== self::PIN && !array_key_exists($file, $files)) {
                $problems[] = "{$file} is not in " . self::PIN;
            }
        }
        $listed = array_map('strval', array_keys($files));
        $sorted = $listed;
        sort($sorted, SORT_STRING);
        if ($listed !== $sorted) {
            $problems[] = self::PIN . ': files are not sorted';
        }

        return $problems;
    }

    /**
     * The pinned version (PIN.json's), as the development and the CI install it.
     *
     * @param string $copy The pinned copy (sdk/).
     * @throws \RuntimeException When PIN.json has none.
     */
    public function version(string $copy): string
    {
        $pin = json_decode((string) file_get_contents($copy . '/' . self::PIN), true);
        $version = is_array($pin) ? ($pin['version'] ?? null) : null;
        if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
            throw new \RuntimeException(self::PIN . ' names no version');
        }

        return $version;
    }

    /**
     * The SDK's version tools/embed/composer.json ($manifest) locks.
     *
     * The one its "require" and its path repository's "versions" both name, or null when they
     * differ or name none.
     *
     * @param string $manifest tools/embed/composer.json.
     * @return string|null
     */
    public function embeddedVersion(string $manifest): ?string
    {
        $json = json_decode((string) file_get_contents($manifest), true);
        $json = is_array($json) ? $json : [];
        $required = is_array($json['require'] ?? null) ? ($json['require'][self::PACKAGE] ?? null) : null;
        $versions = [];
        foreach (is_array($json['repositories'] ?? null) ? $json['repositories'] : [] as $repository) {
            if (is_array($repository) && ($repository['type'] ?? null) === 'path'
                && ($repository['url'] ?? null) === '../../sdk'
            ) {
                $options = is_array($repository['options'] ?? null) ? $repository['options'] : [];
                $named = is_array($options['versions'] ?? null) ? $options['versions'] : [];
                $versions[] = $named[self::PACKAGE] ?? null;
            }
        }

        return is_string($required) && $versions === [$required] ? $required : null;
    }

    /**
     * Sets the SDK's version in tools/embed/composer.json ($manifest).
     *
     * In its "require" and its path repository's "versions", the file otherwise unchanged.
     *
     * @param string $manifest tools/embed/composer.json.
     * @param string $version The version.
     * @return void
     * @throws \RuntimeException When the file does not name it twice.
     */
    public function setEmbeddedVersion(string $manifest, string $version): void
    {
        $count = 0;
        $json = preg_replace(
            '/("' . preg_quote(self::PACKAGE, '/') . '": )"\d+\.\d+\.\d+"/',
            '${1}"' . $version . '"',
            (string) file_get_contents($manifest),
            -1,
            $count
        );
        if ($count !== 2 || !is_string($json) || file_put_contents($manifest, $json) === false) {
            throw new \RuntimeException("{$manifest} does not name the version of " . self::PACKAGE . ' twice');
        }
    }

    /**
     * Replaces $copy with the SDK's package at $commit of the clone $source, and writes PIN.json.
     *
     * $commit is by default the clone's HEAD; the files are those git holds there.
     *
     * @param string $source A clone of checkout-php.
     * @param string $copy The pinned copy (sdk/).
     * @param string|null $commit A commit, a tag or a branch; null for HEAD.
     * @return array{repository: string, commit: string, version: string, files: array<string, string>}
     * @throws \RuntimeException When the commit is not the SDK's.
     */
    public function import(string $source, string $copy, ?string $commit): array
    {
        $asked = $commit ?? 'HEAD';
        if ($asked === '' || str_starts_with($asked, '-')) {
            throw new \RuntimeException("Not a commit: {$asked}");
        }
        $commit = $this->git($source, ['rev-parse', '--verify', '--quiet', $asked . '^{commit}']);
        if (preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
            throw new \RuntimeException("Not a commit of {$source}: {$asked}");
        }
        $manifest = json_decode($this->git($source, ['show', "{$commit}:composer.json"]), true);
        if (!is_array($manifest) || ($manifest['name'] ?? null) !== self::PACKAGE) {
            throw new \RuntimeException("{$source} at {$commit} is not " . self::PACKAGE);
        }
        // The version the SDK declares (Client::VERSION): its composer.json has none.
        $client = $this->git($source, ['show', "{$commit}:src/Client.php"]);
        if (preg_match("/public const VERSION = '(\\d+\\.\\d+\\.\\d+)';/", $client, $match) !== 1) {
            throw new \RuntimeException('No Client::VERSION in src/Client.php');
        }
        $wanted = [];
        foreach ($this->archived($source, $commit) as $file) {
            if (preg_match('#^[^/]+\.md$#Di', $file) !== 1) {
                $wanted[] = $file;
            }
        }
        if (!in_array('composer.json', $wanted, true) || !in_array('src/Client.php', $wanted, true)) {
            throw new \RuntimeException("The package of {$commit} has no composer.json or no src/Client.php");
        }
        $wanted = array_merge($wanted, self::VECTORS);
        sort($wanted, SORT_STRING);
        $this->remove($copy);
        $files = [];
        foreach ($wanted as $file) {
            // The blob as committed: never the working tree, never a line ending converted.
            $content = $this->git($source, ['cat-file', 'blob', "{$commit}:{$file}"], false);
            $target = $copy . '/' . $file;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true)) {
                throw new \RuntimeException("Cannot create the directory of {$target}");
            }
            if (file_put_contents($target, $content) === false) {
                throw new \RuntimeException("Cannot write {$target}");
            }
            $files[$file] = hash('sha256', $content);
        }
        $pin = ['repository' => self::REPOSITORY, 'commit' => $commit, 'version' => $match[1], 'files' => $files];
        $json = json_encode($pin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($copy . '/' . self::PIN, $json . "\n") === false) {
            throw new \RuntimeException('Cannot write ' . self::PIN);
        }

        return $pin;
    }

    /**
     * The files of the SDK's package at $commit, sorted.
     *
     * Those `git archive` gives: export-ignore applied, from the commit's own .gitattributes.
     *
     * @param string $source A clone of checkout-php.
     * @param string $commit The commit.
     * @return list<string>
     */
    private function archived(string $source, string $commit): array
    {
        $files = array_values(
            array_filter(explode("\0", $this->listing($source, $commit)), static fn(string $name): bool => $name !== '')
        );
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The regular files of `git archive`'s tar of $commit, separated by NUL.
     *
     * Read from the tar's headers (ustar, with git's pax headers for long paths).
     *
     * @param string $source A clone of checkout-php.
     * @param string $commit The commit.
     * @return string
     * @throws \RuntimeException When the archive holds what is not a file nor a directory.
     */
    private function listing(string $source, string $commit): string
    {
        $tar = $this->git($source, ['archive', '--format=tar', $commit], false);
        $length = strlen($tar);
        $names = [];
        $offset = 0;
        $path = null;
        while ($offset + 512 <= $length) {
            $header = substr($tar, $offset, 512);
            if (trim($header, "\0") === '') {
                break;
            }
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            $type = $header[156];
            $data = substr($tar, $offset + 512, $size);
            $offset += 512 + (int) (ceil($size / 512) * 512);
            if ($type === 'g') {
                continue;
            }
            if ($type === 'x') {
                $path = preg_match('/^\d+ path=(.*)$/m', $data, $long) === 1 ? $long[1] : null;
                continue;
            }
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $name = rtrim(substr($header, 0, 100), "\0");
            $name = $path ?? ($prefix === '' ? $name : $prefix . '/' . $name);
            $path = null;
            if ($type === '0' || $type === "\0") {
                $names[] = $name;
            } elseif ($type !== '5') {
                throw new \RuntimeException("{$name} is not a file nor a directory");
            }
        }

        return implode("\0", $names);
    }

    /**
     * Every file under $dir, as paths relative to it with "/", sorted.
     *
     * @param string $dir The directory.
     * @return list<string>
     */
    public function filesUnder(string $dir): array
    {
        $found = [];
        if (!is_dir($dir)) {
            return $found;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            }
        }
        sort($found, SORT_STRING);

        return $found;
    }

    /**
     * Runs git in $repository: its output, trimmed unless $trim is false.
     *
     * @param string $repository The repository.
     * @param list<string> $args The arguments.
     * @param bool $trim Whether to trim the output.
     * @return string
     * @throws \RuntimeException When git fails.
     */
    private function git(string $repository, array $args, bool $trim = true): string
    {
        $process = proc_open(
            array_merge(['git', '-C', $repository], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot run git');
        }
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ' failed: ' . trim($error));
        }

        return $trim ? trim($output) : $output;
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
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}

// Loaded by the tests for its class alone.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $root = dirname(__DIR__);
    $copy = $root . '/sdk';
    $pin = new SdkPin();
    $command = $argv[1] ?? '';
    if ($command === 'check' && count($argv) === 2) {
        $problems = $pin->problems($copy);
        if ($problems === [] && $pin->embeddedVersion($root . '/tools/embed/composer.json') !== $pin->version($copy)) {
            $problems[] = 'tools/embed/composer.json does not lock ' . SdkPin::PACKAGE . ' ' . $pin->version($copy)
                . ', the version of sdk/';
        }
        foreach ($problems as $problem) {
            fwrite(STDERR, $problem . "\n");
        }
        if ($problems === []) {
            echo "sdk/ is PIN.json's\n";
        }
        exit($problems === [] ? 0 : 1);
    }
    if ($command === 'version' && count($argv) === 2) {
        echo $pin->version($copy), "\n";
        exit(0);
    }
    if ($command === 'import' && (count($argv) === 3 || count($argv) === 4)) {
        try {
            $imported = $pin->import($argv[2], $copy, $argv[3] ?? null);
            $pin->setEmbeddedVersion($root . '/tools/embed/composer.json', $imported['version']);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        echo SdkPin::PACKAGE . " {$imported['version']} at {$imported['commit']} (" . count($imported['files'])
            . " files) is in sdk/, and its version in tools/embed/composer.json: now lock it"
            . " (composer update sattleme/checkout --working-dir=tools/embed), then run the checks and the"
            . " tests (README.md, Development)\n";
        exit(0);
    }
    fwrite(STDERR, "Usage: php tools/sdk.php check | version | import <checkout-php clone> [<commit>]\n");
    exit(2);
}
