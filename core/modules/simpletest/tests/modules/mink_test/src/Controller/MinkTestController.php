<?php

/**
 * @file
 * Contains \Drupal\mink_test\Controller\MinkTestController.
 */

namespace Drupal\mink_test\Controller;

/**
 * Controller routines for mink_test routes.
 */
class MinkTestController {

  /**
   * Outputs some content for testing Mink.
   *
   * @return array
   *   Array of markup.
   */
  public function minkTest1() {
    return array(
      'test-lists-1' => array(
        '#type' => 'container',
        '#attributes' => array(
          'id' => 'test-lists-1',
        ),
        'list1' => array(
          '#markup' => '<ul><li>item1</li></ul>',
        ),
        'list2' => array(
          '#markup' => '<ul><li>item1</li><li>item2</li></ul>',
        ),
      ),
      'test-lists-2' => array(
        '#type' => 'container',
        '#attributes' => array(
          'id' => 'test-lists-2',
        ),
        'list1' => array(
          '#markup' => '<ul><li>item1</li></ul>',
        ),
      ),
    );
  }

}
