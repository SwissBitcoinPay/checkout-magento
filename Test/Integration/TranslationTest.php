<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Integration;

use Magento\Framework\Phrase;
use Magento\Framework\TranslateInterface;
use Sattleme\Payment\Model\Notes;

/**
 * The module's translations, as Magento loads them (French, German and Italian, in their
 * countries and in Switzerland), and its notes on an order in the language of the order's store,
 * whoever writes them (the customer's request, the cron).
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class TranslationTest extends OrderTestCase
{
    public function testMagentoLoadsTheModulesTranslations(): void
    {
        $translations = [
            'fr_FR' => 'Payer en Bitcoin',
            'fr_CH' => 'Payer en Bitcoin',
            'de_DE' => 'Mit Bitcoin bezahlen',
            'de_CH' => 'Mit Bitcoin bezahlen',
            'it_IT' => 'Pagare con Bitcoin',
            'it_CH' => 'Pagare con Bitcoin',
        ];
        $translate = $this->get(TranslateInterface::class);
        try {
            foreach ($translations as $locale => $expected) {
                $translate->setLocale($locale)->loadData(null, true);
                $this->assertSame($expected, (string) __('Pay with Bitcoin'), $locale);
            }
            $translate->setLocale('de_CH')->loadData(null, true);
            $this->assertStringContainsString(
                'Sie können diese Seite schliessen',
                (string) __(
                    'Your payment came in time. The Bitcoin network is confirming its on-chain transaction '
                    . '(%1 of 3 confirmations), which takes about 30 minutes. '
                    . 'You can close this page: your order is confirmed by email once it is.',
                    '1'
                )
            );
        } finally {
            $translate->setLocale('en_US')->loadData(null, true);
        }
    }

    public function testTheNotesAreInTheLanguageOfTheOrdersStore(): void
    {
        $this->setConfig('general/locale/code', 'fr_CH');
        $order = $this->placeOrder();

        $attempt = $this->invoiceFor($order);

        $notes = $this->notes($order);
        $this->assertStringStartsWith('Facture Bitcoin ' . $attempt->ref . ' créée : 59', (string) end($notes));
        $note = str_replace("'", '’', (string) end($notes));
        $this->assertStringContainsString('payable en Lightning ou on-chain jusqu’au', $note);
        // Magento's own translation stays as it was (English here).
        $this->assertSame('Pay with Bitcoin', (string) __('Pay with Bitcoin'));
    }

    public function testANoteOfAStoreInAnotherCountryOfALanguageIsInThatLanguage(): void
    {
        $this->setConfig('general/locale/code', 'de_AT');
        $notes = $this->get(Notes::class);

        $this->assertSame('Mit Bitcoin bezahlen', $notes->render(1, new Phrase('Pay with Bitcoin')));
        $this->setConfig('general/locale/code', 'es_ES');
        $this->assertSame('Pay with Bitcoin', $notes->render(1, new Phrase('Pay with Bitcoin')), 'English, the source');
    }
}
