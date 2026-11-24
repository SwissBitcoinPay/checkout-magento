# Security

This repository is the Magento module with which a shop takes Bitcoin payments for a sattle.me
merchant. A flaw here could mark an order paid that is not, show an invoice that pays someone else,
or leak the integration key: please report it **privately**, never in a public issue, pull request
or discussion.

## How to report

- On GitHub: **Security** tab of this repository → **Report a vulnerability** (private
  vulnerability reporting).
- Or by email: security@sattle.me, subject starting with `[security] checkout-magento`.

Please include what you found, the class, the controller or the template concerned, the versions of
the module (`sattleme/module-payment`), of Magento, of PHP and of `sattleme/checkout`, and, when
you can, the steps that show it. Do not test against real merchants' funds or keys.

## What is in scope

- This module: anything that registers a payment the SDK did not report paid, completes one order
  with another order's payment, makes a second payable invoice for an order, shows a payment page
  to someone without its link, leaks the integration key, or lets a customer make the server do
  more than the checks of their own payment.
- A flaw of the SDK goes to [checkout-php](https://github.com/sattleme/checkout-php) (its
  SECURITY.md); a rule of the protocol itself to
  [checkout-spec](https://github.com/sattleme/checkout-spec/blob/main/SECURITY.md); a flaw of
  Magento to Adobe, as
  [Magento's own policy](https://github.com/magento/magento2/blob/2.4-develop/SECURITY.md) says
  (Adobe's [bug bounty program](https://hackerone.com/adobe), or psirt@adobe.com).

We will acknowledge your report, keep you informed, and credit you when the fix is published,
unless you prefer otherwise.
