<?php

declare(strict_types=1);

namespace Drupal\data_surface;

/**
 * The address of one surface: host id, operation and subject.
 *
 * The wire coordinate every provider is already asked by, as one value.
 * It is what a mount names its child by when the child is somebody
 * else's surface, so that what a parent advertises can say *which*
 * surface sits at a key — an address a discovery document can print and
 * a caller can ask for on its own — rather than only a copy of what that
 * surface said the day the parent was built.
 *
 * Pure data, and deliberately so: no provider, no service, nothing that
 * cannot be serialized into a cached form. Turning an address into a
 * surface is the factory's job, through the resolvers registered with
 * it, which is why a builder holding a coordinate can only be sealed by
 * the factory.
 *
 * The three parts mean exactly what they mean on
 * DataSurfaceProviderInterface::getDataSurface(): the host id is
 * namespaced `<host type>:<id>`, the operation is a closed verb that
 * never carries identity, and the subject is an opaque id only the
 * resolver for that host type parses, NULL when the host is its own
 * subject.
 *
 * @see \Drupal\data_surface\DataSurfaceFactoryInterface::resolve()
 * @see \Drupal\data_surface\DataSurfaceResolverInterface
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::mount()
 */
final class DataSurfaceCoordinate implements \Stringable {

  /**
   * Constructs a DataSurfaceCoordinate.
   *
   * @param string $hostId
   *   The namespaced host id, `<host type>:<id>`.
   * @param string $operation
   *   The operation, from the host type's own closed vocabulary.
   * @param string|null $subject
   *   The opaque id of the thing the operation is about, or NULL when the
   *   host is its own subject.
   *
   * @throws \InvalidArgumentException
   *   When the host id is not namespaced, or the operation is empty.
   */
  public function __construct(
    public readonly string $hostId,
    public readonly string $operation = 'configure',
    public readonly ?string $subject = NULL,
  ) {
    $type = strstr($hostId, ':', TRUE);
    if ($type === FALSE || $type === '' || str_ends_with($hostId, ':')) {
      throw new \InvalidArgumentException(sprintf(
        'The host id "%s" is not namespaced: a coordinate names its host as <host type>:<id>, which is how a resolver knows the host is its own.',
        $hostId,
      ));
    }
    if ($operation === '') {
      throw new \InvalidArgumentException(sprintf('The coordinate for %s names no operation.', $hostId));
    }
  }

  /**
   * Gets the host type, the part of the host id before the colon.
   *
   * @return string
   *   The host type: block, field_type, entity_type, and so on.
   */
  public function hostType(): string {
    return (string) strstr($this->hostId, ':', TRUE);
  }

  /**
   * {@inheritdoc}
   *
   * Written the way a message or a discovery document prints an address:
   * host id, operation and, when there is one, subject, slash separated.
   */
  public function __toString(): string {
    return $this->hostId . '/' . $this->operation . ($this->subject === NULL ? '' : '/' . $this->subject);
  }

}
