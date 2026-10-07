<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceAccess;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceFactoryInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceProviderInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Target\MountTarget;

/**
 * The surface for configuring one field instance, addressed by its id.
 *
 * What the two field tools are built from. Its one key, `settings`, is
 * the field type's own settings surface mounted by coordinate — field
 * type, `field_settings`, and the field config id — so the shape of the
 * settings is known from the address alone: the subject names the
 * field, the field names its type, and the type answers. Nothing about
 * the settings is decided by a value, and nothing is swapped in when a
 * value arrives.
 *
 * Two operations from the field config's own vocabulary, and the subject
 * is the field config id, `<entity type>.<bundle>.<field>`:
 * - `add`, for an instance about to be created on a bundle from an
 *   existing field storage. It is described unsaved.
 * - `edit`, for an instance that exists.
 *
 * A field type that declares no surface has no shape for this provider
 * to mount: surfaceFor() answers NULL for it, and getDataSurface()
 * refuses its subject, so a caller falls back to whatever it did before.
 *
 * The typed conveniences beside the coordinate methods — surfaceFor(),
 * accessFor() and targetFor() — take a field config the caller already
 * holds, which is what a tool executing a write has: the object it has
 * set a label on is the object the target must save.
 *
 * @see \Drupal\data_surface_tool\FieldTypeSurfaceResolver
 * @see \Drupal\data_surface_tool\Plugin\tool\Tool\FieldAdd
 * @see \Drupal\data_surface_tool\Plugin\tool\Tool\FieldUpdate
 */
final class FieldInstanceSurfaceProvider implements DataSurfaceProviderInterface {

  use StringTranslationTrait;

  /**
   * The host id every surface this provider builds is sealed under.
   */
  public const HOST_ID = 'entity_type:field_config';

  /**
   * The operation describing an instance about to be added.
   */
  public const OPERATION_ADD = 'add';

  /**
   * The operation describing an instance that exists.
   */
  public const OPERATION_EDIT = 'edit';

  /**
   * The key the field type's settings surface is mounted at.
   */
  public const SETTINGS = 'settings';

  /**
   * Constructs a FieldInstanceSurfaceProvider.
   *
   * @param \Drupal\data_surface_tool\FieldSurfaceLocator $locator
   *   The service that finds a field instance, its surface and its
   *   settings target.
   * @param \Drupal\data_surface\DataSurfaceFactoryInterface $factory
   *   The surface factory, which seals the surface and resolves the
   *   mounted coordinate.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which confirms an entity type exists
   *   before its field permission is spelled.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The account an access answer is about when none is named.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    protected readonly FieldSurfaceLocator $locator,
    protected readonly DataSurfaceFactoryInterface $factory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AccountInterface $currentUser,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function getDataSurface(string $operation = self::OPERATION_EDIT, ?string $subject = NULL): DataSurfaceInterface {
    $field = $this->fieldFor($operation, $subject);
    if ($field === NULL) {
      throw new \InvalidArgumentException(sprintf('There is no field instance to %s at "%s".', $operation, $subject ?? ''));
    }
    return $this->surfaceFor($field)
      ?? throw new \InvalidArgumentException(sprintf('The %s field type declares no surface for its settings.', $field->getType()));
  }

  /**
   * {@inheritdoc}
   */
  public function surfaceAccess(string $operation = self::OPERATION_EDIT, ?string $subject = NULL, ?AccountInterface $account = NULL): AccessResultInterface {
    $field = $this->fieldFor($operation, $subject);
    return $field === NULL ? AccessResult::forbidden() : $this->accessFor($field, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function getDataSurfaceTarget(string $operation = self::OPERATION_EDIT, ?string $subject = NULL): DataSurfaceTargetInterface {
    $field = $this->fieldFor($operation, $subject);
    if ($field === NULL) {
      throw new \InvalidArgumentException(sprintf('There is no field instance to %s at "%s".', $operation, $subject ?? ''));
    }
    return $this->targetFor($field);
  }

  /**
   * Builds the surface for a field instance the caller already holds.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field config entity, saved or not.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface|null
   *   The surface, or NULL when the field type declares none.
   */
  public function surfaceFor(FieldConfigInterface $field): ?DataSurfaceInterface {
    if (!$this->locator->describes($field)) {
      return NULL;
    }
    $builder = new DataSurfaceBuilder();
    $builder->setDefinition(self::SETTINGS, MapDataDefinition::create()
      ->setLabel($this->t('Field instance settings'))
      ->setDescription($this->t('Settings for this field on this bundle, described by the field type itself: every key, its meaning, the values it allows and what it starts from.')));
    $builder->mount(self::SETTINGS, FieldTypeSurfaceResolver::coordinate($field->getType(), (string) $field->id()));
    return $this->factory->build($builder, self::class, self::HOST_ID);
  }

  /**
   * Answers whether an account may configure a field instance's settings.
   *
   * Two answers, and the field type gets the second one. The host gate
   * is the field's own: for an instance that exists, the field config
   * entity is asked, which is where core spells the permission and where
   * entity access hooks are heard; for one about to be added there is no
   * entity yet, so it is the entity type's field administration
   * permission. The field type's surface may refuse on top of either,
   * and can never open what the host gate closed.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field config entity, saved or not.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The combined answer.
   */
  public function accessFor(FieldConfigInterface $field, ?AccountInterface $account = NULL): AccessResultInterface {
    $host = $field->isNew()
      ? $this->administrationAccess($field->getTargetEntityTypeId(), $account)
      : $field->access('update', $account, TRUE);
    return DataSurfaceAccess::gate($host, $this->locator->accessFor($field, account: $account));
  }

  /**
   * Gets the target that writes a field instance's settings.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field config entity, saved or not; the target saves this very
   *   object, so whatever else the caller set on it is written too.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface
   *   The target, routing the mounted settings to the field type's own.
   *
   * @throws \LogicException
   *   When the field type declares no settings target.
   */
  public function targetFor(FieldConfigInterface $field): DataSurfaceTargetInterface {
    $target = $this->locator->targetFor($field)
      ?? throw new \LogicException(sprintf('The %s field type declares no target for its settings.', $field->getType()));
    return new MountTarget([self::SETTINGS => $target]);
  }

  /**
   * Answers whether an account may administer an entity type's fields.
   *
   * The permission name carries an entity type id, so it is spelled only
   * after the entity type manager has confirmed the id names one.
   *
   * @param mixed $entity_type_id
   *   The entity type id, not yet known to be one.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer, forbidden when the entity type does not exist.
   */
  public function administrationAccess(mixed $entity_type_id, ?AccountInterface $account = NULL): AccessResultInterface {
    if (!is_string($entity_type_id) || $entity_type_id === '' || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowedIfHasPermission($account ?? $this->currentUser, 'administer ' . $entity_type_id . ' fields');
  }

  /**
   * Finds the field instance one coordinate addresses.
   *
   * @param string $operation
   *   The operation.
   * @param string|null $subject
   *   The field config id.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface|null
   *   The field, or NULL when the operation is unknown, the subject names
   *   nothing, or names an instance that does not fit the operation: a
   *   saved one to add, or an unsaved one to edit.
   */
  protected function fieldFor(string $operation, ?string $subject): ?FieldConfigInterface {
    if ($subject === NULL || !in_array($operation, [self::OPERATION_ADD, self::OPERATION_EDIT], TRUE)) {
      return NULL;
    }
    $field = $this->locator->fieldAt($subject);
    if ($field === NULL || $field->isNew() !== ($operation === self::OPERATION_ADD)) {
      return NULL;
    }
    return $field;
  }

}
