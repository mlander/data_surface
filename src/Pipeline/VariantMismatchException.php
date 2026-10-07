<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * Thrown by accept() when a slot's value is shaped for another variant.
 *
 * The slot is told which variant it holds by its discriminator, and the
 * value carried keys only some other variant declares: the caller sent
 * a list's settings while choosing a grid, say. That is not an unknown
 * key in the ordinary sense — every key named is known, just not here —
 * so it is reported in words that say which variant was chosen and
 * which one the keys belong to, on the slot's own path.
 *
 * @see \Drupal\data_surface\SurfaceSlot
 */
final class VariantMismatchException extends \InvalidArgumentException {

  /**
   * Constructs a VariantMismatchException.
   *
   * @param string $path
   *   The dotted path of the slot, starting at a surface key.
   * @param string $by
   *   The discriminator key.
   * @param string $chosen
   *   The variant the discriminator chose.
   * @param array<string, string[]> $owners
   *   The keys that do not belong to the chosen variant, each with the
   *   variants that do declare it.
   */
  public function __construct(
    protected readonly string $path,
    protected readonly string $by,
    protected readonly string $chosen,
    protected readonly array $owners,
  ) {
    parent::__construct(sprintf(
      'The value at %s carries %s, which the "%s" variant chosen by %s does not take.',
      $path,
      implode(', ', array_keys($owners)),
      $chosen,
      $by,
    ));
  }

  /**
   * Gets the dotted path of the slot.
   *
   * @return string
   *   The path.
   */
  public function getPath(): string {
    return $this->path;
  }

  /**
   * Gets the discriminator key.
   *
   * @return string
   *   The key whose value chose the variant.
   */
  public function getBy(): string {
    return $this->by;
  }

  /**
   * Gets the variant the discriminator chose.
   *
   * @return string
   *   The variant id.
   */
  public function getChosen(): string {
    return $this->chosen;
  }

  /**
   * Gets the misplaced keys and the variants each belongs to.
   *
   * @return array<string, string[]>
   *   The variant ids declaring each key, keyed by key.
   */
  public function getOwners(): array {
    return $this->owners;
  }

}
