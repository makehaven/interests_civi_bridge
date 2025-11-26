<?php

namespace Drupal\interests_civi_bridge\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\profile\Entity\ProfileType;

/**
 * Settings form for the Interests CiviCRM Bridge module.
 */
class InterestsCiviBridgeSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'interests_civi_bridge_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['interests_civi_bridge.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('interests_civi_bridge.settings');

    $form['profile_bundle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Profile type machine name'),
      '#default_value' => $config->get('profile_bundle') ?: 'main',
      '#description' => $this->buildProfileBundleDescription(),
      '#required' => TRUE,
    ];

    $bundle = $form_state->getValue('profile_bundle') ?: $config->get('profile_bundle');
    $form['profile_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Profile field machine name'),
      '#default_value' => $config->get('profile_field') ?: 'field_member_areas_interest',
      '#description' => $this->buildProfileFieldDescription($bundle),
      '#required' => TRUE,
    ];

    $form['civicrm_custom_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CiviCRM custom field identifier'),
      '#default_value' => $config->get('civicrm_custom_field') ?: '',
      '#description' => $this->t('The identifier for the specific custom FIELD. Accepts numeric IDs (e.g. "75"), machine names (e.g. "custom_75"), or column names (e.g. "area_of_interest_75"). NOTE: Do not use the Custom Group ID (e.g. 24). You can find this ID in the `civicrm_custom_field` table or by inspecting the field in the CiviCRM UI.'),
      '#required' => TRUE,
    ];

    $form['enable_push'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Push Drupal changes to CiviCRM'),
      '#default_value' => $config->get('enable_push') ?? TRUE,
    ];

    $form['enable_pull'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Pull CiviCRM changes back into Drupal'),
      '#default_value' => $config->get('enable_pull') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Builds a profile bundle description listing available types.
   */
  protected function buildProfileBundleDescription(): TranslatableMarkup {
    $types = ProfileType::loadMultiple();
    if (!$types) {
      return $this->t('Create a profile type before configuring this bridge.');
    }
    $labels = [];
    foreach ($types as $type) {
      $labels[] = sprintf('%s (%s)', $type->label(), $type->id());
    }
    return $this->t('Available profile types: @list', ['@list' => implode(', ', $labels)]);
  }

  /**
   * Builds helper text describing available taxonomy fields on the bundle.
   */
  protected function buildProfileFieldDescription(?string $bundle): TranslatableMarkup {
    if (!$bundle) {
      return $this->t('Enter the field_machine_name that stores the taxonomy selections.');
    }

    $taxonomy_fields = [];
    try {
      $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('profile', $bundle);
      foreach ($field_definitions as $field_name => $definition) {
        if ($definition->getType() === 'entity_reference' && ($definition->getSetting('target_type') === 'taxonomy_term')) {
          $taxonomy_fields[] = $field_name;
        }
      }
    }
    catch (\Exception $exception) {
      return $this->t('Unable to load fields for the %bundle profile bundle.', ['%bundle' => $bundle]);
    }

    if (!$taxonomy_fields) {
      return $this->t('No taxonomy reference fields were detected for the %bundle profile.', ['%bundle' => $bundle]);
    }

    return $this->t('Taxonomy fields on %bundle: @list', ['%bundle' => $bundle, '@list' => implode(', ', $taxonomy_fields)]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('interests_civi_bridge.settings')
      ->set('profile_bundle', $form_state->getValue('profile_bundle'))
      ->set('profile_field', $form_state->getValue('profile_field'))
      ->set('civicrm_custom_field', $form_state->getValue('civicrm_custom_field'))
      ->set('enable_push', $form_state->getValue('enable_push'))
      ->set('enable_pull', $form_state->getValue('enable_pull'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
