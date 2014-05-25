<?php

/**
 * @file
 * Contains \Drupal\simpletest\MinkNodeElementDecoratorTest.
 */

namespace Drupal\simpletest\Tests;

use Drupal\simpletest\MinkNodeElementDecorator;
use Drupal\simpletest\WebTestBase;

/**
 * Tests helper methods provided by the Mink node decorator.
 *
 * @coversDefaultClass \Drupal\simpletest\MinkNodeElementDecorator
 * @group Drupal
 * @group simpletest
 */
class MinkNodeElementDecoratorTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('mink_test');

  public static function getInfo() {
    return array(
      'name' => 'Mink node element decorator',
      'description' => 'Test Mink node element decorator logic.',
      'group' => 'Simpletest',
    );
  }

  /**
   * Test decorator.
   *
   * @covers MinkNodeElementDecorator::__get
   */
  public function testDecorator() {
    $this->drupalGet("/mink-test-1");
    $element = $this->getSession()->getPage();
    $container = $element->find("css", "#test-lists-1");
    $container = new MinkNodeElementDecorator($container);

    // Ensure we are not working all the way down the tree every time we do a
    // find. We want to make sure it's working in a jQuery like fashion so we
    // don't end up with weird results.
    // @todo, @nick_schuch, for laters.

    // Look for a multiple lists on the page. Should return an array of objects
    // since that list has children (list items).
    $this->assertTrue(count($container->ul) > 1, "Array of objects found.");

    // Look for list with single list item. Should be a text value return.
    $this->assertTrue($container->ul->li == "item1", "Found the single item in the list.");

    // Look for list with single list item. Should be a text value return.
    $list = $container->ul[0];
    $this->assertTrue($list->li == "item1", "Found the single item in the list.");

    // Look for a list with multiple list items. Should return an array of
    // strings.
    $list = $container->ul[1];
    $this->assertTrue(count($list->li) > 1);
    $this->assertTrue($list->li[0] == "item1", "Found the first item in the list.");
    $this->assertTrue($list->li[1] == "item2", "Found the second item in the list.");

    // Ensure a single child gets decorated. So we can get it's children.
    $container = $element->find("css", "#test-lists-2");
    $container = new MinkNodeElementDecorator($container);
    $this->assertTrue(count($container->ul) == 1, "Our child is an object.");
    $this->assertTrue($container->ul->li == "item1", "We found the child of the child.");
  }

}
