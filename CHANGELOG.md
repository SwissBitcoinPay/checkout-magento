# Changelog

Versions of `sattleme/module-payment` ([semantic versioning](https://semver.org)).

## 1.0.0 - 2026-11-24

First version, on the sattle.me checkout SDK 1.0.0 (`sattleme/checkout`, checkout-spec 1.0.0),
for Magento Open Source 2.4.7, 2.4.8 and 2.4.9, on PHP 8.2 to 8.5.

- The payment method **Bitcoin (sattle.me)**: its integration key, checked when saved, stored
  encrypted and never shown again, the business it reached, how long an invoice can be paid, and
  its title and description at checkout, per store view.
- The order placed in *Pending Payment*, its confirmation email held back until it is paid; an
  invoice per attempt, made after the order's placement for a random reference, with the order
  locked in the database while it is made, so that two requests at once get one invoice.
- A payment page in the shop's theme: the QR code (made on the server), the invoice and the
  address to copy, the amount in sats, in bitcoin and in the order's currency, the time left, the
  rest of an on-chain underpayment, the confirmations; updated on its own, usable without
  JavaScript. The customer's order view offers it only while the order may get paid there, says
  when a payment is being checked or confirmed, and what came of an underpayment.
- Payments checked for the payment page and by Magento's cron, in a group of its own, at each of
  its runs while an invoice can be paid; the blockchain read, by the SDK, from mempool.space or
  the merchant's own Bitcoin node. Registered through Magento's capture of a payment the gateway
  confirmed on a `paid` status only (the order invoiced and *Processing*, its emails sent), the
  order cancelled when its invoice expired unpaid, put on hold with a note when it expired
  underpaid (`expired` is final); a payment Magento failed to register registered by the next
  check, and the emails of one whose check stopped sent by the next. An order cancelled
  meanwhile, which Magento cannot take back, stays cancelled, with a note of what came and of the
  invoice's price.
- Invoices that end, with their grace period, before Magento's cron would cancel the order as
  unpaid, payments made in time confirmed however long their confirmations take, and the order
  kept from being cancelled while its invoice may still be paid or confirmed, and, but by the
  admin, once bitcoin came for it: neither the customer (Magento's order cancellation included)
  nor Magento's cron of orders left unpaid cancels it then. Another payment
  method, or a new invoice, only once an invoice ended unpaid, as a check after its end finds it:
  an order is never paid twice by its customer.
- An order that owes another amount than its invoice's price when the invoice is found paid (an
  admin's invoice or credit memo meanwhile) never completed automatically: it is put on hold, with
  a note of the amount received, the invoice's price and what the order owes now, and the admin is
  notified. A payment of another amount in sats than the invoice's is noted too.
- The payment's details in the admin's order view (each proof of payment with the payment hash it
  proves), the order's history (each payment's note saying what came, how, and against what was
  asked; times in the store's time zone), the admin's notifications;
  the command `bin/magento sattleme:payment:privacy` to export and erase a customer's payment data
  (the deposit address and the transactions); translations in French, German and Italian.
- The libraries it uses in the package, under names of its own (`Sattleme\Payment\Vendor\`):
  the SDK 1.0.0, and BaconQrCode 3.1.1 with DASPRiD\Enum 1.0.7 for the QR codes. Composer installs
  nothing with the module but Magento's own packages, and no other copy of these libraries is
  ever loaded in their place.
