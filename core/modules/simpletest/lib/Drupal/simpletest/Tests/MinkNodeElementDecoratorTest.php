<?php

/**
 * @file
 * Contains \Drupal\simpletest\MinkNodeElementDecoratorTest.
 */

namespace Drupal\simpletest\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Tests helper methods provided by the Mink node decorator.
 *
 * @coversDefaultClass \Drupal\simpletest\MinkNodeElementDecorator
 * @group Drupal
 * @group simpletest
 */
class MinkNodeElementDecoratorTest extends WebTestBase {

  public static function getInfo() {
    return array(
      'name' => 'Mink node element decorator',
      'description' => 'Test Mink node element decorator logic.',
      'group' => 'Simpletest',
    );
  }

  protected function setUp() {
    $modules = array('mink_test');
    parent::setUp($modules);
    $this->initMink();
  }

  /**
   * Test decorator.
   *
   * @covers MinkNodeElementDecorator::__get
   */
  public function testDecorator() {
    $session = $this->getSession();
    $session->visit('/mink-test-1');
    $content = $session->getPage()->getContent();
    $a = 1;
    $this->assertRaw('adsflkajdsf', 'adsflkajdsf');
  }

}
