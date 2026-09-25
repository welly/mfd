<?php

declare(strict_types=1);

namespace Drupal\Tests\{{THEME}}_tests\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Example kernel test: the theme's Single Directory Components are discovered.
 */
#[Group('{{THEME}}')]
final class ComponentDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The example card component is registered under the theme's namespace.
   */
  public function testCardComponentIsDiscovered(): void {
    $this->container->get('theme_installer')->install(['{{THEME}}']);
    $this->config('system.theme')->set('default', '{{THEME}}')->save();

    $component = $this->container->get('plugin.manager.sdc')->find('{{THEME}}:card');

    $this->assertSame('Card', $component->metadata->name);
  }

}
