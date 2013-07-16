<?php

/**
 * @file
 * Contains \Drupal\user\Plugin\Validation\Constraint\UserNameUniqueValidator.
 */

namespace Drupal\user\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the UserNameUnique constraint.
 */
class UserNameUniqueValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($name, Constraint $constraint) {
    $user = $this->context->getMetadata()->getTypedData()->getParent()->getParent();
    $uid = $user->get('uid')->value;
    $name_taken = (bool) db_select('users')
      ->fields('users', array('uid'))
      ->condition('uid', (int) $uid, '<>')
      ->condition('name', db_like($name), 'LIKE')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($name_taken) {
      $this->context->addViolation($constraint->message, array('%name' => $name));
    }
  }
}
