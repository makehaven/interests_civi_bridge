<?php

namespace Drupal\interests_civi_bridge;

use CRM_Core_BAO_UFMatch;
use CRM_Core_DAO;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\civicrm\Civicrm;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Coordinates two-way syncing between Drupal taxonomy fields and CiviCRM.
 */
class InterestsSyncManager {

  /**
   * Settings config name.
   */
  public const SETTINGS_NAME = 'interests_civi_bridge.settings';

  /**
   * Cached configuration.
   */
  protected ?Config $settings = NULL;

  protected bool $civicrmInitialized = FALSE;

  protected array $fieldMetadataCache = [];

  protected array $optionGroupCache = [];

  protected array $termMapCache = [];

  protected array $suppressedProfileIds = [];

  protected array $suppressedProfileHashes = [];

  protected array $suppressedContactIds = [];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $fieldManager,
    protected Civicrm $civicrm,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Reacts to Drupal profile saves.
   */
  public function handleProfileSave(ProfileInterface $profile, bool $isNew): void {
    $this->syncProfile($profile, $isNew);
  }

  /**
   * Pushes a profile's interests to CiviCRM.
   *
   * @param \Drupal\profile\Entity\ProfileInterface $profile
   *   The profile to sync.
   * @param bool $force
   *   When TRUE, skip change detection (used for inserts/batch syncs).
   */
  public function syncProfile(ProfileInterface $profile, bool $force = FALSE): void {
    if (!$this->getSettings()->get('enable_push')) {
      return;
    }

    if (!$this->shouldProcessProfile($profile)) {
      return;
    }

    if ($this->isProfileSuppressed($profile)) {
      return;
    }

    $field_name = $this->getSettings()->get('profile_field');
    $current_ids = $this->extractTermIds($profile, $field_name);

    if (!$force && !$profile->isNew()) {
      $original_ids = $this->loadOriginalTermIds($profile, $field_name);
      sort($current_ids);
      sort($original_ids);
      if ($current_ids === $original_ids) {
        return;
      }
    }

    // Safeguard: If a NEW profile is created with NO interests, do not wipe
    // potentially existing data in CiviCRM. Instead, try to populate Drupal
    // from CiviCRM (Reverse Sync).
    if ($profile->isNew() && empty($current_ids)) {
      $owner = $profile->getOwner();
      if ($owner instanceof UserInterface) {
        $contact_id = $this->getContactIdForUser($owner);
        if ($contact_id) {
          $this->logger->info('New empty profile created for user @uid. Checking CiviCRM contact @cid for existing interests to pull.', [
            '@uid' => $owner->id(),
            '@cid' => $contact_id,
          ]);
          $this->pullFromCivicrm($owner, $contact_id);
          return;
        }
      }
    }

    $this->pushProfileToCivicrm($profile);
  }

  /**
   * Responds to CiviCRM post hooks.
   */
  public function handleCivicrmPost(int $contactId): void {
    if (!$this->getSettings()->get('enable_pull')) {
      return;
    }

    if ($this->isContactSuppressed($contactId)) {
      $this->releaseContactSuppression($contactId);
      return;
    }

    if (!$this->initCivicrm()) {
      return;
    }

    $user_id = CRM_Core_BAO_UFMatch::getUFId($contactId);
    if (!$user_id) {
      $this->logger->debug('No Drupal user is linked to CiviCRM contact @cid.', ['@cid' => $contactId]);
      return;
    }

    /** @var \Drupal\user\UserStorageInterface $user_storage */
    $user_storage = $this->entityTypeManager->getStorage('user');
    /** @var \Drupal\user\UserInterface|null $user */
    $user = $user_storage->load($user_id);
    if (!$user) {
      $this->logger->warning('CiviCRM contact @cid is linked to missing Drupal user ID @uid.', [
        '@cid' => $contactId,
        '@uid' => $user_id,
      ]);
      return;
    }

    $this->pullFromCivicrm($user, $contactId);
  }

  /**
   * Pushes taxonomy terms from Drupal to CiviCRM.
   */
  protected function pushProfileToCivicrm(ProfileInterface $profile): void {
    if (!$this->initCivicrm()) {
      return;
    }

    $field_metadata = $this->getCustomFieldMetadata();
    if (!$field_metadata) {
      return;
    }

    $owner = $profile->getOwner();
    if (!$owner instanceof UserInterface) {
      $this->logger->warning('Profile ID @id is missing an owner; skipping CiviCRM sync.', ['@id' => $profile->id()]);
      return;
    }

    $contact_id = $this->getContactIdForUser($owner);
    if (!$contact_id) {
      $this->logger->warning('Unable to locate a CiviCRM contact ID for user @uid.', ['@uid' => $owner->id()]);
      return;
    }

    $field_name = $this->getSettings()->get('profile_field');
    $term_names = $this->extractTermNames($profile, $field_name);
    $value = $this->formatCiviFieldValue($term_names, $field_metadata);

    $params = [
      'id' => $contact_id,
      $field_metadata['api_field_name'] => $value,
      'check_permissions' => 0,
    ];

    $this->suppressContact($contact_id);
    try {
      civicrm_api3('Contact', 'create', $params);
      $this->logger->notice('Synced @count interest(s) from Drupal to CiviCRM for user @uid (contact @cid).', [
        '@count' => count($term_names),
        '@uid' => $owner->id(),
        '@cid' => $contact_id,
      ]);
    }
    catch (\Exception $exception) {
      $this->logger->error('Failed to sync interests for user @uid to CiviCRM: @message', [
        '@uid' => $owner->id(),
        '@message' => $exception->getMessage(),
      ]);
    }
    finally {
      $this->releaseContactSuppression($contact_id);
    }
  }

  /**
   * Pulls option values from CiviCRM back into Drupal.
   */
  protected function pullFromCivicrm(UserInterface $user, int $contactId): void {
    $field_metadata = $this->getCustomFieldMetadata();
    if (!$field_metadata) {
      return;
    }

    $bundle = $this->getSettings()->get('profile_bundle');
    $profile = $this->loadUserProfile($user, $bundle);

    if (!$profile) {
      $this->logger->debug('User @uid is missing the %bundle profile; skipping pull from CiviCRM.', [
        '@uid' => $user->id(),
        '%bundle' => $bundle,
      ]);
      return;
    }

    if (!$this->shouldProcessProfile($profile)) {
      return;
    }

    $option_values = $this->fetchCiviFieldValues($contactId, $field_metadata);
    $target_term_ids = $this->mapOptionValuesToTermIds(
      $option_values,
      $field_metadata,
      $bundle,
      $this->getSettings()->get('profile_field')
    );

    $current_ids = $this->extractTermIds($profile, $this->getSettings()->get('profile_field'));
    sort($current_ids);
    sort($target_term_ids);
    if ($current_ids === $target_term_ids) {
      return;
    }

    $this->markProfileSuppressed($profile);
    $profile->set($this->getSettings()->get('profile_field'), $this->buildReferenceArray($target_term_ids));

    try {
      $profile->save();
      $this->logger->notice('Updated Drupal interests on profile @pid from CiviCRM contact @cid.', [
        '@pid' => $profile->id(),
        '@cid' => $contactId,
      ]);
    }
    catch (\Exception $exception) {
      $this->logger->error('Failed to update profile @pid from CiviCRM: @message', [
        '@pid' => $profile->id(),
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * Determines if this profile should trigger syncing.
   */
  protected function shouldProcessProfile(ProfileInterface $profile): bool {
    $field = $this->getSettings()->get('profile_field');
    $bundle = $this->getSettings()->get('profile_bundle');
    if (!$field || !$bundle) {
      $this->logger->error('Interests bridge is missing required configuration.');
      return FALSE;
    }

    if ($profile->bundle() !== $bundle) {
      return FALSE;
    }

    if (!$profile->hasField($field)) {
      $this->logger->warning('Profile bundle %bundle no longer provides %field.', [
        '%bundle' => $bundle,
        '%field' => $field,
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Loads the original taxonomy values for change detection.
   */
  protected function loadOriginalTermIds(ProfileInterface $profile, string $field_name): array {
    // Use the original entity if available (populated in hook_entity_update).
    if (isset($profile->original)) {
      return $this->extractTermIds($profile->original, $field_name);
    }

    if (!$profile->id()) {
      return [];
    }

    try {
      /** @var \Drupal\profile\ProfileStorageInterface $storage */
      $storage = $this->entityTypeManager->getStorage('profile');
      /** @var \Drupal\profile\Entity\ProfileInterface|null $original */
      $original = $storage->loadUnchanged($profile->id());
    }
    catch (EntityStorageException $exception) {
      $this->logger->error('Failed to load original profile @id: @message', [
        '@id' => $profile->id(),
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }

    if (!$original) {
      return [];
    }

    return $this->extractTermIds($original, $field_name);
  }

  /**
   * Extracts taxonomy target IDs from a profile field.
   */
  protected function extractTermIds(ProfileInterface $profile, string $field_name): array {
    if (!$profile->hasField($field_name) || $profile->get($field_name)->isEmpty()) {
      return [];
    }
    $ids = [];
    foreach ($profile->get($field_name) as $item) {
      if (!empty($item->target_id)) {
        $ids[] = (int) $item->target_id;
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Extracts taxonomy term labels.
   */
  protected function extractTermNames(ProfileInterface $profile, string $field_name): array {
    if (!$profile->hasField($field_name) || $profile->get($field_name)->isEmpty()) {
      return [];
    }
    $names = [];
    foreach ($profile->get($field_name)->referencedEntities() as $term) {
      $names[] = $term->label();
    }
    return $names;
  }

  /**
   * Ensures CiviCRM is bootstrapped.
   */
  protected function initCivicrm(): bool {
    if ($this->civicrmInitialized) {
      return TRUE;
    }
    try {
      $this->civicrm->initialize();
      $this->civicrmInitialized = TRUE;
      return TRUE;
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Unable to initialize CiviCRM: @message', ['@message' => $throwable->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Provides saved configuration.
   */
  protected function getSettings(): Config {
    if (!$this->settings) {
      $this->settings = $this->configFactory->get(self::SETTINGS_NAME);
    }
    return $this->settings;
  }

  /**
   * Resolves metadata for the configured custom field.
   */
  protected function getCustomFieldMetadata(): ?array {
    $identifier = trim((string) $this->getSettings()->get('civicrm_custom_field'));
    if ($identifier === '') {
      $this->logger->error('No CiviCRM custom field has been configured for the interests bridge.');
      return NULL;
    }

    if (array_key_exists($identifier, $this->fieldMetadataCache)) {
      return $this->fieldMetadataCache[$identifier];
    }

    $strategies = [];
    if (ctype_digit($identifier)) {
      $strategies[] = ['id', (int) $identifier];
    }
    if (preg_match('/^custom_(\d+)$/i', $identifier, $matches)) {
      $strategies[] = ['id', (int) $matches[1]];
      $strategies[] = ['column_name', $identifier];
    }
    $strategies[] = ['name', $identifier];

    foreach ($strategies as [$field, $value]) {
      try {
        $result = civicrm_api3('CustomField', 'get', [
          'sequential' => 1,
          'return' => ['id', 'name', 'label', 'column_name', 'option_group_id', 'html_type', 'data_type', 'is_multi_select'],
          $field => $value,
          'check_permissions' => 0,
          'options' => ['limit' => 1],
        ]);
      }
      catch (\Exception $exception) {
        $this->logger->error('Failed to lookup CiviCRM custom field via @field: @message', [
          '@field' => $field,
          '@message' => $exception->getMessage(),
        ]);
        continue;
      }

      if (!empty($result['values'][0])) {
        $metadata = $result['values'][0];
        $metadata['api_field_name'] = 'custom_' . $metadata['id'];
        $metadata['is_multi_select'] = !empty($metadata['is_multi_select']) || in_array($metadata['html_type'], ['CheckBox', 'Multi-Select', 'AdvMulti-Select'], TRUE);
        $this->fieldMetadataCache[$identifier] = $metadata;
        return $metadata;
      }
    }

    $this->logger->error('Unable to resolve the configured CiviCRM custom field identifier "@identifier".', ['@identifier' => $identifier]);
    $this->fieldMetadataCache[$identifier] = NULL;
    return NULL;
  }

  /**
   * Returns a Contact ID for a Drupal user.
   */
  protected function getContactIdForUser(UserInterface $user): ?int {
    $contact_id = CRM_Core_BAO_UFMatch::getContactId($user->id());
    if ($contact_id) {
      return $contact_id;
    }

    $email = $user->getEmail();
    if (!$email) {
      return NULL;
    }

    try {
      $result = civicrm_api3('Contact', 'get', [
        'sequential' => 1,
        'email' => $email,
        'return' => ['id'],
        'options' => ['limit' => 1],
        'check_permissions' => 0,
      ]);
    }
    catch (\Exception $exception) {
      $this->logger->error('Failed to look up CiviCRM contact by email (@email): @message', [
        '@email' => $email,
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }

    if (!empty($result['values'][0]['id'])) {
      return (int) $result['values'][0]['id'];
    }

    return NULL;
  }

  /**
   * Builds the payload to send to CiviCRM.
   */
  protected function formatCiviFieldValue(array $term_names, array $field_metadata): array|string {
    $option_group_id = $field_metadata['option_group_id'] ?? NULL;
    $is_multi = !empty($field_metadata['is_multi_select']);

    if ($option_group_id) {
      $values = $this->mapTermsToOptionValues($term_names, (int) $option_group_id);
      if ($values === NULL) {
        return $is_multi ? [] : '';
      }
      if ($is_multi) {
        $formatted = [];
        foreach ($values as $value) {
          $formatted[(string) $value] = (string) $value;
        }
        return $formatted;
      }
      return !empty($values) ? (string) reset($values) : '';
    }

    if ($is_multi) {
      return implode(', ', $term_names);
    }

    return $term_names[0] ?? '';
  }

  /**
   * Maps taxonomy term labels to option values.
   */
  protected function mapTermsToOptionValues(array $term_names, int $option_group_id): ?array {
    if (!$term_names) {
      return [];
    }

    $group = $this->loadOptionGroupMap($option_group_id);
    if (!$group) {
      return NULL;
    }

    $mapped = [];
    $missing = [];
    foreach ($term_names as $name) {
      $matched = FALSE;
      foreach ($this->generateMatchVariants($name) as $variant) {
        if (isset($group['variant_to_value'][$variant])) {
          $mapped[$group['variant_to_value'][$variant]] = TRUE;
          $matched = TRUE;
          break;
        }
      }
      if (!$matched) {
        $missing[] = $name;
      }
    }

    if ($missing) {
      $this->logger->warning('Could not map the following taxonomy interests to CiviCRM options: @list', ['@list' => implode(', ', $missing)]);
    }

    return array_keys($mapped);
  }

  /**
   * Loads option value metadata for an option group.
   */
  protected function loadOptionGroupMap(int $option_group_id): array {
    if (isset($this->optionGroupCache[$option_group_id])) {
      return $this->optionGroupCache[$option_group_id];
    }

    try {
      $result = civicrm_api3('OptionValue', 'get', [
        'sequential' => 1,
        'option_group_id' => $option_group_id,
        'is_active' => 1,
        'return' => ['label', 'name', 'value'],
        'options' => ['limit' => 0],
        'check_permissions' => 0,
      ]);
    }
    catch (\Exception $exception) {
      $this->logger->error('Unable to load option values for group @group: @message', [
        '@group' => $option_group_id,
        '@message' => $exception->getMessage(),
      ]);
      $this->optionGroupCache[$option_group_id] = [];
      return [];
    }

    $map = [
      'variant_to_value' => [],
      'value_to_label' => [],
    ];

    foreach ($result['values'] ?? [] as $option) {
      $value = (string) $option['value'];
      $label = (string) ($option['label'] ?? $option['name'] ?? $value);
      $map['value_to_label'][$value] = $label;
      foreach (['label', 'name', 'value'] as $property) {
        if (empty($option[$property])) {
          continue;
        }
        foreach ($this->generateMatchVariants((string) $option[$property]) as $variant) {
          $map['variant_to_value'][$variant] = $value;
        }
      }
    }

    $this->optionGroupCache[$option_group_id] = $map;
    return $map;
  }

  /**
   * Fetches the configured field values for a contact.
   */
  protected function fetchCiviFieldValues(int $contactId, array $field_metadata): array {
    try {
      $result = civicrm_api3('Contact', 'getsingle', [
        'id' => $contactId,
        'return' => [$field_metadata['api_field_name']],
        'check_permissions' => 0,
      ]);
    }
    catch (\Exception $exception) {
      $this->logger->error('Failed to read interests for contact @cid: @message', [
        '@cid' => $contactId,
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }

    $raw = $result[$field_metadata['api_field_name']] ?? NULL;
    if ($raw === NULL || $raw === '') {
      return [];
    }

    if (!empty($field_metadata['is_multi_select'])) {
      if (is_array($raw)) {
        return array_values(array_filter(array_map('strval', $raw)));
      }
      $separator = CRM_Core_DAO::VALUE_SEPARATOR;
      $parts = array_filter(explode($separator, trim((string) $raw, $separator)));
      return array_values(array_map('strval', $parts));
    }

    return [(string) $raw];
  }

  /**
   * Maps option values from CiviCRM to taxonomy term IDs.
   */
  protected function mapOptionValuesToTermIds(array $option_values, array $field_metadata, string $bundle, string $field_name): array {
    if (!$option_values) {
      return [];
    }

    $labels = [];
    $option_group_id = $field_metadata['option_group_id'] ?? NULL;
    if ($option_group_id) {
      $group = $this->loadOptionGroupMap((int) $option_group_id);
      foreach ($option_values as $value) {
        $key = (string) $value;
        $labels[] = $group['value_to_label'][$key] ?? $key;
      }
    }
    else {
      $labels = $option_values;
    }

    $term_map = $this->loadTermMap($bundle, $field_name);
    $term_ids = [];
    $missing = [];
    foreach ($labels as $label) {
      $matched = FALSE;
      foreach ($this->generateMatchVariants((string) $label) as $variant) {
        if (isset($term_map['variants'][$variant])) {
          $term_ids[$term_map['variants'][$variant]] = TRUE;
          $matched = TRUE;
          break;
        }
      }
      if (!$matched) {
        $missing[] = $label;
      }
    }

    if ($missing) {
      $this->logger->warning('CiviCRM provided interests that do not exist in Drupal: @list', ['@list' => implode(', ', $missing)]);
    }

    return array_keys($term_ids);
  }

  /**
   * Loads taxonomy terms for the configured field.
   */
  protected function loadTermMap(string $bundle, string $field_name): array {
    $cache_key = $bundle . ':' . $field_name;
    if (isset($this->termMapCache[$cache_key])) {
      return $this->termMapCache[$cache_key];
    }

    $definitions = [];
    try {
      $definitions = $this->fieldManager->getFieldDefinitions('profile', $bundle);
    }
    catch (\Exception $exception) {
      $this->logger->error('Unable to inspect fields for bundle %bundle: @message', [
        '%bundle' => $bundle,
        '@message' => $exception->getMessage(),
      ]);
      $this->termMapCache[$cache_key] = ['variants' => []];
      return $this->termMapCache[$cache_key];
    }

    if (empty($definitions[$field_name])) {
      $this->termMapCache[$cache_key] = ['variants' => []];
      return $this->termMapCache[$cache_key];
    }

    $definition = $definitions[$field_name];
    $settings = $definition->getSetting('handler_settings') ?? [];
    $target_bundles = array_keys($settings['target_bundles'] ?? []);

    if (!$target_bundles) {
      $this->termMapCache[$cache_key] = ['variants' => []];
      return $this->termMapCache[$cache_key];
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $term_storage->getQuery()->condition('vid', $target_bundles, 'IN')->accessCheck(FALSE);
    $tids = $query->execute();
    $terms = $tids ? $term_storage->loadMultiple($tids) : [];

    $map = ['variants' => [], 'terms' => []];
    foreach ($terms as $term) {
      $map['terms'][$term->id()] = $term;
      foreach ($this->generateMatchVariants($term->label()) as $variant) {
        $map['variants'][$variant] = $term->id();
      }
    }

    $this->termMapCache[$cache_key] = $map;
    return $map;
  }

  /**
   * Provides normalized variants for fuzzy matches.
   */
  protected function generateMatchVariants(string $value): array {
    $value = trim($value);
    if ($value === '') {
      return [];
    }

    $lower = strtolower($value);
    $variants = [
      $lower,
      str_replace([' ', '-'], '_', $lower),
      str_replace(['_', '-'], ' ', $lower),
      str_replace('_', '-', $lower),
      str_replace([' ', '_', '-'], '', $lower),
    ];

    $unique = [];
    foreach ($variants as $variant) {
      if ($variant === '') {
        continue;
      }
      $unique[$variant] = TRUE;
    }

    return array_keys($unique);
  }

  /**
   * Builds entity reference values.
   */
  protected function buildReferenceArray(array $term_ids): array {
    $rows = [];
    foreach ($term_ids as $tid) {
      $rows[] = ['target_id' => $tid];
    }
    return $rows;
  }

  /**
   * Loads the requested profile for a user.
   */
  protected function loadUserProfile(UserInterface $user, string $bundle): ?ProfileInterface {
    $storage = $this->entityTypeManager->getStorage('profile');
    $profiles = $storage->loadByProperties([
      'uid' => $user->id(),
      'type' => $bundle,
    ]);
    return $profiles ? reset($profiles) : NULL;
  }

  /**
   * Determines whether the profile save originated from this service.
   */
  protected function isProfileSuppressed(ProfileInterface $profile): bool {
    $hash = spl_object_hash($profile);
    if (isset($this->suppressedProfileHashes[$hash])) {
      unset($this->suppressedProfileHashes[$hash]);
      if ($profile->id()) {
        unset($this->suppressedProfileIds[$profile->id()]);
      }
      return TRUE;
    }

    if ($profile->id() && isset($this->suppressedProfileIds[$profile->id()])) {
      unset($this->suppressedProfileIds[$profile->id()]);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Flags a profile so the ensuing hook does not immediately push back to Civi.
   */
  protected function markProfileSuppressed(ProfileInterface $profile): void {
    if ($profile->id()) {
      $this->suppressedProfileIds[$profile->id()] = TRUE;
    }
    $this->suppressedProfileHashes[spl_object_hash($profile)] = TRUE;
  }

  /**
   * Tracks contacts modified by this module.
   */
  protected function suppressContact(int $contactId): void {
    $this->suppressedContactIds[$contactId] = TRUE;
  }

  /**
   * Removes a contact from the suppression list.
   */
  protected function releaseContactSuppression(int $contactId): void {
    unset($this->suppressedContactIds[$contactId]);
  }

  /**
   * Checks whether the contact update came from this module.
   */
  protected function isContactSuppressed(int $contactId): bool {
    return !empty($this->suppressedContactIds[$contactId]);
  }

}
