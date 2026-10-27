<?php

declare(strict_types=1);

namespace Drupal\ds_adopter_pathauto_two\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Url;
use Drupal\data_surface\Resource\ArrangementGroup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\ds_adopter_pathauto_two\Keys\PathautoEntityTypes;
use Drupal\ds_adopter_pathauto_two\Keys\PunctuationCharacters;
use Drupal\ds_adopter_pathauto_two\Shape\PunctuationAction;
use Drupal\ds_adopter_pathauto_two\Storage\PathautoSettingsStorage;
use Drupal\pathauto\PathautoGeneratorInterface;

/**
 * Pathauto's settings, as PathautoSettingsForm asks for them.
 */
#[Resource('adopter.pathauto_two.settings', storage: PathautoSettingsStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure Pathauto'),
  description: new TranslatableMarkup('Sets which entity types get URL aliases and how Pathauto cleans the text it builds aliases from.'),
  permission: 'administer pathauto',
)]
final class PathautoSettingsResource implements ResourceInterface {

  /**
   * The longest alias the path_alias table holds.
   *
   * AliasStorageHelper::getAliasSchemaMaxLength() answers this constant.
   */
  public const ALIAS_MAX = 255;

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $help = self::helpLink();

    $inputs->addEach('enabled_entity_types', 'boolean', from: PathautoEntityTypes::class, default: FALSE)
      ->setLabel(t('Enabled entity types'))
      ->setDescription(t('Enable to add a path field and allow to define alias patterns for the given type. Disabled types already define a path field themselves or currently have a pattern.'));

    $inputs->add('verbose', 'boolean', t('Verbose'), default: FALSE)
      ->setDescription(t('Display alias changes (except during bulk updates).'));

    $inputs->add('separator', 'string', t('Separator'), default: '-')
      ->setDescription(t('Character used to separate words in titles. This will replace any spaces and punctuation characters. Using a space or + character can cause unexpected results.'))
      ->addConstraint('Length', ['max' => 1]);

    $inputs->add('case', 'boolean', t('Character case'), default: TRUE)
      ->setDescription(t('Convert token values to lowercase.'));

    // Limits.
    $inputs->add('max_length', 'integer', t('Maximum alias length'), default: 150)
      ->setDescription(t('Maximum length of aliases to generate. 100 is the recommended length. @max is the maximum possible length.', ['@max' => self::ALIAS_MAX]) . $help)
      ->addConstraint('Range', ['min' => 1, 'max' => self::ALIAS_MAX]);

    $inputs->add('max_component_length', 'integer', t('Maximum component length'), default: 100)
      ->setDescription(t('Maximum text length of any component in the alias (e.g., [title]). 100 is the recommended length. @max is the maximum possible length.', ['@max' => self::ALIAS_MAX]) . $help)
      ->addConstraint('Range', ['min' => 1, 'max' => self::ALIAS_MAX]);

    // Updates.
    $inputs->add('update_action', 'integer', t('Update action'), default: PathautoGeneratorInterface::UPDATE_ACTION_DELETE)
      ->setDescription(self::updateActionDescription())
      ->setSetting('widget', 'radios')
      ->addConstraint('LabeledChoice', [
        'labels' => [
          PathautoGeneratorInterface::UPDATE_ACTION_NO_NEW => t('Do nothing. Leave the old alias intact.'),
          PathautoGeneratorInterface::UPDATE_ACTION_LEAVE => t('Create a new alias. Leave the existing alias functioning.'),
          PathautoGeneratorInterface::UPDATE_ACTION_DELETE => t('Create a new alias. Delete the old alias.'),
        ],
      ]);

    // Cleaning.
    $inputs->add('transliterate', 'boolean', t('Transliterate prior to creating alias'), default: TRUE)
      ->setDescription(t('When a pattern includes certain characters (such as those with accents) should Pathauto attempt to transliterate them into the US-ASCII alphabet?'));

    $inputs->add('reduce_ascii', 'boolean', t('Reduce strings to letters and numbers'), default: FALSE)
      ->setDescription(t('Filters the new alias to only letters and numbers found in the ASCII-96 set.'));

    $inputs->add('ignore_words', 'string', t('Strings to Remove'))
      ->setDescription(t('Words to strip out of the URL alias, separated by commas. Do not use this to remove punctuation.'))
      ->setSetting('multiline', TRUE);

    $inputs->addList('safe_tokens', 'string', t('Safe tokens'), default: [])
      ->setDescription(t('List of tokens that are safe to use in alias patterns and do not need to be cleaned. For example urls, aliases, machine names. Separated with a comma.'))
      ->setSetting('widget', 'ds_adopter_pathauto_two_comma_list');

    $inputs->addEach('punctuation', PunctuationAction::class, from: PunctuationCharacters::class)
      ->setLabel(t('Punctuation'));
    $inputs->describe('punctuation', group: ArrangementGroup::details('punctuation', t('Punctuation'), collapsed: TRUE));
  }


  /**
   * The link to pathauto's help page, while the help module is on.
   */
  protected static function helpLink(): string {
    if (!\Drupal::moduleHandler()->moduleExists('help')) {
      return '';
    }
    $url = Url::fromRoute('help.page', ['name' => 'pathauto'])->toString(TRUE)->getGeneratedUrl();
    return ' ' . t('See <a href=":pathauto-help">Pathauto help</a> for details.', [':pathauto-help' => $url]);
  }

  /**
   * What the update action means, pointing at the redirect module.
   */
  protected static function updateActionDescription(): string {
    $description = t('What should Pathauto do when updating an existing content item which already has an alias?');
    if (\Drupal::moduleHandler()->moduleExists('redirect')) {
      $url = Url::fromRoute('redirect.settings')->toString(TRUE)->getGeneratedUrl();
      return $description . ' ' . t('The <a href=":url">Redirect module settings</a> affect whether a redirect is created when an alias is deleted.', [':url' => $url]);
    }
    return $description . ' ' . t('Considering installing the <a href=":url">Redirect module</a> to get redirects when your aliases change.', [':url' => 'http://drupal.org/project/redirect']);
  }

}
