<?php
/**
 * Writes the configuration of the module's tests in a Magento (tools/tests/magento.sh), from
 * that Magento's own (its PHPUnit version's):
 *
 *   php tools/tests/phpunit.php <magento> <module's tests> <db host:port> <db name>
 *
 * - dev/tests/unit/phpunit-sattleme.xml: Magento's unit test framework, the module's Test/Unit;
 * - dev/tests/integration/phpunit-sattleme.xml: Magento's integration test framework, the
 *   module's Test/Integration; its own sandbox, no override of Magento's annotations
 *   (USE_OVERRIDE_CONFIG), the sandbox kept between runs (TESTS_CLEANUP);
 * - dev/tests/integration/etc/install-config-mysql.php: the database, the user "magento" of
 *   tools/tests/mariadb.sh, without a search engine (Magento's search modules disabled: the
 *   module never searches, and no OpenSearch runs without Docker).
 */

declare(strict_types=1);

// $argv: PHP's CLI always defines it (PHPStan only knows it when register_argc_argv is on).
$args = $argv ?? [];
if (count($args) !== 5) {
    fwrite(STDERR, "Usage: php tools/tests/phpunit.php <magento> <module's tests> <db host:port> <db name>\n");
    exit(2);
}
[, $magento, $tests, $host, $database] = $args;

/**
 * Magento's $dist configuration, its test suites replaced by $directory, its constants $constants.
 *
 * @param string $dist The configuration.
 * @param string $directory The tests.
 * @param array<string,string> $constants Constants to set.
 * @return string
 */
function sattlemeConfig(string $dist, string $directory, array $constants): string
{
    $document = new DOMDocument();
    $document->preserveWhiteSpace = false;
    $document->formatOutput = true;
    if (!$document->load($dist)) {
        throw new RuntimeException("Cannot read {$dist}");
    }
    $root = $document->documentElement;
    if (!$root instanceof DOMElement) {
        throw new RuntimeException("{$dist} is empty");
    }
    foreach (iterator_to_array($root->getElementsByTagName('testsuites')) as $old) {
        $old->parentNode?->removeChild($old);
    }
    $suites = $document->createElement('testsuites');
    $suite = $document->createElement('testsuite');
    $suite->setAttribute('name', 'sattleme');
    $suite->appendChild($document->createElement('directory', $directory));
    $suites->appendChild($suite);
    $root->insertBefore($suites, $root->firstChild);
    // Magento's coverage settings name its own code only.
    foreach (['source', 'coverage', 'filter', 'logging'] as $name) {
        foreach (iterator_to_array($root->getElementsByTagName($name)) as $element) {
            $element->parentNode?->removeChild($element);
        }
    }
    $php = $root->getElementsByTagName('php')->item(0);
    if (!$php instanceof DOMElement) {
        $php = $document->createElement('php');
        $root->appendChild($php);
    }
    foreach ($constants as $name => $value) {
        $found = false;
        foreach ($php->getElementsByTagName('const') as $const) {
            if ($const->getAttribute('name') === $name) {
                $const->setAttribute('value', $value);
                $found = true;
            }
        }
        if (!$found) {
            $const = $document->createElement('const');
            $const->setAttribute('name', $name);
            $const->setAttribute('value', $value);
            $php->appendChild($const);
        }
    }

    return (string) $document->saveXML();
}

$tests = rtrim($tests, '/');
$unit = sattlemeConfig($magento . '/dev/tests/unit/phpunit.xml.dist', $tests . '/Unit', []);
file_put_contents($magento . '/dev/tests/unit/phpunit-sattleme.xml', $unit);
$integration = sattlemeConfig(
    $magento . '/dev/tests/integration/phpunit.xml.dist',
    $tests . '/Integration',
    ['TESTS_CLEANUP' => 'disabled', 'TESTS_EXTRA_VERBOSE_LOG' => '0', 'USE_OVERRIDE_CONFIG' => 'disabled']
);
file_put_contents($magento . '/dev/tests/integration/phpunit-sattleme.xml', $integration);
// The search engines' modules of this Magento (their names change with its versions).
$searchModules = array_values(array_filter(
    [
        'Magento_OpenSearch', 'Magento_Elasticsearch', 'Magento_Elasticsearch7', 'Magento_Elasticsearch8',
        'Magento_AdvancedSearch',
    ],
    static fn(string $module): bool => is_dir($magento . '/app/code/Magento/' . substr($module, strlen('Magento_')))
));
$install = [
    'db-host' => $host,
    'db-user' => 'magento',
    'db-password' => 'magento',
    'db-name' => $database,
    'db-prefix' => '',
    'backend-frontname' => 'backend',
    'disable-modules' => implode(',', $searchModules),
];
$code = "<?php\n\n// Written by tools/tests/phpunit.php.\nreturn " . var_export($install, true) . " + [\n"
    . "    'admin-user' => \\Magento\\TestFramework\\Bootstrap::ADMIN_NAME,\n"
    . "    'admin-password' => \\Magento\\TestFramework\\Bootstrap::ADMIN_PASSWORD,\n"
    . "    'admin-email' => \\Magento\\TestFramework\\Bootstrap::ADMIN_EMAIL,\n"
    . "    'admin-firstname' => \\Magento\\TestFramework\\Bootstrap::ADMIN_FIRSTNAME,\n"
    . "    'admin-lastname' => \\Magento\\TestFramework\\Bootstrap::ADMIN_LASTNAME,\n"
    . "];\n";
file_put_contents($magento . '/dev/tests/integration/etc/install-config-mysql.php', $code);
echo "Wrote the test configuration of {$magento}\n";
