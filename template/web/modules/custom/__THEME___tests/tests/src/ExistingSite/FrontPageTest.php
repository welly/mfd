<?php

declare(strict_types=1);

namespace Drupal\Tests\{{THEME}}_tests\ExistingSite;

use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Example ExistingSite test: runs against the installed DDEV site.
 */
#[Group('{{THEME}}')]
final class FrontPageTest extends ExistingSiteBase {

  /**
   * The front page renders the example card component.
   */
  public function testFrontPageRendersCard(): void {
    $this->drupalGet('/');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '[data-qa="card"]');
  }

}
