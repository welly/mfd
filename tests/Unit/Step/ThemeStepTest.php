<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Step;

use Manifesto\Mfd\Step\DrupalStep;
use Manifesto\Mfd\Step\ThemeStep;
use Manifesto\Mfd\Tests\Support\NewCommandTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ThemeStepTest extends NewCommandTestCase
{
    /** @param array<string, mixed> $input */
    private function theme(array $input = ['name' => 'acme']): CommandTester
    {
        return $this->newProject($input, [new DrupalStep(), new ThemeStep()]);
    }

    public function testGeneratesTheThemeAddsTheCardAndSetsTheDefault(): void
    {
        $tester = $this->theme(['name' => 'acme-corp', '--theme-label' => 'Acme & Co']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertContains(
            'ddev exec vendor/bin/dr generate-theme acme_corp --name Acme & Co --path themes/custom',
            $this->shell->calls,
        );
        $card = $this->project('acme-corp') . '/web/themes/custom/acme_corp/components/card';
        self::assertStringContainsString('examples:', (string) file_get_contents($card . '/card.component.yml'));
        self::assertStringContainsString('data-qa="card"', (string) file_get_contents($card . '/card.twig'));
        self::assertContains('ddev drush theme:enable acme_corp -y', $this->shell->calls);
        self::assertContains('ddev drush config:set system.theme default acme_corp -y', $this->shell->calls);
        self::assertContains('ddev drush cr', $this->shell->calls);
    }

    public function testFrontPageTemplateIncludesTheCardBeforeThePageContent(): void
    {
        $this->theme();
        $front = (string) file_get_contents(
            $this->project('acme') . '/web/themes/custom/acme/templates/layout/page--front.html.twig',
        );

        self::assertStringContainsString(
            "{{ include('acme:card', { heading: 'Welcome' }, with_context = false) }}",
            $front,
        );
        self::assertLessThan(strpos($front, '{{ page.content }}'), strpos($front, 'acme:card'));
    }

    public function testFailsClearlyWhenThePageTemplateHasNoContentRegion(): void
    {
        $this->shell->failOn('drush theme:enable');
        $this->theme();
        $layout = $this->project('acme') . '/web/themes/custom/acme/templates/layout';
        unlink($layout . '/page--front.html.twig');
        file_put_contents($layout . '/page.html.twig', "<main></main>\n");
        $this->shell->clearFailures();
        $tester = $this->theme();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('page.content', $tester->getDisplay());
    }

    public function testARerunKeepsAnEditedCardAndDoesNotRegenerateTheTheme(): void
    {
        $this->shell->failOn('drush theme:enable');
        $this->theme();
        $twig = $this->project('acme') . '/web/themes/custom/acme/components/card/card.twig';
        file_put_contents($twig, 'edited');
        $this->shell->clearFailures();
        $this->shell->calls = [];
        $tester = $this->theme();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('edited', file_get_contents($twig));
        self::assertSame([], $this->shell->callsStartingWith('ddev exec vendor/bin/dr generate-theme'));
    }
}
