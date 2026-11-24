<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Tools;

use PHPUnit\Framework\TestCase;

/**
 * tools/tests/mariadb.sh, as the CI calls it: `db="$(sh tools/tests/mariadb.sh start)"` must give
 * the server's address as soon as it answers, while the server keeps running; the clients run with
 * the libraries extracted beside them (a runner may have none of them). Here with a server and
 * clients of the test's own, which stand for MariaDB's: a client fails unless those libraries come
 * first.
 */
class MariaDbTest extends TestCase
{
    /**
     * Seconds the stand-in server runs.
     */
    private const SERVER_SECONDS = 20;

    /**
     * @var string|null
     */
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            $pid = is_file($this->dir . '/server.pid') ? (int) file_get_contents($this->dir . '/server.pid') : 0;
            if ($pid > 0 && function_exists('posix_kill')) {
                posix_kill($pid, 15);
            } elseif ($pid > 0) {
                exec('kill ' . $pid . ' 2>/dev/null');
            }
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    public function testStartGivesTheAddressWhileTheServerRuns(): void
    {
        $this->dir = sys_get_temp_dir() . '/sattleme-mariadb-test-' . bin2hex(random_bytes(6));
        $this->executable(
            'root/usr/sbin/mariadbd',
            "echo \$\$ > '{$this->dir}/server.pid'\nexec sleep " . self::SERVER_SECONDS . "\n"
        );
        $this->client('mariadb', "[ -f '{$this->dir}/server.pid' ]\n");
        mkdir($this->dir . '/data/mysql', 0700, true);

        $started = microtime(true);
        [$status, $output, $errors] = $this->script('db="$(sh "$1" start)"; echo "$db"');
        $seconds = microtime(true) - $started;

        $this->assertSame(0, $status, $errors);
        $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+\n$/D', $output);
        $this->assertLessThan(self::SERVER_SECONDS / 2, $seconds, 'start waited for the server to end');
        $this->assertFileExists($this->dir . '/server.pid');
    }

    public function testTheClientsMagentoCallsRunWithTheExtractedLibraries(): void
    {
        $this->dir = sys_get_temp_dir() . '/sattleme-mariadb-test-' . bin2hex(random_bytes(6));
        $this->executable('root/usr/sbin/mariadbd', "exit 1\n");
        $this->client('mariadb', "echo \"mariadb \$*\"\n");
        $this->client('mariadb-dump', "echo \"mariadb-dump \$*\"\n");

        [$status, $output, $errors] = $this->script(
            'bin="$(sh "$1" bin)" && PATH="$bin:$PATH" && mysql --host=h "a b" && mysqldump --port=1'
        );

        $this->assertSame(0, $status, $errors);
        $this->assertSame("mariadb --host=h a b\nmariadb-dump --port=1\n", $output);
    }

    /**
     * Writes a stand-in for MariaDB's client $name: it runs $body only when the extracted libraries
     * come first in LD_LIBRARY_PATH, as the real one finds those it needs only there.
     *
     * @param string $name The client, in root/usr/bin.
     * @param string $body Its commands.
     * @return void
     */
    private function client(string $name, string $body): void
    {
        $this->executable(
            'root/usr/bin/' . $name,
            "case \"\$LD_LIBRARY_PATH\" in\n"
            . "  '{$this->dir}/root/usr/lib/x86_64-linux-gnu'|'{$this->dir}/root/usr/lib/x86_64-linux-gnu':*) ;;\n"
            . "  *) echo 'error while loading shared libraries' >&2; exit 127 ;;\n"
            . "esac\n"
            . $body
        );
    }

    /**
     * Runs $command with sh, its $1 the script, in the test's directory: its status, output and
     * errors.
     *
     * @param string $command The commands.
     * @return array{int, string, string}
     */
    private function script(string $command): array
    {
        $process = proc_open(
            ['sh', '-c', $command, 'sh', dirname(__DIR__, 3) . '/tools/tests/mariadb.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['SATTLEME_MARIADB_DIR' => $this->dir, 'PATH' => (string) getenv('PATH')]
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $errors];
    }

    /**
     * Writes an executable shell script $path under the test's directory.
     *
     * @param string $path The script, relative to the directory.
     * @param string $body Its commands.
     * @return void
     */
    private function executable(string $path, string $body): void
    {
        $file = $this->dir . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        file_put_contents($file, "#!/bin/sh\n" . $body);
        chmod($file, 0700);
    }
}
