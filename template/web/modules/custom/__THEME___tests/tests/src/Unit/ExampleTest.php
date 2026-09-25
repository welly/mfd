<?php

declare(strict_types=1);

namespace Drupal\Tests\{{THEME}}_tests\Unit;

use Drupal\Component\Utility\Html;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Example unit test: no Drupal bootstrap. Replace with tests of your classes.
 */
#[Group('{{THEME}}')]
final class ExampleTest extends UnitTestCase {

  /**
   * Html::getClass() turns a label into the CSS class a template expects.
   */
  public function testClassNameFromLabel(): void {
    $this->assertSame('card-heading', Html::getClass('Card heading'));
  }

}
