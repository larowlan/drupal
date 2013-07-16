<?php

/**
 * @file
 * Contains \Drupal\user\Plugin\Validation\Constraint\UserNameUnique.
 */

namespace Drupal\user\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;


/**
 * Checks if a user name is unique on the site.
 *
 * @Plugin(
 *   id = "UserNameUnique",
 *   label = @Translation("User name unique", context = "Validation")
 * )
 */
class UserNameUnique extends Constraint {

  public $message = 'The name %name is already taken.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy() {
    return '\Drupal\user\Plugin\Validation\Constraint\UniqueValidator';
  }
}
