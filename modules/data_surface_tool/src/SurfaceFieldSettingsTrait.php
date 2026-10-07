<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\InputDefinitionInterface;

/**
 * The part of the two field tools that is about surfaces, not fields.
 *
 * Both tools are built from the same surface — the field instance
 * surface FieldInstanceSurfaceProvider serves, addressed by the field
 * config id their three identity inputs spell — and report violations
 * the same way. Neither contains a line of knowledge about what a
 * setting means.
 *
 * The settings input is that surface's `settings` mount, converted. It
 * is not refined by the surface layer at all: the field type's settings
 * are mounted by coordinate, so their shape is known the moment the
 * field is named. What still runs through the Tool API's
 * input_definition_refiners is the *address*: a Tool API invocation
 * carries the field it is about as three ordinary inputs, and a refiner
 * is the only place a tool hears them. That is the Tool API's static
 * versus instance split, documented in the module README, and the
 * static `settings` input on each attribute says so in its description
 * rather than advertising a shape it does not have.
 *
 * Executing is one call to the pipeline with that same surface and the
 * provider's target for the field the tool is writing.
 */
trait SurfaceFieldSettingsTrait {

  use SurfaceResultReportingTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The provider of the field instance surface the tools are built from.
   *
   * @var \Drupal\data_surface_tool\FieldInstanceSurfaceProvider
   */
  protected FieldInstanceSurfaceProvider $fieldInstances;

  /**
   * The service that converts a surface into input definitions.
   *
   * @var \Drupal\data_surface_tool\SurfaceInputDefinitions
   */
  protected SurfaceInputDefinitions $surfaceInputDefinitions;

  /**
   * The pipeline that accepts, validates, prepares and commits values.
   *
   * @var \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface
   */
  protected DataSurfacePipelineInterface $pipeline;

  /**
   * The field type plugin manager.
   *
   * @var \Drupal\Core\Field\FieldTypePluginManagerInterface
   */
  protected FieldTypePluginManagerInterface $fieldTypePluginManager;

  /**
   * The typed config manager, which owns the config schema fallback.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected TypedConfigManagerInterface $typedConfigManager;

  /**
   * Answers whether an account may administer an entity type's fields.
   *
   * The answer for adding a field, where there is no field config entity
   * yet to ask; the entity type id arrives as tool input and is never
   * spelled into a permission before it is known to name something.
   *
   * @param mixed $entity_type_id
   *   The entity type id as it arrived, not yet known to be a string or
   *   to name anything.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result, forbidden when the entity type does not exist.
   */
  protected function fieldAdministrationAccess(mixed $entity_type_id, AccountInterface $account): AccessResultInterface {
    return $this->fieldInstances->administrationAccess($entity_type_id, $account);
  }

  /**
   * Answers whether an account may update the field the values name.
   *
   * The field config entity is asked, not a permission assembled here:
   * core's own access handler delegates to the field storage, which is
   * where "administer <entity type> fields" is spelled once, and any
   * module refining that answer through entity access hooks is heard.
   * Values naming no field are refused with no reason attached, so the
   * refusal says nothing about whether the field exists.
   *
   * @param array $values
   *   The tool's input values.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function fieldUpdateAccess(array $values, AccountInterface $account): AccessResultInterface {
    $field = $this->resolveField($values);
    return $field === NULL
      ? AccessResult::forbidden()
      : $this->fieldSettingsAccess($field, $account);
  }

  /**
   * Answers whether an account may configure one field's settings.
   *
   * The provider's answer for the field, which is two answers with the
   * field type's second: the host gate stays exactly what it was — the
   * field config entity's own access for a saved field, field
   * administration for one about to be added — and the field type's
   * surface may refuse on top of it but never open what it closed.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field instance, saved or not.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The combined access result.
   */
  protected function fieldSettingsAccess(FieldConfigInterface $field, AccountInterface $account): AccessResultInterface {
    return $this->fieldInstances->accessFor($field, $account);
  }

  /**
   * Answers whether an account may add a field with these settings.
   *
   * The add counterpart: there is no saved field to ask, so the host
   * gate is the entity type's field administration permission and the
   * field type is asked about the instance that is about to exist. A
   * payload naming no existing field storage describes nothing the field
   * type could have an opinion on, so only the host gate answers.
   *
   * @param array $values
   *   The tool's input values.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The combined access result.
   */
  protected function fieldAddAccess(array $values, AccountInterface $account): AccessResultInterface {
    $field = $this->unsavedField($values);
    return $field === NULL
      ? $this->fieldAdministrationAccess($values['entity_type_id'] ?? NULL, $account)
      : $this->fieldSettingsAccess($field, $account);
  }

  /**
   * Builds the unsaved field instance a payload describes, if it can.
   *
   * The same object the settings input definition is refined against: a
   * field config that has never been saved is still a complete subject
   * for both the surface and the access answer, which is what lets a
   * tool describe and gate a field before it exists.
   *
   * @param array $values
   *   The tool's input values.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface|null
   *   The unsaved field, or NULL when the values name no field storage.
   */
  protected function unsavedField(array $values): ?FieldConfigInterface {
    foreach (['entity_type_id', 'bundle', 'field_name'] as $key) {
      if (!isset($values[$key]) || !is_string($values[$key]) || $values[$key] === '') {
        return NULL;
      }
    }
    if (!$this->entityTypeManager->hasDefinition($values['entity_type_id'])) {
      return NULL;
    }
    $storage = FieldStorageConfig::loadByName($values['entity_type_id'], $values['field_name']);
    return $storage === NULL ? NULL : FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $values['bundle'],
    ]);
  }

  /**
   * Loads the field instance the values name, if the values name one.
   *
   * @param array $values
   *   The tool's input values.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface|null
   *   The field, or NULL when the values are not three non-empty
   *   strings naming an existing entity type and an existing field.
   */
  protected function resolveField(array $values): ?FieldConfigInterface {
    foreach (['entity_type_id', 'bundle', 'field_name'] as $key) {
      if (!isset($values[$key]) || !is_string($values[$key]) || $values[$key] === '') {
        return NULL;
      }
    }
    if (!$this->entityTypeManager->hasDefinition($values['entity_type_id'])) {
      return NULL;
    }
    $field = FieldConfig::loadByName($values['entity_type_id'], $values['bundle'], $values['field_name']);
    return $field instanceof FieldConfigInterface ? $field : NULL;
  }

  /**
   * Builds the settings input definition for one field instance.
   *
   * The `settings` key of the field instance surface, converted: the
   * field type's own settings surface, mounted by coordinate, with every
   * key, its meaning, the values it allows and what it starts from.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field instance whose settings are being described. It need not
   *   be saved; an unsaved one describes a field about to be added.
   * @param \Drupal\tool\TypedData\InputDefinitionInterface $advertised
   *   The static definition, returned unchanged when neither a surface
   *   nor a config schema can say anything better.
   * @param mixed $default_value
   *   The default for the settings map as a whole.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The definition for this field.
   */
  protected function settingsInputDefinition(FieldConfigInterface $field, InputDefinitionInterface $advertised, mixed $default_value): InputDefinitionInterface {
    $surface = $this->fieldInstances->surfaceFor($field);
    if ($surface !== NULL) {
      $definition = $this->surfaceInputDefinitions->fromKey($surface, FieldInstanceSurfaceProvider::SETTINGS);
      $definition->setDefaultValue($default_value);
      return $definition;
    }
    return $this->schemaSettingsInputDefinition($field->getType(), $default_value) ?? $advertised;
  }

  /**
   * Runs one settings payload through the field instance surface.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The field instance surface.
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field the settings are written to, which the target saves.
   * @param mixed $settings
   *   The settings input.
   * @param \Drupal\Core\Access\AccessResultInterface $access
   *   The access answer the tool already resolved.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The pipeline's result.
   */
  protected function submitSettings(DataSurfaceInterface $surface, FieldConfigInterface $field, mixed $settings, AccessResultInterface $access): DataSurfaceResult {
    return $this->pipeline->submit(
      $surface,
      [FieldInstanceSurfaceProvider::SETTINGS => is_array($settings) ? $settings : []],
      $this->fieldInstances->targetFor($field),
      access: $access,
    );
  }

  /**
   * Derives a settings definition from config schema, as a fallback.
   *
   * What a caller gets for a field type that declares no surface: the
   * serialized shape of the stored settings, with whatever the schema
   * happens to label, and no vocabulary, defaults or bounds.
   *
   * @param string $field_type
   *   The field type plugin identifier.
   * @param mixed $default_value
   *   The default for the settings map as a whole.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface|null
   *   The definition, or NULL when the field type has no settings
   *   schema either.
   */
  protected function schemaSettingsInputDefinition(string $field_type, mixed $default_value): ?InputDefinitionInterface {
    $typed_config = $this->typedConfigManager;
    $config_id = 'field.field_settings.' . $field_type;
    if (!$typed_config->hasConfigSchema($config_id)) {
      return NULL;
    }
    $definition = InputDefinition::fromConfigSchema($typed_config->getDefinition($config_id));
    $definition->setRequired(FALSE);
    $definition->setDefaultValue($default_value);
    return $definition;
  }

}
