<?php

declare(strict_types=1);

namespace Sattleme\Payment\Test\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Sattleme\Payment\Tools\Translations;

/**
 * The dictionaries (i18n/*.csv): each translates exactly the phrases of the sources, with the same
 * placeholders; English is the sources'.
 */
class TranslationsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/i18n.php';
    }

    public function testEachDictionaryTranslatesThePhrasesOfTheSources(): void
    {
        $translations = new Translations(dirname(__DIR__, 3));

        $this->assertSame([], $translations->problems());
        $this->assertGreaterThan(100, count($translations->phrases()));
    }

    public function testThePhrasesAreThoseMagentosCollectorFinds(): void
    {
        $translations = new Translations(dirname(__DIR__, 3));

        $this->assertSame(
            ['A b', 'Joined phrase', "It's"],
            $translations->ofPhp("<?php __('A b'); \$x->__('Not'); __('Joined' . ' phrase', \$y); __(\"It's\");")
        );
        $this->assertSame(
            ['Label', 'Comment'],
            $translations->ofXml(
                '<config><field translate="label comment"><label>Label</label><comment>Comment</comment>'
                . '<other>No</other></field></config>'
            )
        );
        $this->assertSame(['Place Order', "Don't"], $translations->ofScript("\$t('Place Order'); i18n: 'Don\\'t'"));
    }

    public function testGermanInSwitzerlandHasNoEszett(): void
    {
        $translations = new Translations(dirname(__DIR__, 3));

        foreach ($translations->dictionary('de_CH') as $translation) {
            $this->assertStringNotContainsString('ß', $translation);
        }
    }
}
