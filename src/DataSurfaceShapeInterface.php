<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Another path to the value a surface key stores.
 *
 * A key's definition is its canonical contract: what the key holds, and
 * what is stored. A shape is an alter layer on top of it, never a change
 * to it. Whoever contributes one describes a different input — its own
 * definition, labels, constraints and defaults — and the arithmetic that
 * turns that input into the canonical value and back. Core does this
 * everywhere and keeps it in widgets: the default datetime widget and
 * the date list widget accept different inputs and store the same
 * string; an entity reference is an autocomplete, a select or buttons
 * for one stored id; the link widget turns a typed path into a URI. A
 * shape is the same thing said as data, so a payload, a tool and a form
 * all reach it.
 *
 * The canonical is always accepted. Shapes are additive alternates: a
 * caller may send the canonical value, any shape's input, or name the
 * shape it means. Both gates hold for a shape's input: its own
 * constraints judge what was sent, and the canonical's constraints judge
 * what it becomes.
 *
 * An implementation must be pure and serializable, like every other
 * thing a surface carries: no services, no current user, no site state,
 * no closures. The same input converts the same way for a form, a test,
 * a config action and an agent.
 *
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::addShape()
 * @see docs/shapes.md
 */
interface DataSurfaceShapeInterface {

  /**
   * Gets the definition of what this shape accepts.
   *
   * A map or a scalar, labeled, with whatever constraints and defaults
   * the input has. Its label names the shape wherever it is offered — the
   * advertised union, a form element's title stays the key's — and its
   * description, when it has one, replaces the key's on a form that
   * displays this shape.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The input definition.
   */
  public function getInputDefinition(): DataDefinitionInterface;

  /**
   * Turns an accepted, valid input into the canonical value.
   *
   * Only ever handed input that passed this shape's own constraints, and
   * never input that holds nothing; what comes back is then judged by
   * the canonical definition's constraints, exactly as a canonical value
   * sent directly would be.
   *
   * @param mixed $input
   *   The input, accepted against getInputDefinition().
   *
   * @return mixed
   *   The canonical value. NULL means the key holds nothing.
   */
  public function toCanonical(mixed $input): mixed;

  /**
   * Says a stored canonical value in this shape.
   *
   * What a form displaying this shape shows for what is stored. Must
   * tolerate NULL, which is what an unset key holds, and anything that
   * is not a canonical value at all, answering NULL or the shape's own
   * empty input rather than throwing.
   *
   * @param mixed $stored
   *   The canonical value.
   *
   * @return mixed
   *   The input that toCanonical() turns back into the same canonical
   *   value; when isLossy(), the nearest exact spelling of it.
   */
  public function fromCanonical(mixed $stored): mixed;

  /**
   * Answers whether fromCanonical() can lose how a value was said.
   *
   * The canonical always round-trips: toCanonical(fromCanonical($v)) is
   * $v. The input does not always: ten business days are stored as the
   * seconds of twelve calendar days, and come back as twelve days. A
   * lossy shape says so, and a form displaying it tells the person the
   * value is shown in the nearest exact spelling.
   *
   * @return bool
   *   TRUE when two inputs can store the same canonical value.
   */
  public function isLossy(): bool;

}
