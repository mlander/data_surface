<?php

declare(strict_types=1);

namespace Drupal\ds_adopter_pathauto_two\Keys;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;
use Drupal\ds_adopter_pathauto_two\Storage\PathautoSettingsStorage;
use Drupal\pathauto\AliasCleanerInterface;
use Drupal\pathauto\PathautoGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the punctuation characters the alias cleaner knows.
 *
 * Pathauto's list, after hook_pathauto_punctuation_chars_alter(). A
 * character equal to the stored separator starts as "Replace by
 * separator", every other as "Remove".
 */
final class PunctuationCharacters implements SiteListInterface, ContainerInjectionInterface {

  /**
   * Constructs the provider.
   */
  public function __construct(
    protected readonly AliasCleanerInterface $aliasCleaner,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('pathauto.alias_cleaner'), $container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    $config = $this->configFactory->get(PathautoSettingsStorage::CONFIG);
    // The alias cleaner caches the altered list without a tag; a module
    // that alters it is a cache rebuild, which clears every tag.
    $keys = (new SiteList())->addCacheableDependency($config);
    foreach ($this->aliasCleaner->getPunctuationCharacters() as $id => $character) {
      $default = $character['value'] === $config->get('separator')
        ? PathautoGeneratorInterface::PUNCTUATION_REPLACE
        : PathautoGeneratorInterface::PUNCTUATION_REMOVE;
      $keys->add((string) $id, sprintf('%s (%s)', $character['name'], $character['value']), default: $default);
    }
    return $keys;
  }

}
