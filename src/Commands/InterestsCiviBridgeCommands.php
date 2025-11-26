<?php

namespace Drupal\interests_civi_bridge\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\interests_civi_bridge\InterestsSyncManager;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the Interests CiviCRM Bridge.
 */
class InterestsCiviBridgeCommands extends DrushCommands {

  /**
   * Constructs the command handler.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected InterestsSyncManager $syncManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('interests_civi_bridge.sync_manager'),
    );
  }

  /**
   * Pushes all configured profile interests to CiviCRM.
   *
   * @command interests:push-interests
   * @aliases interests-push
   * @option limit Limit the number of profiles processed.
   * @option chunk Size of each load chunk (default: 50).
   * @usage drush interests:push-interests
   *   Sync all existing profile interests into CiviCRM.
   * @usage drush interests:push-interests --limit=100
   *   Sync the first 100 profiles.
   */
  public function pushInterests(array $options = ['limit' => NULL, 'chunk' => 50]): void {
    $settings = $this->configFactory->get(InterestsSyncManager::SETTINGS_NAME);
    $bundle = $settings->get('profile_bundle');
    if (!$bundle) {
      $this->logger()->error('No profile bundle is configured. Update the module settings first.');
      return;
    }

    $profile_storage = $this->entityTypeManager->getStorage('profile');
    $query = $profile_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->sort('profile_id', 'ASC');

    $limit = isset($options['limit']) ? (int) $options['limit'] : 0;
    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $ids = $query->execute();
    if (!$ids) {
      $this->logger()->notice('No profiles found for the %bundle bundle.', ['%bundle' => $bundle]);
      return;
    }

    $chunk_size = isset($options['chunk']) ? (int) $options['chunk'] : 50;
    if ($chunk_size <= 0) {
      $chunk_size = 50;
    }

    $total = count($ids);
    $this->io()->progressStart($total);
    $processed = 0;

    foreach (array_chunk($ids, $chunk_size) as $chunk) {
      $profiles = $profile_storage->loadMultiple($chunk);
      foreach ($profiles as $profile) {
        $this->syncManager->syncProfile($profile, TRUE);
        $processed++;
        $this->io()->progressAdvance();
      }
    }

    $this->io()->progressFinish();
    $this->io()->success(sprintf('Synchronized %d profile(s) with CiviCRM.', $processed));
  }

}
