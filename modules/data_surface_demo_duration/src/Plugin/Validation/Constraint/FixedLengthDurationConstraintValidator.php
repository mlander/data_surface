<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_duration\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the FixedLengthDurationConstraint.
 */
final class FixedLengthDurationConstraintValidator extends ConstraintValidator {

  /**
   * An ISO 8601 duration, each unit captured by name.
   */
  protected const PARTS = '/^P(?:(?<years>\d+)Y)?(?:(?<months>\d+)M)?(?:\d+W)?(?:\d+D)?(?:T(?:\d+H)?(?:(?<minutes>\d+)M)?(?:(?<seconds>\d+)S)?)?$/';

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof FixedLengthDurationConstraint);
    // Anything that is not a duration at all is the pattern's to refuse.
    if (!is_string($value) || !preg_match(self::PARTS, $value, $parts)) {
      return;
    }
    $message = match (TRUE) {
      ($parts['years'] ?? '') !== '' || ($parts['months'] ?? '') !== '' => $constraint->notFixedMessage,
      ($parts['minutes'] ?? '') !== '' || ($parts['seconds'] ?? '') !== '' => $constraint->notWholeHoursMessage,
      default => NULL,
    };
    if ($message !== NULL) {
      $this->context->buildViolation($message)
        ->setParameter('@value', $value)
        ->addViolation();
    }
  }

}
