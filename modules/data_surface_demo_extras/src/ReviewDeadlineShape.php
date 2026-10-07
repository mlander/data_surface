<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceShapeInterface;

/**
 * The review deadline as a person says it: an amount and a unit.
 *
 * The key's canonical is what is stored, one integer of seconds, the
 * same integer core's content type form stores through this module's
 * form alter. This shape is the other way to say it, contributed beside
 * the canonical rather than in place of it: a caller may send seconds,
 * or an amount and a unit, and the pipeline converts the pair in
 * prepare, after the pair's own constraint and before the seconds'
 * Range, so both gates hold.
 *
 * The conversion is NodeTypeReviewSettings::seconds(), business days
 * included, the same the form alter uses. fromCanonical() reads seconds
 * back in the largest fixed unit that divides them exactly, so seven
 * days comes back as one week — the same duration, said once. The
 * duration always round-trips; the unit does not always, which is why
 * the shape is lossy: stored seconds carry no unit, so ten business days
 * come back as twelve days, as NodeTypeReviewSettings::BUSINESS_DAYS
 * explains. A stored value that is not whole hours, which neither this
 * surface nor the form alter writes, reads back as no amount.
 *
 * The input definition is handed in, built where translation is
 * injected, so the shape itself stays a plain serializable value.
 */
final class ReviewDeadlineShape implements DataSurfaceShapeInterface {

  /**
   * The id this shape is contributed under.
   */
  public const ID = 'amount_unit';

  /**
   * Constructs a ReviewDeadlineShape.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The amount and unit map, with the constraint on the pair.
   */
  public function __construct(
    protected readonly DataDefinitionInterface $definition,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getInputDefinition(): DataDefinitionInterface {
    return $this->definition;
  }

  /**
   * {@inheritdoc}
   */
  public function toCanonical(mixed $input): mixed {
    $amount = is_array($input) ? ($input[NodeTypeReviewSettings::AMOUNT] ?? NULL) : NULL;
    $unit = is_array($input) ? ($input[NodeTypeReviewSettings::UNIT] ?? NULL) : NULL;
    if (!is_int($amount)) {
      // No amount is no deadline.
      return NULL;
    }
    return NodeTypeReviewSettings::seconds($amount, is_string($unit) ? $unit : NodeTypeReviewSettings::DEFAULT_UNIT);
  }

  /**
   * {@inheritdoc}
   */
  public function fromCanonical(mixed $stored): mixed {
    $split = is_int($stored) ? NodeTypeReviewSettings::split($stored) : NULL;
    return $split ?? [
      NodeTypeReviewSettings::AMOUNT => NULL,
      NodeTypeReviewSettings::UNIT => NodeTypeReviewSettings::DEFAULT_UNIT,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isLossy(): bool {
    return TRUE;
  }

}
