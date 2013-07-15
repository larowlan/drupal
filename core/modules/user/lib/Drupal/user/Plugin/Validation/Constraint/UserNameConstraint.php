<?php

/**
 * @file
 * Contains \Drupal\user\Plugin\Validation\Constraint\UserNameConstraint.
 */

namespace Drupal\user\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;


/**
 * Checks if a value is a valid user name.
 *
 * This class is empty because all validation messages are retrieved from
 * user_validate_name().
 *
 * @Plugin(
 *   id = "UserName",
 *   label = @Translation("User name", context = "Validation")
 * )
 */
class UserNameConstraint extends Constraint {}
