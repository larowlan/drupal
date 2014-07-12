<?php

/**
 * @file
 * Definition of Drupal\block\Tests\NonDefaultBlockAdminTest.
 */

namespace Drupal\block\Tests;

use Drupal\simpletest\BrowserTestBase;

/**
 * Tests the block administration page for a non-default theme.
 *
 * @group block
 */
class NonDefaultBlockAdminTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('block');

  /**
   * Test non-default theme admin.
   */
  function testNonDefaultBlockAdmin() {
    $admin_user = $this->drupalCreateUser(array('administer blocks', 'administer themes'));
    $this->drupalLogin($admin_user);
    $new_theme = 'bartik';
    theme_enable(array($new_theme));
    $this->drupalGet('admin/structure/block/list/' . $new_theme);
    $this->assertText('Bartik(' . t('active tab') . ')', 'Tab for non-default theme found.');
  }
}
