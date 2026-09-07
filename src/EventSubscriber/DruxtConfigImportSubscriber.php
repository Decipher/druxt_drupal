<?php

declare(strict_types=1);

namespace Drupal\druxt\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Refuses a configuration import that would expose an unsafe resource.
 *
 * The configuration schema carries the rule, but nothing runs schema
 * constraints on save or on import, so a hand-edited or imported
 * druxt.settings would otherwise be accepted. Being on the list grants a
 * blanket entity access result, so the rule has to hold here too.
 */
class DruxtConfigImportSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The configuration name this subscriber guards.
   */
  private const CONFIG_NAME = 'druxt.settings';

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed configuration manager.
   */
  public function __construct(
    protected TypedConfigManagerInterface $typedConfigManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::IMPORT_VALIDATE => ['onConfigImportValidate', 20]];
  }

  /**
   * Checks the resource list an import would store.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   *   The configuration import event.
   */
  public function onConfigImportValidate(ConfigImporterEvent $event): void {
    $importer = $event->getConfigImporter();

    foreach (['create', 'update'] as $op) {
      if (!in_array(self::CONFIG_NAME, $importer->getUnprocessedConfiguration($op), TRUE)) {
        continue;
      }

      $data = $importer->getStorageComparer()
        ->getSourceStorage()
        ->read(self::CONFIG_NAME);
      if (!is_array($data)) {
        continue;
      }

      $violations = $this->typedConfigManager
        ->createFromNameAndData(self::CONFIG_NAME, $data)
        ->validate();

      foreach ($violations as $violation) {
        $importer->logError((string) $this->t('@config: @message', [
          '@config' => self::CONFIG_NAME,
          '@message' => strip_tags((string) $violation->getMessage()),
        ]));
      }
    }
  }

}
