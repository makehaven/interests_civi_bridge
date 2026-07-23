<?php

namespace Drupal\interests_civi_bridge\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets a member pick their Areas of Interest on the onboarding thank-you page.
 *
 * Saving writes the member's `main` profile field_member_areas_interest, which
 * interests_civi_bridge syncs to CiviCRM — driving Slack channel routing and
 * the personalized weekly digest. Kept on the end-of-signup thank-you page (not
 * the join form) so the funnel stays short but we still capture interests while
 * the member is engaged (JR, 2026-07).
 */
class InterestPickerForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  public function getFormId() {
    return 'interests_civi_bridge_interest_picker';
  }

  /**
   * Load the member's 'main' profile (creating one in memory if absent).
   */
  protected function memberProfile(): ?object {
    if (!$this->currentUser->isAuthenticated()) {
      return NULL;
    }
    $user = User::load($this->currentUser->id());
    if (!$user) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('profile');
    $profile = $storage->loadByUser($user, 'main');
    if (!$profile) {
      $profile = $storage->create(['type' => 'main', 'uid' => $user->id()]);
    }
    return $profile;
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    if (!$this->currentUser->isAuthenticated()) {
      return ['#markup' => $this->t('Log in to choose your areas of interest.')];
    }

    // Top-level Area of Interest terms make a tidy picker.
    $options = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadTree('area_of_interest', 0, 1) as $term) {
      $options[$term->tid] = $term->name;
    }

    $default = [];
    $profile = $this->memberProfile();
    if ($profile && $profile->hasField('field_member_areas_interest')) {
      foreach ($profile->get('field_member_areas_interest') as $item) {
        if (!empty($item->target_id)) {
          $default[] = $item->target_id;
        }
      }
    }

    $form['#attributes']['class'][] = 'mh-interest-picker';
    $form['intro'] = [
      '#markup' => '<p class="mh-interest-picker__intro">' . $this->t('Pick the areas you\'re interested in. We use this to add you to the right Slack channels and tailor your weekly email — you can change it any time.') . '</p>',
    ];
    $form['interests'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Your areas of interest'),
      '#title_display' => 'invisible',
      '#options' => $options,
      '#default_value' => $default,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save my interests'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $profile = $this->memberProfile();
    if (!$profile) {
      $this->messenger()->addError($this->t('Sorry — we could not save your interests. Please try again.'));
      return;
    }
    $selected = array_values(array_filter((array) $form_state->getValue('interests')));
    $profile->set('field_member_areas_interest', $selected);
    $profile->save();
    $this->messenger()->addStatus($this->t('Thanks! Your interests are saved — we will use them to tailor your Slack channels and weekly email.'));
  }

}
