<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;

/**
 * Finds the surface describing one field instance's settings.
 *
 * A field type's per instance settings are declared by the field item
 * class, so reaching them means having an item, and an item that knows
 * which field config entity it belongs to: the surface is the same for
 * every instance of the field type, but the target writes to one
 * particular field.
 *
 * Field UI gets its item by building typed data from the field config
 * being edited and taking the first one, which is the obvious thing to
 * copy and the wrong thing to copy here. Asking a field item list for an
 * item goes through the typed data manager's prototype cache, which is
 * keyed by the root data type and the property path and not by the field
 * config object, so the second field config with the same name on the
 * same entity type is handed a clone of the first one's item — and a
 * target built from that writes to the wrong entity. A tool hits this
 * immediately, because it describes an unsaved field while refining its
 * inputs and then creates another one while executing.
 *
 * So the item is built straight from the field's own item definition,
 * which a field config caches on itself and binds to itself. No
 * prototype, no entity to hang it from, and the item's field definition
 * is the object the caller passed in.
 *
 * Nothing here knows about any particular field type. A field item that
 * implements FieldSurfaceProviderInterface answers with a surface and a
 * target; anything else answers with NULL, and the caller falls back to
 * whatever it did before. The interface is the whole test: there is no
 * method_exists fallback, because a class that happens to have a method
 * of the right name has not promised anything.
 */
final class FieldSurfaceLocator {

  /**
   * Constructs a FieldSurfaceLocator.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which instantiates the field item.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which loads the field a subject names.
   */
  public function __construct(
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Finds the field instance a subject names, saved or about to be.
   *
   * The subject is a field config id, `<entity type>.<bundle>.<field>`,
   * which is the one id a field instance already has and the only
   * vocabulary a caller addressing one needs. A field that exists is
   * loaded. One that does not, on a bundle whose entity type has a
   * storage of that name, is the instance adding it would create, built
   * unsaved — which is what lets a field be described before it exists,
   * exactly as Field UI's add form does.
   *
   * @param string $subject
   *   The field config id.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface|null
   *   The field, or NULL when the subject is not three non-empty parts
   *   naming an existing entity type and field storage.
   */
  public function fieldAt(string $subject): ?FieldConfigInterface {
    $parts = explode('.', $subject);
    if (count($parts) !== 3 || in_array('', $parts, TRUE)) {
      return NULL;
    }
    [$entity_type_id, $bundle, $field_name] = $parts;
    if (!$this->entityTypeManager->hasDefinition($entity_type_id) || !$this->entityTypeManager->hasDefinition('field_config')) {
      return NULL;
    }
    $field = $this->entityTypeManager->getStorage('field_config')->load($subject);
    if ($field instanceof FieldConfigInterface) {
      return $field;
    }
    $storage = $this->entityTypeManager->getStorage('field_storage_config')->load($entity_type_id . '.' . $field_name);
    if ($storage === NULL) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage('field_config')->create([
      'field_storage' => $storage,
      'bundle' => $bundle,
    ]);
  }

  /**
   * Answers whether a field instance's settings are described by a surface.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field config entity, saved or not.
   *
   * @return bool
   *   TRUE when the field type declares a surface for its settings.
   */
  public function describes(FieldConfigInterface $field): bool {
    return $this->fieldItem($field) !== NULL;
  }

  /**
   * Gets the surface describing a field instance's settings.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field config entity, saved or not.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface|null
   *   The surface, or NULL when this field type declares none.
   */
  public function surfaceFor(FieldConfigInterface $field): ?DataSurfaceInterface {
    return $this->fieldItem($field)?->getFieldSurface();
  }

  /**
   * Gets the target a field instance's settings are stored through.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field config entity, saved or not. The target holds this same
   *   object, so a caller that has already set a label or a description
   *   on it sees those written by the target's own save.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface|null
   *   The target, or NULL when this field type declares none.
   */
  public function targetFor(FieldConfigInterface $field): ?DataSurfaceTargetInterface {
    return $this->fieldItem($field)?->getDataSurfaceTarget();
  }

  /**
   * Asks a field type whether an account may configure these settings.
   *
   * A field type that declares no surface has no opinion to offer, which
   * is neutral rather than forbidden: the caller's own field and entity
   * checks are what decide, exactly as they did before this method
   * existed.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field config entity, saved or not.
   * @param string $operation
   *   The operation the answer is wanted for.
   * @param string|null $subject
   *   The id of the thing the operation is about, in the operation and
   *   subject order every provider takes it in. NULL here is the whole
   *   of the normal case: the field this locator was handed is the
   *   subject, and the item it builds is already bound to it.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to answer for, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The field type's answer, or no opinion when it declares none.
   */
  public function accessFor(FieldConfigInterface $field, string $operation = FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS, ?string $subject = NULL, ?AccountInterface $account = NULL): AccessResultInterface {
    return $this->fieldItem($field)?->surfaceAccess($operation, $subject, $account) ?? AccessResult::neutral();
  }

  /**
   * Builds a field item bound to one field config entity.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field config entity.
   *
   * @return \Drupal\data_surface\Form\FieldSurfaceProviderInterface|null
   *   The field item when its class describes its settings with a
   *   surface, or NULL otherwise.
   */
  protected function fieldItem(FieldConfigInterface $field): ?FieldSurfaceProviderInterface {
    $item = $this->typedDataManager->create($field->getItemDefinition());
    return $item instanceof FieldSurfaceProviderInterface ? $item : NULL;
  }

}
