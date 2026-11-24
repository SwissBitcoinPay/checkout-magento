# sattle.me for Magento

A payment module for [Magento Open Source](https://github.com/magento/magento2) 2.4: customers pay
their orders in bitcoin, in Lightning or on-chain, and the bitcoin goes straight to the merchant's
[sattle.me](https://sattle.me) wallet. It is built on the
[sattle.me checkout SDK for PHP](https://github.com/sattleme/checkout-php) (`sattleme/checkout`),
which makes and checks the invoices, and which the package holds: no sattle.me server, no API key
of a third party, and the integration key can make invoices that pay the merchant, never spend the
funds.

- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Payments and orders](#payments-and-orders)
- [Background checks](#background-checks)
- [Security](#security)
- [Privacy](#privacy)
- [Known limits](#known-limits)
- [Troubleshooting](#troubleshooting)
- [Development](#development)
- [Releases](#releases)
- [License](#license)

## How it works

1. At checkout, the customer chooses **Bitcoin** (or the title the merchant gives the method) and
   places the order. The order waits for its payment (Magento's state *Pending Payment*); its
   confirmation email waits too.
2. The customer lands on a payment page of the shop, in its theme and language. The module asks
   the SDK for the order's invoice (a Lightning invoice, a deposit address of the merchant's
   wallet, or both, depending on the amount: see
   [the SDK](https://github.com/sattleme/checkout-php#on-chain-payments)), and the page shows one
   QR code of it (BIP-21 with `lightning=`, or `lightning:`), a button that opens the customer's
   wallet with it, the Lightning invoice and the address to copy, the amount (in sats, in bitcoin
   and in the order's currency), and the time left to pay.
3. The page follows the payment on its own. Once the SDK reports it **paid**, the module registers
   the payment through Magento's own path for a payment the gateway confirms (the payment's
   capture): the order is invoiced and *Processing*, its confirmation and invoice emails are sent,
   and the page thanks the customer.

The page is rendered on the server (the QR code is an inline SVG); its small script, a file of the
module, only counts down, copies, and reloads the page when the payment moved on. Without
JavaScript, the page offers a button to check the payment. The customer's order view (in their
account, or the guest's order lookup) offers to pay on the page only while the order may get paid
there (no invoice yet, one that can be paid or whose rest can, or none that could be made); while
a payment is being checked or confirmed, it says so and links to the page that follows it, never
offering to pay; once an invoice ended underpaid, it says what came, as the page does, with no
link (no new invoice then).

## Requirements

- **Magento Open Source 2.4.7, 2.4.8 or 2.4.9**, the release lines Adobe supports on 2026-11-23
  (Adobe Commerce's lifecycle: standard support until 2027-05-31, 2028-05-31 and 2029-05-31), on
  the PHP versions each one's Composer constraint takes from **8.2** (the SDK's oldest): 2.4.7 on
  PHP 8.2 and 8.3, 2.4.8 on PHP 8.2 to 8.4, 2.4.9 on PHP 8.3 to 8.5. Adobe's system requirements
  name 8.2 and 8.3 for 2.4.7-p10, 8.3 and 8.4 for 2.4.8-p5 and 8.5 for 2.4.9: the pairs the tests
  run on (and 2.4.7 on 8.3, in Docker), on 2.4.7-p10, 2.4.8-p5 and 2.4.9, whose sources the APIs
  the module uses were read in. PHP 8.2 receives security fixes until 2026-12-31. Adobe Commerce
  runs the same modules, but was not tested.
- PHP's `bcmath`, `hash`, `iconv`, `intl`, `json`, `openssl` and `pcre` extensions (those the
  module and the libraries it embeds need, which its `composer.json` requires), 64-bit, and
  OpenSSL with the secp256k1 curve (the
  [SDK's requirements](https://github.com/sattleme/checkout-php#requirements), which the module
  checks: the method is not offered on a server that lacks them).
- **Magento's cron** running (`bin/magento cron:install`, or the system's crontab running
  `bin/magento cron:run` every minute), as on any production Magento: it checks the payments in
  the background, and cancels the orders left unpaid.
- A merchant account of sattle.me, and an **integration** created in its app.
- **Outbound connections** from the Magento server (never from the customer's browser), direct:
  the SDK does not use the environment's proxy settings.
  - The merchant's **Nostr relays**, over WebSocket (TLS): those the integration key names, or the
    merchant's app.
  - The merchant's **Lightning address** (breez.tips for a sattle.me account) and the URLs its
    provider gives (its callback, its verify URLs), over HTTPS.
  - The **rate sources**, one after the other until one answers: CoinGecko, mempool.space,
    Coinbase, Kraken, OpenNode.
  - For an invoice with a deposit address, the explorer: **mempool.space**, or the **merchant's
    own Bitcoin node** when the merchant's app names one (over HTTPS, or plain HTTP for a node on
    the merchant's local network), with mempool.space as its fallback unless the merchant chose
    none; and **api.blockcypher.com** for a transaction no check saw in time.

  Every request, and the handshake of each WebSocket, says `User-Agent: sattleme-Checkout/1`.

## Installation

The module is distributed on [Packagist](https://packagist.org/packages/sattleme/module-payment)
(`sattleme/module-payment`), which Composer reads by default. From Magento's root:

```shell
composer require sattleme/module-payment
bin/magento module:enable Sattleme_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile                 # in production mode
bin/magento setup:static-content:deploy      # in production mode
bin/magento cache:flush
```

The package holds the libraries the module uses (the SDK, a QR code encoder), under names of its
own (see [Development](#development), Dependencies): Composer installs nothing else with it, and
another module's or Magento's own copies of them never interfere. To update the module: `composer
update sattleme/module-payment`, then the same `bin/magento` commands from `setup:upgrade`.

### Uninstalling

Magento's declarative schema drops the tables of a module it no longer finds enabled: once the
module is disabled (`bin/magento module:disable Sattleme_Payment`), the next `bin/magento
setup:upgrade` drops `sattleme_payment_attempt` and `sattleme_payment_order`, with every row
(checked on Magento 2.4.9). Lost with them: each invoice as the SDK made it (the Lightning invoice
and its payment hash, the deposit address), what its checks found (the transactions, the proof of
payment: the preimage), and the background checks of the invoices that may still be paid. What
stays, with each order: its history (each invoice's reference, amount in sats, methods and end; what
came, and how it ended), its payment's capture (the reference as its transaction) and the page's
secret. The sattle.me app keeps its sales.

To keep the rows, run that `setup:upgrade` with Magento's safe mode, which writes each table it
drops to `var/declarative_dumps_csv/` first; once the module is enabled again, Magento's data
restore puts the rows back:

```shell
bin/magento module:disable Sattleme_Payment
bin/magento setup:upgrade --safe-mode=1    # var/declarative_dumps_csv/sattleme_payment_attempt.csv
# later, to install it again:
bin/magento module:enable Sattleme_Payment
bin/magento setup:upgrade --data-restore=1
```

Or keep a copy of both tables with the database's own tools before.

## Configuration

1. In the sattle.me app, create an integration for your shop and copy its secret key
   (`sattleme_ck_…`).
2. In Magento's admin, open **Stores → Configuration → Sales → Payment Methods → Bitcoin
   (sattle.me)**, set **Enabled** to *Yes*, and enter the **Integration key**. It is checked when
   you save it: the SDK refuses what is not an integration key, then reads the integration on the
   merchant's relays, so that the key of an integration removed in the app, or of one the relays
   do not know, is refused with a message. Once saved, it is never shown again (the field shows
   `******`); **Connected to** then shows the business the key reached, its Lightning limits and
   its deposit addresses. The key can be set per website.
3. **Time to pay (minutes)**: how long the customer has to pay an invoice, 1 to 60 minutes (15 by
   default). An invoice never ends after Magento's cron would cancel the order as unpaid: see
   *Pending Payment Order Lifetime* in **Stores → Configuration → Sales → Sales → Orders Cron
   Settings** (480 minutes by default).
4. Optionally, the **Title** and the **Description** shown at checkout, per store view (in each
   one's language). Without them, the method is called "Bitcoin", and the checkout says how a
   bitcoin payment goes, in the store's language.
5. Magento's usual settings of a payment method apply as well: the countries, the minimum and
   maximum order totals, the sort order.

Change the key only while no bitcoin payment is pending: each check uses the key set at its time,
and an invoice with a deposit address made under another integration's key would be judged without
its records in the merchant's registry (its lease, its tolerance, the invoices of its rest).

**No test mode.** sattle.me has no test network: payments are real bitcoin payments, to the
merchant's wallet. The settings' page says so.

The module is translated into English, French, German and Italian (`i18n/`: `fr_FR`, `fr_CH`,
`de_DE`, `de_CH`, `it_IT`, `it_CH`), its notes on an order in the language of the order's store,
and their times in that store's time zone (*Stores → Configuration → General → General → Locale
Options → Timezone*), which the note names; the admin's view of a payment shows its times as
Magento's admin shows its own dates (`formatDate()`, in the configured time zone).

## Payments and orders

| What the SDK reports                     | What the module does                                                                  |
| ---------------------------------------- | ------------------------------------------------------------------------------------- |
| An invoice was made                      | Noted on the order (*Pending Payment*); the payment page shows it.                    |
| `open`                                   | Waits. Another method only once a check after its end finds it expired, unpaid.       |
| `open` with `received` below `sats`      | The page shows what came and a QR code for the rest (the SDK asks for it); noted.     |
| `unconfirmed`                            | Waits for 3 confirmations (about 30 minutes), keeping the order from being cancelled. |
| `paid` (Lightning, on-chain, or both)    | Registers the payment: the order is invoiced and *Processing*, its emails sent.       |
| `paid`, the order owing another amount   | Puts the order **on hold**, noting what came and what the order owes now (below).     |
| `paid` with `received` other than `sats` | Notes both on the order: the merchant's tolerance or acceptance, or an overpayment.   |
| `expired`                                | Cancels the order (its stock comes back): the customer can order again. Final.        |
| `expired` with `received` above 0        | Puts the order **on hold**, noting what reached the wallet (below). Final.            |

- **Only a `paid` status completes an order**, from the SDK's own check of the invoice the order's
  attempt holds, on the server: never because the customer's browser says so. It is registered
  once, through Magento's own capture of a payment the gateway confirmed
  (`Payment::registerCaptureNotification()`), with the attempt's reference as the transaction's
  id, whichever check sees it first: the page's, the button's or the cron's.
- **One attempt, one reference.** Each attempt to pay an order gets a random reference (128 bits
  from the CSPRNG, after the order's number), stored before the SDK is asked for its invoice, and
  never used for another attempt: no deleted test order, restored backup or copy of the shop can
  share it. A try that follows a failed one (a timeout, a worker stopped) asks for the same
  reference: the SDK then hands out the invoice it may have made meanwhile, never a second one
  (shown once a check found it open), or reports it paid, which completes the order.
- **The order locked.** The invoice is made with the order locked by Magento's lock manager: two
  requests at once (a double click, two PHP workers) get one invoice; the second waits for the
  first. Each payment is applied in Magento's own row lock of the order too (the one Magento's
  cancellation takes), so that an admin's change meanwhile counts. Every lock provider of Magento
  (`lock/provider` in `env.php`) works; they differ only when a PHP process dies holding the lock
  (killed, out of memory): with `db` (the default: the database's `GET_LOCK`), `file` (`flock`)
  and `zookeeper` (an ephemeral node, gone with its session), the lock goes with the process.
  With `cache`, Magento keeps it for 7200 seconds (`Lock\Backend\Cache`), or until the
  configuration cache is cleaned (`bin/magento cache:clean config`); meanwhile, for that order
  only, the payment page makes no invoice (it says to try again later), *Pay another way* does
  nothing (the page stays as it was), and its checks fail and are tried again, 30 minutes apart
  at most: a payment that came is recorded by the first check once the lock is gone. Use `db`,
  the default, unless the shop has a reason for another provider. No lock keeps an order from
  being cancelled (below).
- **One payable invoice per order.** A Lightning invoice cannot be revoked: until the invoice of
  the order is known to have ended unpaid, the customer gets no other invoice and cannot leave it
  for another method, and Magento cannot cancel the order (the module answers *no* to
  `Order::canCancel()`), neither the admin, the customer, nor the cron that cancels the orders
  left unpaid. Known from its final status only, `expired` with nothing received, which a check
  after its end (its grace period of 120 seconds included) gives: its time being over is not
  enough, a payment of its last seconds or an on-chain one dated in time is found by that check,
  however late it comes. An invoice that could not be made at all (the network, a key the SDK
  refuses) was never shown: the page then offers to try again, or to choose another method, which
  cancels the order and gives the customer back their cart. Nor can the order be cancelled while a
  request makes its invoice (a first one, or one asked again after a failure), nor an invoice be
  made while the order's cancellation is carried out: the request marks the order, in the module's
  table `sattleme_payment_order`, until the SDK's call's limit and a margin (20 + 10 seconds), and
  clears the mark once the invoice is stored; `Order::registerCancellation()`, where Magento carries
  out every cancellation (through `Order::cancel()` for the admin's, the customer's, the cron's and
  the REST API's; directly in PayPal's paths), marks it in the same row for 60 seconds, which the
  order's save follows. Each mark is set by one update of that row that requires the other mark to
  be over, so that the two never both start, whatever the lock provider, and both are read by the
  database's clock, so that web servers whose clocks differ never shorten them. A cancellation the
  module refuses is refused before the order changes, wherever it comes from, so that nothing
  Magento does after a cancellation is done: Magento's order cancellation tells the customer why
  ("This order's bitcoin invoice is being made: it cannot be cancelled now. Try again in a minute.",
  "A bitcoin payment of this order may still come: it cannot be cancelled now.", or "Bitcoin came
  for this order: only the shop can cancel it.") and sends no e-mail; Magento's order management
  (the admin's cancel and mass cancel, the cron, the REST API) answers that the order was not
  cancelled. A request that dies keeps no order from being cancelled, nor from being paid, past its
  mark. An invoice that ends unpaid while another invoice of the order is being made does not cancel
  the order: its note says only that it expired unpaid, the order stays *Pending Payment* for the
  new invoice, and if none is made, Magento's cron cancels it once its *Pending Payment Order
  Lifetime* is over. The order is read again once its invoice is made: an invoice made while the
  order left the states it may be paid in (cancelled, or invoiced otherwise by an admin, by a path
  that asks no one) is never shown; the order's history names its status, and the invoice is checked
  as any other, so that a payment of it is noted on the order and told to the merchant. That read
  also covers the two bounds that can be overrun: the SDK's limit of 20 seconds is not a hard one (a
  DNS lookup blocks outside it), and a cancellation could be saved more than 60 seconds after it was
  carried out (its e-mail sent first, to a mail server slow to answer). What remains: a cancellation
  saved after that read, and more than 60 seconds after it was carried out, leaves an invoice shown
  for an order cancelled meanwhile; a payment of it is noted on the cancelled order and told to the
  merchant, as above.
- **No cancellation once bitcoin came.** Once an order was paid in bitcoin, or something came for
  it (an underpayment, a payment being confirmed, one the checks gave up on), the sats are in the
  merchant's wallet: neither the customer nor Magento's cron of orders left unpaid can cancel it,
  even once the merchant released it from hold (*Pending Payment* again), only the admin, who
  settles it. Magento's own order cancellation by the customer (*Stores → Configuration → Sales →
  Sales → Order cancellation*, off by default; its button in the customer's account and its
  GraphQL mutations) reads an order's state only, and would have a paid order refunded offline:
  the module has it answer as `Order::canCancel()` does for this method's orders, so that its
  button is not shown and its mutations refuse. The REST API's cancellation is refused too.
- **An order changed after its invoice.** When an invoice is found paid, the module compares its
  price with what the order owes then, in its currency: its total, less what it was paid
  otherwise (an invoice an admin captured, say), less what was refunded. When they differ, the
  order is never completed automatically: it is put **on hold** (Magento's state for an order the
  merchant must look at), its history notes the amount received, the invoice's price and what the
  order owes now, and the admin's notifications say it needs attention. The merchant settles the
  difference with the customer, then invoices the order (offline) or cancels it. Magento itself
  does not let an admin edit an order paid with this method (`can_edit` is off).
- **Deadlines.** An invoice never outlives the moment Magento's cron would cancel the order (and
  free its stock): its last change plus the store's *Pending Payment Order Lifetime*. The module
  asks for an invoice that ends, its grace period and the time to make it included, by then,
  refuses one that would not (the clock of the merchant's Lightning address being off, say), and
  makes none when less than about 3 minutes are left. While an invoice may still be paid or
  confirmed, the order cannot be cancelled anyway.
- **Underpayments.** When less than the amount comes on-chain in time, the page shows the rest, as a
  Lightning invoice the SDK asks for. The merchant may accept an on-chain payment a little short of
  the amount (its tolerance, set in the sattle.me app): the SDK then reports it `paid`, and the
  order's history notes what came; so it does when the merchant accepts, in the sattle.me app, what
  an underpaid order received (the merchant's acceptance), the order *Processing*, never on hold for
  the difference alone. An invoice that ends underpaid all the same did not end unpaid: what came
  reached the wallet, and paying the order again would pay it twice. The order is put **on hold**,
  its history notes what came, the admin is notified, the customer's page asks them to contact the
  shop, and the merchant settles the difference.
- **An order cancelled, then paid.** The module keeps an order from being cancelled while its
  invoice may be paid, but an order can still be cancelled around it (in the database, by another
  module). When its invoice is then found paid, the order is never captured, and it stays
  cancelled: Magento takes no order out of that state (`Order::canHold()`, `canInvoice()` and
  `canUnhold()` refuse it), so it cannot go on hold as an order that changed does. Its history
  notes the sats received, the invoice's price, and that the bitcoin reached the wallet, and the
  admin's notifications say it needs attention: refund the customer from the wallet, or settle
  with them by hand.
- **Late payments.** A payment counts when it comes by the invoice's end, its grace period
  included. An on-chain payment broadcast after that reaches the merchant's wallet (the address is
  the wallet's) but pays no order: the SDK reports the invoice `expired` without it, and the order
  is cancelled. The customer contacts the shop, who finds it in the wallet (or on mempool.space,
  the address being in the payment's information) and settles it by hand.
- **Refunds** are not automatic: a Lightning payment can only be refunded to an invoice of the
  customer's, an on-chain one to an address of theirs, which Magento never asks. Refund the
  customer from the wallet, then record it in Magento with an offline credit memo.
- **The admin's view of a payment** (the order's *Payment Information*): for each attempt, its
  reference and state, the amount in sats and in the order's currency, the exchange rate and its
  source, what came (linked to the address's transactions on mempool.space when the status lists
  none, as after an underpayment), the end of the invoice, the payment hash, the address and the
  transactions (linked to mempool.space), each proof of payment (preimage) with the payment hash it
  proves (its SHA-256), the last check and the next one, a first check that failed said as such,
  and its error in plain words. The order's history logs the invoices made, the payments being
  confirmed, the underpayments and the payments, in the language of the order's store. A note says
  how a payment came only when the SDK's status tells it: a payment's (`paid`) says what came (in
  Lightning, the invoice's amount; on-chain or both, what the status received), how (in Lightning,
  on-chain, or both), and the amount asked when they differ. A payment being confirmed
  (`unconfirmed`) says what came in time, of what the invoice asks, and that its on-chain payment is
  being confirmed; a part of the amount (`open`), what came in time and that the rest is asked.
  Neither says it reached the wallet: until it is confirmed, an on-chain payment may still fail. An
  invoice that ended underpaid says that what came reached the wallet. A payment accepted short is
  noted as accepted "by your tolerance or by your acceptance of what came": the status does not tell
  which.
- The method is offered only when an integration key is set and the SDK takes it on this server,
  for a cart in a currency the SDK converts and whose total is a price the SDK takes: no more
  decimals than that currency has (the price is never rounded), and at most 2^53 − 1 of its minor
  units (SPEC.md §25 of the SDK), within Magento's own rules (countries, totals). It is not
  offered in the admin's order creation, nor in the multi-address checkout (which makes several
  orders).

## Background checks

The SDK has no webhooks: payments are checked, by the module, on the Magento server.

- While the customer's payment page is open: its script asks the server every 3 seconds (every 30
  seconds while a payment is being confirmed), and the server checks the invoice at most every 6
  seconds.
- In the background, by Magento's cron: the module's job runs every minute, in a cron group of its
  own (`sattleme_payment`, in a process of its own: a long run of Magento's other jobs never delays
  it). Each invoice is checked at each run while it can be paid (every minute), once 75 seconds
  before the end of its grace period and once 10 seconds after (an on-chain payment a check lists
  by then came in time), then every 5 minutes until its final status is known (an on-chain payment
  being confirmed). After failed checks, once the invoice has ended: one minute, then twice as long
  each time, up to every 30 minutes. Each wait is counted from the start of the run that checked,
  less 10 seconds, so that a check that takes its time never makes an invoice miss the next run. A
  run checks the invoices that are due, the longest due first, 20 at most, each for 20 seconds at
  most, and starts no check after 45 seconds.
- An invoice whose final status is still unknown 90 days after it was made (an on-chain payment
  made in time, still unconfirmed on a congested network; checks that kept failing) is no longer
  checked: its order is put on hold, with a note, and the admin is notified. Check the payment in
  the sattle.me app and settle the order by hand.

If Magento could not take a payment (the database goes away, another module reacting to the
payment fails), the attempt is not final: the next check registers it again, as a whole. A check
that stopped once the payment was registered (before the order's emails, say) is finished by the
next one: the emails not sent yet are sent, the payment is never registered twice, and its note
is written once. The background checks run in Magento's `crontab` area, with no request or
session: the module's notes are written in the order's store's language from its own
dictionaries, and Magento's own emails and capture run as Magento runs them from its cron (the
end-to-end test completes a virtual product's order, which completes at once, from the cron
alone).

## Security

- **The integration key** is stored in Magento's configuration, **encrypted** with the
  installation's key (`crypt/key` of `app/etc/env.php`: Magento's `Encrypted` backend), and
  declared sensitive: `bin/magento app:config:dump` writes it to `env.php`, never to
  `config.php`. It never goes back to the browser (the settings' field is Magento's *obscure*
  field), and never in a log, an exception, a page, the checkout's configuration or a support
  dump; the SDK never puts it, or a key derived from it, in a message or a representation.
- **One key, one shop.** Create an integration for your shop, and use its key nowhere else. If it
  leaks, remove the integration in the sattle.me app and create a new one: the key can make
  invoices that pay you, never spend your funds.
- **The payment page** opens with a secret of the order (256 random bits made on the server when
  the order is placed, kept with its payment), compared in constant time: the order's number, or
  its id, never opens it. Like Magento's own order links, the page's link is the customer's. The
  page and its status endpoint are `noindex`, never cached (by Magento's page cache nor the
  browser's), send no referrer, and use no inline script; the page loads nothing from another
  host. Its forms (check, try again, choose another method) carry Magento's form key. Nothing the
  browser sends decides an amount, a currency, a reference or a status.
- **Outbound requests.** The SDK connects to hosts the merchant chooses (its relays, its Lightning
  address, its Bitcoin node). It refuses `localhost`, follows no redirects and checks TLS, and
  refuses IP addresses but for the merchant's Bitcoin node, which may be an IPv4 address and, on a
  local network (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10, a name under `.local`),
  plain HTTP (the [SDK's rule](https://github.com/sattleme/checkout-php#the-merchants-bitcoin-node)).
  A name may still lead to an address of your own network: if your Magento server sits in a network
  with services of its own, restrict what it can reach to public addresses (a node the server then
  cannot reach is read as one that does not answer).
- Report a vulnerability privately: see [SECURITY.md](SECURITY.md).

## Privacy

- **What the module stores**, in Magento's database: in its table `sattleme_payment_attempt`, each
  attempt's invoice as the SDK made it (its reference, amounts in sats and in the order's currency,
  exchange rate, Lightning invoice and, from 1,000 sats, a deposit address of the merchant's wallet)
  and what its checks found (its state, what came, the confirmations, the transactions, the proof of
  payment); with the order's payment, the page's secret and the reference that paid it; in its table
  `sattleme_payment_order`, two times per order (until when its invoice is being made, or its
  cancellation carried out). No name and no email address: the customer's are Magento's own. Its
  log, `var/log/sattleme_payment.log`, names orders and references, never a key.
- **What the Magento server sends**, and to whom:
  - the merchant's **Nostr relays**, encrypted for the merchant (NIP-44): each invoice, with its
    price and its note (the store's name and the order's number), which the merchant's app shows;
  - the merchant's **Lightning address** (breez.tips for a sattle.me account): asked for each
    invoice, its amount and, when the address accepts comments, its note, then for its payment;
  - the **rate sources**, one at a time until one answers: asked for the price of bitcoin;
  - the explorer, for an invoice with an address: the address and its transactions, and the
    chain's height. It is **mempool.space**, or the **merchant's own Bitcoin node** when the
    merchant's app names one; then mempool.space only when the node does not answer, unless the
    merchant chose no fallback. **api.blockcypher.com**: a transaction, only for one no check saw
    in time.

  Each of them sees the Magento server's IP address, never the customer's: the customer's browser
  talks to the shop only.
- **Exporting and erasing personal data.** Magento Open Source has no framework of its own for
  them: the module gives a command, for the merchant to answer a customer's request (by their
  email address, all their orders, or by an order's number):

  ```shell
  bin/magento sattleme:payment:privacy export --email=customer@example.com   # JSON
  bin/magento sattleme:payment:privacy erase --order=000000042
  ```

  Erasing removes what leads to the customer's coins on the blockchain: the deposit address and
  the transactions (and the payment URIs that hold the address). The references, amounts,
  exchange rates, dates and states stay, for the merchant's accounting; so do the Lightning
  invoices and their proofs, which name the merchant's node, never the customer's. A payment still
  being checked then loses them once final. Erasing the order itself, its addresses and its
  history, is Magento's (or the merchant's) to do. What the merchant's relays received stays
  theirs: the merchant's app keeps its sales.

## Known limits

- **No proxy.** The SDK's connections do not use the environment's proxy settings: the Magento
  server needs direct outbound connections.
- **Currencies.** The order's currency (the one the customer sees and pays in) must be one the SDK
  knows (its table of currencies) and one its rate sources price; else the method is not offered.
  An amount the SDK cannot convert, or out of the merchant's limits, is refused when the invoice
  is made, and the page offers another method.
- **Confirmations.** An on-chain payment is paid at 3 confirmations on the explorer read (the
  merchant's Bitcoin node, or mempool.space), about 30 minutes: the order waits for its payment
  until then.
- **The merchant's Bitcoin node.** A node the merchant names in its app with no fallback is the
  only explorer: while it does not answer, invoices get no deposit address (Lightning only, or none
  for an amount payable on-chain only), and the checks of an invoice with one fail and go on (the
  order waits, never cancelled for want of an answer). A node on the merchant's local network is
  reachable only from a Magento server on that network.
- **An underpayment accepted late.** An invoice the SDK reports `expired` is final: the order is
  cancelled, or on hold when something came. In one rare case
  ([SPEC.md §3](https://github.com/sattleme/checkout-spec/blob/main/SPEC.md#3-data-model)), the
  merchant accepted in the sattle.me app what an underpaid order received, but a relay that holds
  the acceptance fails at the check after the lease's end: that check gives `expired`, where a later
  one would give `unconfirmed`, then `paid`. The module does not check an expired invoice again: the
  order shows as cancelled (or on hold) although the merchant accepted the underpayment in the app,
  and the merchant settles the order by hand.
- **Time.** The invoice is made in the customer's request that follows the order's placement,
  bounded at 20 seconds, after 5 at most waiting for another request on the same order (25 in all
  at most); the page's checks are bounded at 15 seconds, the cron's at 20. The SDK's bounds leave
  DNS lookups out (PHP's resolver blocks): a server whose resolver is slow can overrun them.
- **Deposit addresses.** Each invoice of 1,000 sats or more may lease an address of the
  integration's lot for its time: many orders left unpaid at once can empty it, the next ones are
  then payable in Lightning only until the merchant opens the app (see the SDK).
- **Checkouts.** The payment method's renderer is one of Magento's own checkout (the Luma and
  Blank themes, and those built on them). A checkout of another kind (a headless storefront on
  GraphQL, Hyvä's) places the order but does not send the customer to the payment page: its link
  is in the customer's order view.

## Troubleshooting

The order's *Payment Information* and its history say what went wrong, in plain words; the
module's log, `var/log/sattleme_payment.log`, says it too, without the key.

- **The key is refused when saving the settings.**
  - "This is not the secret key of a sattle.me integration": copy the whole key from the app,
    `sattleme_ck_` included.
  - "This integration was removed in the sattle.me app": create a new integration, and enter its
    key.
  - "This integration could not be found on its relays": the merchant's relays do not hold it
    (yet): open the sattle.me app, check the key, and try again.
  - "This integration needs a newer version of sattle.me for Magento": update the module.
  - "sattle.me could not be reached to check this key": the Magento server could not reach the
    merchant's relays or its Lightning address. Check its outbound connections
    ([Requirements](#requirements)): direct, without a proxy.
- **The method is not offered at checkout.** It is enabled and has a key, for the store's website?
  The log says when the SDK refused the key or the server's PHP. The cart's currency must be one
  the SDK converts, its total within the method's minimum and maximum, the address in its
  countries.
- **"Bitcoin payments are not available right now"** on the payment page: the payment's
  information says why: an integration key no longer valid or removed, the integration not found
  on its relays, the merchant's Lightning address answering wrongly, or a clock that is off (the
  Magento server's: keep it synchronized).
- **"We could not create your Bitcoin invoice right now"**: the relays, the Lightning address or
  the rate sources did not answer in time, or the amount can only be paid on-chain and no deposit
  address of the integration was free (the merchant opens the app to give it more). The customer
  tries again: check the server's outbound connections.
- **"This order cannot be paid with Bitcoin"**: the SDK refused the amount or the currency
  ([Known limits](#known-limits)).
- **A payment stays pending.** The payment's information shows its last check and its next one. No
  check at all: Magento's cron must run (`bin/magento cron:run`, the group `sattleme_payment`). An
  on-chain payment waits for 3 confirmations, about 30 minutes, longer with a low fee. "The checks
  of Bitcoin invoice … stopped": check the payment in the sattle.me app, and settle the order by
  hand.
- **An order on hold, "… ended underpaid"** or **"… The order now owes …"**: see
  [Payments and orders](#payments-and-orders); settle the difference with the customer, release
  the order from hold, then invoice it (offline) or cancel it.
- **A customer paid after the invoice ended**: the order was cancelled, as the SDK reports the
  invoice expired (see Late payments): the bitcoin reached the wallet, and pays no order. Settle it
  with the customer by hand.
- **The payment page has no style or no script**: the static files were not deployed
  (`bin/magento setup:static-content:deploy` in production mode).

## Development

The module's sources are the repository's root (as Composer installs a Magento module);
`Test/` holds its tests, `tools/` its tools, `sdk/` the pinned SDK: none of them is in the
package. The package holds `Block/`, `Console/`, `Controller/`, `Cron/`, `Embedded/`, `Gateway/`,
`Model/`, `Observer/`, `Plugin/`, `Sdk/`, `ViewModel/`, `etc/`, `i18n/` and `view/`,
`composer.json`, `registration.php`, `LICENSE`, `README.md`, `CHANGELOG.md` and `SECURITY.md`,
and, in `vendor/`, the libraries it embeds (below), each with its licence:
`vendor/sattleme/checkout/`, `vendor/bacon/bacon-qr-code/` and `vendor/dasprid/enum/`.

Composer installs the package from a tag's git archive, which Packagist distributes:
`.gitattributes` keeps the tests, the tools, the pinned SDK and the CI out of it, and git tracks
`vendor/` as `tools/embed.php` builds it. `php tools/embed.php vendor` builds the embedded
libraries, checks them (below) and writes `vendor/`; commit it after any change of
`tools/embed/composer.lock` or `sdk/`.

- **The coding standard and PHPStan**, from `tools/dev/composer.lock`
  (`composer install --working-dir=tools/dev`): `tools/dev/vendor/bin/phpcs` runs Magento's coding
  standard (`magento/magento-coding-standard`, `phpcs.xml.dist`) on the repository, warnings
  included; `SATTLEME_MAGENTO_ROOT=<a Magento> php tools/dev/vendor/bin/phpstan analyse` runs
  PHPStan at its strictest level (`max`, its strict rules, and Magento's extension
  `bitexpert/phpstan-magento`) on the module, its tests and its tools, against that Magento's own
  sources (one prepared as below).
- **The package**: `php tools/build.php build` (PHP, git, Composer and curl) writes the ZIP of
  the module, `build/dist/sattleme-module-payment-<version>.zip`, from the files git tracks and
  the embedded libraries it builds (`tools/embed.php`: a clean checkout of a commit gives the same
  bytes, on any PHP version the module supports), and fails unless the `vendor/` git tracks is
  that build, byte for byte. It checks the ZIP as Adobe Commerce Marketplace's technical review
  checks a package, a bar the package keeps meeting (its `composer.json`, `etc/module.xml` and
  `registration.php`, no to-do marker, nothing but the module, at most 30 MB), and that no code of
  it names the embedded libraries without their prefix. `php tools/embed.php check` then compares
  the embedded libraries with their originals (below). The Marketplace's code sniffer on the
  package: `phpcs --standard=Magento2 --extensions=php,phtml --error-severity=10
  --ignore-annotations`, which scans the embedded libraries too. The CI then checks that the ZIP
  holds exactly the files of the commit's `git archive`, byte for byte: what Composer installs
  from Packagist is what the tests install.
- **A Magento to test in**, without Docker nor root: `sh tools/tests/magento.sh prepare <tag> <dir>`
  clones Magento's repository at a release's tag (2.4.7-p10, 2.4.8-p5, 2.4.9), installs its lock,
  and installs the module from the package with Composer, the embedded libraries with it: every
  test runs on them, as shops get them. After a change, `php tools/build.php build` and
  `sh tools/tests/magento.sh module <dir>` put the new package's files in place.
- **The unit tests**, in Magento's unit test framework: `sh tools/tests/magento.sh unit <dir>`.
- **The integration tests**, in Magento's integration test framework, on MariaDB 11.4:
  `sh tools/tests/magento.sh integration <dir>` starts MariaDB without root
  (`tools/tests/mariadb.sh`: MariaDB's own packages, checked against their SHA-256, extracted
  under `$TMPDIR`), installs Magento in the framework's sandbox (without a search engine: the
  module never searches), and runs `Test/Integration`: orders placed through Magento's checkout,
  the SDK faked at the module's seam (`Sdk\ClientProviderInterface`) and the clock faked, every
  status and error of the SDK, the order's lock against another process holding it in the
  database, the deadlines, the payment page and its endpoints through Magento's front
  controller, the settings saved as the admin saves them, the cron's schedule, the privacy
  command, the translations.
- **The end-to-end test**: `sh tools/tests/e2e.sh <dir> <db host:port>` installs that Magento,
  compiles it (`setup:di:compile`), deploys the storefront's static files, puts it in production
  mode, sets the key with `bin/magento config:set`, then, on PHP's built-in server, checks that
  the home page and a product's are still cacheable, places a guest's orders through
  the storefront's own API, opens the payment page, and has the payment found by the page's
  status endpoint and by Magento's cron, then a virtual product's order (complete at once once
  paid) completed by the cron alone, its emails rendered and handed to a stand-in of sendmail
  (PHP's `sendmail_path`), the orders read back through Magento's REST API. The SDK is
  replaced there by a fake, through a test-only module (`Test/E2e/Sattleme_PaymentE2e`, which the
  package never holds: the module has no switch for it). `sh tools/tests/e2e-docker.sh <dir>
  <php binary>` runs it in Docker containers (MariaDB's and Ubuntu's official images).
- **The CI** (`.github/workflows/ci.yml`) runs all of this: the checks and PHPStan on one job;
  the unit, integration and end-to-end tests on Magento 2.4.7-p10 (PHP 8.2), 2.4.8-p5 (PHP 8.3
  and 8.4) and 2.4.9 (PHP 8.5), and the end-to-end test in Docker on 2.4.7-p10 and 2.4.9, on
  GitHub-hosted runners.
- **The translations**: `php tools/i18n.php en` writes `i18n/en_US.csv` from the sources' phrases
  (those Magento's own collector finds), and `php tools/i18n.php check` checks that each
  dictionary translates exactly those phrases, with the same placeholders.
- **Dependencies.** The package requires PHP, its extensions and Magento's modules only (from
  2.4.7), as constraints each shop's Composer resolves. The libraries the module uses are in the
  package, never Composer dependencies: a version of them required beside the module could
  conflict with another module's, or with the QR code library Magento's two-factor
  authentication requires. They are locked exactly in `tools/embed/composer.lock`: the SDK
  `sattleme/checkout` 1.0.0 (MIT, from `sdk/`), `bacon/bacon-qr-code` 3.1.1 and its
  `dasprid/enum` 1.0.7 (both BSD-2-Clause). `tools/embed.php` prefixes them with
  `Sattleme\Payment\Vendor` (PHP-Scoper 0.18.20, its signed PHAR checked against
  its SHA-256), so that no other copy of them (another module's, or the QR library Magento's
  two-factor authentication installs) is ever loaded in their place; the module's `composer.json`
  autoloads them from `vendor/`. Adobe's code sniffer refuses some of their code, and the build
  changes it where nothing it does changes: the patches below. `php tools/embed.php check` (in CI)
  compares each embedded file with its original (`sdk/`, and the lock's) token by token, after
  printing the original as PHP-Scoper prints the files it writes (and checking that the reprint
  has the same syntax tree): nothing differs but the prefix, PHP's own constants written with a
  leading `\`, and those patches where they are bound; and its QR code encoder draws the same
  codes as the locked one. Dependabot proposes the QR library's updates (`tools/embed`), the
  development tools' (`tools/dev/composer.lock`) and the actions', after a week; a pull request
  of the QR library fails the CI until `php tools/embed.php vendor` is run on its branch and
  `vendor/` committed, so that the code the package ships is in its diff.
- **The pinned SDK.** `sdk/` holds the SDK's own files at a commit of
  [checkout-php](https://github.com/sattleme/checkout-php), unchanged, with the test vectors of its
  repository that need no network (prices in a currency, integration keys, BIP-340, NIP-44), and
  `sdk/PIN.json`: the commit, the version, each file's SHA-256. `php tools/sdk.php check` checks
  the copy file by file, and that `tools/embed/composer.json` takes its version; the unit tests run
  those vectors against the embedded copy (`Test/Unit/Sdk/EmbeddedSdkTest.php`). To take a new
  version of the SDK, from a clone of checkout-php:
  1. `php tools/sdk.php import <clone> [<commit>]` (the clone's HEAD by default) copies the files
     git holds at that commit, writes `sdk/PIN.json`, and sets the version in
     `tools/embed/composer.json`;
  2. `composer update sattleme/checkout --working-dir=tools/embed`, with the Composer the CI uses
     (2.11.2), locks it;
  3. `php tools/embed.php vendor`, then `php tools/build.php build`: the build fails when the new
     version has code a patch was not proven for (an `@`, an `ArrayObject` elsewhere, an
     assertion that does more than read), which is then proven again, or changed in the SDK;
     commit `vendor/` with the rest;
  4. then the coding standard, PHPStan and the tests. The module uses the SDK's public API alone
     (`Test/Unit/Sdk/PricesTest.php` checks it): its currencies through
     `Client::currencyDigits()`.
- **The SDK's own tests, on the SDK the package ships.** `sh tools/tests/sdk.sh <clone>` runs the
  whole test suite of checkout-php (its `tests/` and the conformance vectors of `spec/`, at the
  commit `sdk/PIN.json` names, which it checks) against the copy in the archive, prefixed and
  patched, as checkout-php runs it on a copy prefixed for a plugin (its `tools/prefix/build.sh`):
  the tests' namespace prefixed as the code's, PHPUnit from its own lock. The CI runs it on every
  PHP version of the matrix (2,029 tests at 1.0.0).

### Versions and dependencies: the patches of the embedded libraries

Adobe Commerce Marketplace rejects an extension in which its code sniffer finds an error
(*"phpcs --standard=Magento2 --extensions=php,phtml --error-severity=10 --ignore-annotations …
If PHPCS finds any errors, the extension … is rejected"*, its
[Code sniffer](https://developer.adobe.com/commerce/marketplace/guides/sellers/code-sniffer)
page), and annotations are ignored: the libraries the package embeds hold code that four of its
rules, each an error of severity 10 in `magento/magento-coding-standard`'s ruleset, refuse. The
build (`tools/embed.php`) changes exactly that code, and fails on any new occurrence a patch was
not proven for; `php tools/embed.php check` compares every other token with the originals. The
package is distributed on Packagist, not on the Marketplace, but it keeps meeting the
Marketplace's bar (the CI runs its code sniffer on it).

| Rule (severity 10)                    | What it refuses                     | Where, at 1.0.0                                                                  | The patch                                                                                   | Why nothing the code does changes                                                                                                                                                                                                                                                                                       |
| ------------------------------------- | ----------------------------------- | -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Magento2.PHP.FinalImplementation`    | the keyword `final`                 | 126 in the SDK, 76 in BaconQrCode and DASPRiD\Enum                               | removed                                                                                     | It only forbids extending a class or overriding a method or constant, which nothing does; no code of theirs reads it (their one reflection, dasprid/enum's, reads constants).                                                                                                                                            |
| `Magento2.Security.InsecureFunction`  | `assert()`                          | 83 in the SDK                                                                    | each statement removed                                                                      | PHP does the same on a production server (`zend.assertions = -1`, php.ini-production's: not even compiled); each removed one only reads (variables, properties, array elements, constants, `is_*()`), as the build checks. With assertions on, one would only throw on a bug of the SDK, which its own CI runs them for. |
| `Magento2.Legacy.RestrictedCode`      | the class `ArrayObject`             | 4 in the SDK: `Internal\Nostr\RelayPool` (2) and `Internal\Nostr\PagedRead` (2) | `ArrayIterator` in its place                                                                | The SDK makes one empty (`new`, no argument), appends to it (`[] =`), counts it and copies it out (`getArrayCopy()`), and passes it as an object; never iterates, serializes, clones nor references its elements. PHP's `ArrayIterator::offsetSet`, `count` and `getArrayCopy` are `ArrayObject`'s own (`@implementation-alias`, `ext/spl/spl_array.stub.php`, 8.2 to 8.5), on the same storage. |
| `Generic.PHP.NoSilencedErrors`        | the operator `@`                    | 1 in the SDK (`Internal\Environment`, a check of PCRE), 3 in BaconQrCode         | `\Sattleme\Payment\Embedded\Silence::call(static fn() => …)`                               | It lowers `error_reporting()` to the fatal errors during the call, then restores it, as `@` does (Zend's `E_FATAL_ERRORS`); the expression and its value are the same.                                                                                                                                                  |

And dasprid/enum's `EnumMap.php` (its `serialize()`, `Magento2.Security.InsecureFunction`) is left
out: BaconQrCode, its only user, never uses it, and the build fails if anything refers to it.

The SDK's own test suite runs on the patched copy (above), assertions on: it passes. One line of
one of its tests changes with the patch of `ArrayObject`: `tests/Vectors/RelayReadsVectorsTest.php`
hands an `ArrayObject` to `PagedRead::read()`, whose parameter the patch types `ArrayIterator`;
it hands an `ArrayIterator` (`tools/tests/sdk.sh`; unchanged, that test fails with a TypeError
alone).

## Releases

Releases are the maintainers' only: a maintainer pushes a tag `vX.Y.Z` (the version of
`composer.json` and of `CHANGELOG.md`) on a commit of `main` whose CI passed. **Distribution** is
on [Packagist](https://packagist.org/packages/sattleme/module-payment) only: Packagist reads the
tag through its GitHub hook, and Composer then installs the tag's git archive (the module and
`vendor/`, as `.gitattributes` trims it). No GitHub release is made, no secret is stored, and a
version Packagist published cannot be changed: a fix is a new version.
[`release.yml`](https://github.com/sattleme/checkout-magento/blob/main/.github/workflows/release.yml)
runs the CI again on the tag and checks it (`vX.Y.Z`, the version of `composer.json` and
`CHANGELOG.md`, a commit of `main`) and the package of that version.

Packagist follows this repository through its GitHub hook: the package is submitted once on
packagist.org, from the repository's public URL, by a maintainer signed in to Packagist with
GitHub, which installs the hook.

## License

MIT, see [LICENSE](LICENSE). The sattle.me name and logo
(`view/frontend/web/images/sattleme-icon.svg` and `sattleme-logo.svg`) are not under that
license. The libraries the package embeds keep their own licenses, each in its directory of
`vendor/`: the SDK's MIT, BaconQrCode's and DASPRiD\Enum's BSD-2-Clause.
