<?php
/**
 * The end-to-end test's scenario (tools/tests/e2e.sh), against a Magento being served: the
 * storefront's home and product pages still cacheable with the module (Adobe's installation
 * tests check them in Varnish); then, as a guest, through the storefront's own API
 * (the checkout's calls, with the session's cookie), an order placed with the method, its payment
 * page, its payment found by the page and by Magento's cron; a virtual product's order completed
 * by the cron alone, its emails made in the cron's process; the orders and their invoices read
 * back through Magento's REST API and the database.
 *
 *   php tools/tests/e2e.php <magento dir> <base url> <db host:port>
 *
 * It needs PHP (curl, pdo_mysql) only. Each step says what it checks; the first that fails stops it.
 */

declare(strict_types=1);

namespace Sattleme\Payment\Tools\E2e;

/**
 * A browser: its cookies, and what the last request answered.
 */
class Browser
{
    /**
     * @var array<string,string>
     */
    private array $cookies = [];

    /**
     * @param string $base The storefront's URL.
     */
    public function __construct(private readonly string $base)
    {
    }

    /**
     * A request: [status, headers (lower-case names), body].
     *
     * @param non-empty-string $method GET or POST.
     * @param string $path The path, or a URL.
     * @param mixed $json The JSON body, if any.
     * @param array<string,string> $headers More headers.
     * @return array{0: int, 1: array<string,string>, 2: string}
     */
    public function request(string $method, string $path, mixed $json = null, array $headers = []): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->base . ltrim($path, '/');
        $curl = curl_init($url);
        $lines = ['Accept: application/json, text/html'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $lines[] = 'Cookie: ' . implode('; ', $pairs);
        }
        if ($json !== null) {
            $lines[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json, JSON_THROW_ON_ERROR));
        }
        $received = [];
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $lines);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, 120);
        curl_setopt(
            $curl,
            CURLOPT_HEADERFUNCTION,
            function ($curl, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    if ($name === 'set-cookie') {
                        [$pair] = explode(';', $value, 2);
                        [$cookie, $cookieValue] = array_pad(explode('=', $pair, 2), 2, '');
                        $this->cookies[trim($cookie)] = trim($cookieValue);
                    }
                    $received[$name] = $value;
                }

                return strlen($line);
            }
        );
        $body = curl_exec($curl);
        if (!is_string($body)) {
            throw new \RuntimeException("{$method} {$url}: " . curl_error($curl));
        }
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        return [$status, $received, $body];
    }

    /**
     * A JSON request that must answer 200: its decoded body.
     *
     * @param non-empty-string $method GET or POST.
     * @param string $path The path.
     * @param mixed $json The JSON body, if any.
     * @param array<string,string> $headers More headers.
     * @return mixed
     */
    public function json(string $method, string $path, mixed $json = null, array $headers = []): mixed
    {
        [$status, , $body] = $this->request($method, $path, $json, $headers);
        if ($status !== 200) {
            throw new \RuntimeException("{$method} {$path}: HTTP {$status}: " . substr($body, 0, 500));
        }

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}

/**
 * Stops the test when $condition is false.
 *
 * @param bool $condition What must be true.
 * @param string $what What it checks.
 * @return void
 */
function check(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException('Failed: ' . $what);
    }
    fwrite(STDOUT, "ok - {$what}\n");
}

/**
 * $value when it is a text that is not empty, else the test stops.
 *
 * @param mixed $value The value.
 * @param string $what What it is.
 * @return string
 */
function text(mixed $value, string $what): string
{
    if (!is_string($value) || $value === '') {
        throw new \RuntimeException('Failed: ' . $what);
    }
    fwrite(STDOUT, "ok - {$what}\n");

    return $value;
}

/**
 * The first column of the first row $sql selects, as a text ("" when none).
 *
 * @param \PDO $pdo The database.
 * @param string $sql The query.
 * @return string
 */
function selectOne(\PDO $pdo, string $sql): string
{
    $statement = $pdo->query($sql);
    $value = $statement === false ? false : $statement->fetchColumn();

    return is_scalar($value) ? (string) $value : '';
}

$dir = $argv[1] ?? '';
$base = $argv[2] ?? '';
$db = $argv[3] ?? '';
[$dbHost, $dbPort] = array_pad(explode(':', $db, 2), 2, '3306');
$pdo = new \PDO("mysql:host={$dbHost};port={$dbPort};dbname=magento_e2e", 'magento', 'magento');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$state = $dir . '/var/sattleme-e2e';

// The key, stored encrypted.
$stored = selectOne($pdo, "SELECT value FROM core_config_data WHERE path = 'payment/sattleme/integration_key'");
check($stored !== '' && !str_contains($stored, 'sattleme_ck_'), 'the key is stored encrypted');
$merchant = selectOne($pdo, "SELECT value FROM core_config_data WHERE path = 'payment/sattleme/merchant'");
check(str_contains($merchant, 'E2E Shop'), 'the key was checked with the SDK when saved');

// A product, through the admin's REST API.
$admin = new Browser($base);
$token = text($admin->json('POST', 'index.php/rest/V1/integration/admin/token', [
    'username' => 'e2e',
    'password' => 'e2e-Pass-12345',
]), 'an admin token');
$auth = ['Authorization' => 'Bearer ' . $token];
$sku = 'e2e-ticket-' . bin2hex(random_bytes(4));
$product = $admin->json('POST', 'index.php/rest/default/V1/products', ['product' => [
    'sku' => $sku,
    'name' => 'E2E ticket ' . $sku,
    'attribute_set_id' => 4,
    'price' => 24.5,
    'status' => 1,
    'visibility' => 4,
    'type_id' => 'simple',
    'weight' => 1,
    'extension_attributes' => [
        'website_ids' => [1],
        'stock_item' => ['qty' => 100, 'is_in_stock' => true],
    ],
]], $auth);
$productId = is_array($product) && is_int($product['id'] ?? null) ? $product['id'] : 0;
// Its stock and price, indexed as Magento's cron or an admin's reindex would (a fresh install's
// indexers wait for a first full run).
passthru(
    'php ' . escapeshellarg($dir . '/bin/magento') . ' indexer:reindex cataloginventory_stock catalog_product_price',
    $exit
);
check($exit === 0, 'a product of 24.50 USD, in stock');

// The pages Varnish keeps stay cacheable with the module (Magento, set up for Varnish, sends them
// with public headers): its layouts mark as uncacheable only blocks of pages that already are
// (the checkout, an order's). A category's page lists its products through the search engine,
// which this Magento runs without.
$visitor = new Browser($base);
$pages = [
    'index.php/' => 'the home page',
    "index.php/catalog/product/view/id/{$productId}" => 'the product page',
];
foreach ($pages as $path => $what) {
    [$status, $headers] = $visitor->request('GET', $path);
    $cacheControl = $headers['cache-control'] ?? '';
    check($status === 200 && str_contains($cacheControl, 'public'), "{$what} is cacheable ({$cacheControl})");
}

$address = [
    'firstname' => 'Ada',
    'lastname' => 'Lovelace',
    'street' => ['Rue du Lac 1'],
    'city' => 'Lausanne',
    'postcode' => '1003',
    'country_id' => 'CH',
    'region_id' => 0,
    'telephone' => '0210000000',
    'email' => 'ada@example.com',
];
$regions = $admin->json('GET', 'index.php/rest/default/V1/directory/countries/CH', null, $auth);
$available = is_array($regions) && is_array($regions['available_regions'] ?? null) ? $regions['available_regions'] : [];
foreach ($available as $region) {
    if (is_array($region) && ($region['code'] ?? null) === 'VD') {
        $address['region_id'] = is_numeric($region['id'] ?? null) ? (int) $region['id'] : 0;
        $address['region_code'] = 'VD';
        $address['region'] = is_string($region['name'] ?? null) ? $region['name'] : 'Vaud';
    }
}

/**
 * A guest's order of the product, paid with the method: [the browser, the order's id].
 *
 * Placed as the storefront's checkout places it.
 *
 * @param string $base The storefront.
 * @param string $sku The product.
 * @param array<string,mixed> $address The address.
 * @return array{0: Browser, 1: int}
 */
function order(string $base, string $sku, array $address): array
{
    $browser = new Browser($base);
    [$status] = $browser->request('GET', 'index.php/');
    check($status === 200, 'the storefront answers');
    $cart = text($browser->json('POST', 'index.php/rest/default/V1/guest-carts'), 'a guest cart');
    $browser->json('POST', "index.php/rest/default/V1/guest-carts/{$cart}/items", [
        'cartItem' => ['sku' => $sku, 'qty' => 1, 'quote_id' => $cart],
    ]);
    $shipping = $browser->json('POST', "index.php/rest/default/V1/guest-carts/{$cart}/shipping-information", [
        'addressInformation' => [
            'shipping_address' => $address,
            'billing_address' => $address,
            'shipping_method_code' => 'flatrate',
            'shipping_carrier_code' => 'flatrate',
        ],
    ]);
    $methods = is_array($shipping) && is_array($shipping['payment_methods'] ?? null)
        ? $shipping['payment_methods']
        : [];
    $codes = array_map(
        static fn($method): string => is_array($method) && is_string($method['code'] ?? null) ? $method['code'] : '',
        $methods
    );
    check(in_array('sattleme', $codes, true), 'the checkout offers the method');
    $orderId = $browser->json('POST', "index.php/rest/default/V1/guest-carts/{$cart}/payment-information", [
        'email' => 'ada@example.com',
        'paymentMethod' => ['method' => 'sattleme'],
        'billingAddress' => $address,
    ]);
    if (!is_numeric($orderId)) {
        throw new \RuntimeException('Failed: the order is placed');
    }
    check(true, 'the order is placed');

    return [$browser, (int) $orderId];
}

/**
 * The order $orderId, through the admin's REST API.
 *
 * @param Browser $admin The admin's browser.
 * @param array<string,string> $auth Its token.
 * @param int $orderId The order.
 * @return array<mixed>
 */
function adminOrder(Browser $admin, array $auth, int $orderId): array
{
    $order = $admin->json('GET', "index.php/rest/default/V1/orders/{$orderId}", null, $auth);

    return is_array($order) ? $order : [];
}

// The first order: paid, the page finding it.
[$browser, $orderId] = order($base, $sku, $address);
check(adminOrder($admin, $auth, $orderId)['state'] === 'pending_payment', 'the order waits for its payment');
[$status, $headers] = $browser->request('GET', 'index.php/sattleme/payment/start');
$page = $headers['location'] ?? '';
check(
    $status === 302 && str_contains($page, '/sattleme/payment/view/order/' . $orderId . '/token/'),
    'the checkout leads to the payment page'
);
[$status, $headers, $html] = $browser->request('GET', $page);
check($status === 200, 'the payment page answers');
check(
    str_contains($html, '<svg') && str_contains($html, '59,000 sats') && str_contains($html, '$29.50'),
    'it shows the QR code and the amount'
);
check(
    str_contains($html, 'NOINDEX,NOFOLLOW') && str_contains($headers['cache-control'] ?? '', 'no-store'),
    'it is neither indexed nor cached'
);
preg_match('#<div class="sattleme-payment".*?<p class="sattleme-payment__brand">#s', $html, $own);
check(isset($own[0]) && !str_contains($own[0], '<script'), 'the page of the module holds no script');
preg_match('#"(http[^"]+/)requirejs/require\.js"#', $html, $static);
$script = ($static[1] ?? '') . 'Sattleme_Payment/js/payment-page.js';
[$status, , $js] = $browser->request('GET', $script);
check($status === 200 && str_contains($js, 'define('), 'its script is served by the shop: ' . $script);
preg_match('#"(http[^"]+/Sattleme_Payment/css/payment-page\.css)"#', $html, $style);
check(isset($style[1]) && $browser->request('GET', $style[1])[0] === 200, 'its style sheet too');
check(
    $browser->request('GET', str_replace('/token/', '/token/x', $page))[0] === 404,
    'the page does not open with a wrong secret'
);
$statusUrl = str_replace('/sattleme/payment/view/', '/sattleme/payment/status/', $page);
check($browser->json('GET', $statusUrl) === ['phase' => 'payable'], 'the status endpoint says payable');
$ref = trim((string) file_get_contents($state . '/last-ref'));
touch($state . '/paid-' . $ref);
sleep(7); // The page checks an invoice every 6 seconds at most.
check($browser->json('GET', $statusUrl) === ['phase' => 'paid'], 'the status endpoint finds it paid');
$paid = adminOrder($admin, $auth, $orderId);
check(
    $paid['state'] === 'processing' && is_numeric($paid['total_paid'] ?? null) && (float) $paid['total_paid'] === 29.5,
    'the order is processing, paid 29.50'
);
$filter = 'searchCriteria[filterGroups][0][filters][0]';
$invoices = $admin->json(
    'GET',
    "index.php/rest/default/V1/invoices?{$filter}[field]=order_id&{$filter}[value]={$orderId}",
    null,
    $auth
);
check(is_array($invoices) && ($invoices['total_count'] ?? 0) === 1, 'the order has one invoice');
[$status, , $html] = $browser->request('GET', $page);
check(str_contains($html, 'Thank you: your payment was received.'), 'the page thanks the customer');

// The second order: paid, Magento's cron finding it.
[$browser, $orderId] = order($base, $sku, $address);
[$status, $headers] = $browser->request('GET', 'index.php/sattleme/payment/start');
check($status === 302, 'its invoice is made');
$ref = trim((string) file_get_contents($state . '/last-ref'));
touch($state . '/paid-' . $ref);
$due = $pdo->prepare('UPDATE sattleme_payment_attempt SET next_check_at = ? WHERE ref = ?');
$due->execute([time() - 1, $ref]);
// Magento's cron runs the module's group (its schedule made by a first run, then due): the
// cron's runs, as the system's crontab starts them every minute (a job of each minute runs
// once): for up to two minutes.
for ($run = 0; $run < 24; $run++) {
    if (adminOrder($admin, $auth, $orderId)['state'] === 'processing') {
        break;
    }
    passthru('php ' . escapeshellarg($dir . '/bin/magento') . ' cron:run --group=sattleme_payment');
    sleep(5);
}
check(adminOrder($admin, $auth, $orderId)['state'] === 'processing', 'the cron found the second order paid');

// The third order: a virtual product's, which completes at once once paid, its payment found by
// Magento's cron alone (no page open): Magento's capture, its invoice, its "complete" state and
// its emails, rendered in the cron's process (no request, no session), and the module's note.
$virtualSku = 'e2e-voucher-' . bin2hex(random_bytes(4));
$admin->json('POST', 'index.php/rest/default/V1/products', ['product' => [
    'sku' => $virtualSku,
    'name' => 'E2E voucher ' . $virtualSku,
    'attribute_set_id' => 4,
    'price' => 24.5,
    'status' => 1,
    'visibility' => 4,
    'type_id' => 'virtual',
    'extension_attributes' => [
        'website_ids' => [1],
        'stock_item' => ['qty' => 100, 'is_in_stock' => true],
    ],
]], $auth);
passthru(
    'php ' . escapeshellarg($dir . '/bin/magento') . ' indexer:reindex cataloginventory_stock catalog_product_price',
    $exit
);
check($exit === 0, 'a virtual product of 24.50 USD, in stock');
$browser = new Browser($base);
[$status] = $browser->request('GET', 'index.php/');
check($status === 200, 'the storefront answers');
$cart = text($browser->json('POST', 'index.php/rest/default/V1/guest-carts'), 'a guest cart');
$browser->json('POST', "index.php/rest/default/V1/guest-carts/{$cart}/items", [
    'cartItem' => ['sku' => $virtualSku, 'qty' => 1, 'quote_id' => $cart],
]);
$methods = $browser->json('GET', "index.php/rest/default/V1/guest-carts/{$cart}/payment-methods");
$codes = array_map(
    static fn($method): string => is_array($method) && is_string($method['code'] ?? null) ? $method['code'] : '',
    is_array($methods) ? $methods : []
);
check(in_array('sattleme', $codes, true), 'the checkout of a virtual product offers the method');
$placed = $browser->json('POST', "index.php/rest/default/V1/guest-carts/{$cart}/payment-information", [
    'email' => 'ada@example.com',
    'paymentMethod' => ['method' => 'sattleme'],
    'billingAddress' => $address,
]);
if (!is_numeric($placed)) {
    throw new \RuntimeException('Failed: the virtual product\'s order is placed');
}
check(true, 'the virtual product\'s order is placed');
$orderId = (int) $placed;
[$status] = $browser->request('GET', 'index.php/sattleme/payment/start');
check($status === 302, 'its invoice is made');
$ref = trim((string) file_get_contents($state . '/last-ref'));
touch($state . '/paid-' . $ref);
$due->execute([time() - 1, $ref]);
// The cron's runs, as the system's crontab starts them every minute (a job of each minute runs
// once): for up to two minutes.
for ($run = 0; $run < 24; $run++) {
    if (adminOrder($admin, $auth, $orderId)['state'] === 'complete') {
        break;
    }
    passthru('php ' . escapeshellarg($dir . '/bin/magento') . ' cron:run --group=sattleme_payment');
    sleep(5);
}
$complete = adminOrder($admin, $auth, $orderId);
check(
    $complete['state'] === 'complete' && is_numeric($complete['total_paid'] ?? null)
    && (float) $complete['total_paid'] === 24.5,
    'the cron alone completed the virtual product\'s order, paid 24.50'
);
$number = selectOne($pdo, "SELECT increment_id FROM sales_order WHERE entity_id = {$orderId}");
check(
    selectOne($pdo, "SELECT email_sent FROM sales_order WHERE entity_id = {$orderId}") === '1'
    && selectOne($pdo, "SELECT email_sent FROM sales_invoice WHERE order_id = {$orderId}") === '1',
    'its confirmation and its invoice\'s emails were sent from the cron\'s process'
);
$files = glob($state . '/mail/mail.*');
$mails = array_map(
    static fn(string $file): string => (string) file_get_contents($file),
    is_array($files) ? $files : []
);
$about = array_filter($mails, static fn(string $mail): bool => str_contains($mail, $number));
check(count($about) === 2, "two emails of order {$number} reached sendmail");
$paidNotes = "SELECT COUNT(*) FROM sales_order_status_history WHERE parent_id = {$orderId} "
    . "AND comment LIKE '%paid in Lightning: 49,000 sats.%'";
check(selectOne($pdo, $paidNotes) === '1', 'its note of the payment, once');
check(
    selectOne($pdo, "SELECT CONCAT(is_final, outcome) FROM sattleme_payment_attempt WHERE ref = '{$ref}'") === '1paid',
    'its attempt is final, paid'
);
// Checked again (were it due): nothing more.
$pdo->exec("UPDATE sattleme_payment_attempt SET next_check_at = 1 WHERE ref = '{$ref}'");
passthru('php ' . escapeshellarg($dir . '/bin/magento') . ' cron:run --group=sattleme_payment');
check(selectOne($pdo, $paidNotes) === '1', 'a later run writes no second note');
check(
    selectOne($pdo, "SELECT COUNT(*) FROM sales_invoice WHERE order_id = {$orderId}") === '1',
    'nor a second invoice'
);
fwrite(STDOUT, "The end-to-end test passed.\n");
