<?php

/**
 * @file
 * Contains \Drupal\user\Plugin\Validation\Constraint\UserNameConstraintValidator.
 */

namespace Drupal\user\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the UserName constraint.
 */
class UserNameConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($name, Constraint $constraint) {
    // @todo this is not ideal as the message will run through t() twice.
    $message = user_validate_name($name);
    if ($message != NULL) {
      $this->context->addViolation($message);
    }
  }
}
