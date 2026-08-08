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
 *
 * This form also carries "How did you discover MakeHaven?"
 * (field_member_discovery). That question used to sit on the join profile form;
 * the 2026-07-24 funnel shrink deferred it along with the other
 * community/marketing fields, and capture went from ~85-90% to 0/10 — deferred
 * fields only reappear on /profile/N/edit once the member has a door badge, a
 * page nobody visits unprompted (staff feedback, Kate 2026-08-04). This page is
 * the one deferred-field capture point with a proven 100% completion rate, so
 * the question lives here rather than back on the join form. It is asked only
 * when the field is still empty, so campaign links that prepopulate it via the
 * EPP `?discovery=` token do not re-ask.
 */
class InterestPickerForm extends FormBase {

  /**
   * Member-facing labels for field_member_discovery, in the order asked.
   *
   * The stored *values* are unchanged, so views.view.discovery_report and every
   * historical record keep working — only the wording shown to a member is
   * friendlier than the staff-facing option labels on the field storage. Values
   * present on the field but missing here still render, using their field
   * label, so adding an option in the UI never silently drops it.
   */
  protected function discoveryLabels(): array {
    return [
      'member' => $this->t('A MakeHaven member referred me'),
      'general' => $this->t('Word of mouth'),
      'event' => $this->t('I came to a workshop or event at MakeHaven'),
      'search' => $this->t('I was looking for a makerspace'),
      'social' => $this->t('Social media'),
      'storefront' => $this->t('I saw the storefront'),
      'print' => $this->t('A community poster or flyer'),
      'table' => $this->t('MakeHaven had a table at a community event'),
      'news' => $this->t('A news story'),
      'ads' => $this->t('An online ad'),
      'organization' => $this->t('Another organization referred me'),
      'other' => $this->t('Something else'),
    ];
  }

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

    // Full Area-of-Interest hierarchy (categories + their subcategories), in
    // tree order. Children are dash-prefixed by depth (Drupal's native taxonomy
    // convention) so the theme's global interest-hierarchy.js treats them as
    // subcategories — making the top-level categories non-selectable headers
    // and rolling a child selection up to its parent. Same behaviour as the old
    // profile-form widget.
    $options = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadTree('area_of_interest') as $term) {
      $options[$term->tid] = str_repeat('-', (int) $term->depth) . $term->name;
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
    // Say where to change these later. Kate (2026-08-04) asked for this: the
    // copy promised "you can change it any time" without saying where. A brand
    // new member may have an unsaved profile, so only link when there is one.
    $profile_url = ($profile && !$profile->isNew()) ? $profile->toUrl('edit-form')->toString() : NULL;
    $intro = $profile_url
      ? $this->t("Pick the areas you're interested in. We use this to add you to the right Slack channels and tailor your weekly email. You can change these any time on <a href=\":url\">your member profile</a>.", [':url' => $profile_url])
      : $this->t("Pick the areas you're interested in. We use this to add you to the right Slack channels and tailor your weekly email. You can change these any time from your member profile.");
    $form['intro'] = [
      '#markup' => '<p class="mh-interest-picker__intro">' . $intro . '</p>',
    ];
    $form['interests'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Your areas of interest'),
      '#title_display' => 'invisible',
      '#options' => $options,
      '#default_value' => $default,
      // The class the theme's interest-hierarchy.js keys off (it loads globally),
      // so the parent/child rollup + non-selectable top level apply here.
      '#prefix' => '<div class="field--name-field-member-areas-interest">',
      '#suffix' => '</div>',
    ];

    $this->buildDiscovery($form, $profile);

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      // The button names what it saves, so it stays honest once the discovery
      // question is on the form too.
      '#value' => isset($form['discovery']) ? $this->t('Save and continue') : $this->t('Save my interests'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Adds "How did you discover MakeHaven?" when we have not captured it yet.
   *
   * Deliberately optional: this page's primary job is interests (100% capture),
   * and a required question here risks members abandoning the form and losing
   * both answers. If capture turns out low we can make it required — that is a
   * one-line change.
   */
  protected function buildDiscovery(array &$form, ?object $profile): void {
    if (!$profile || !$profile->hasField('field_member_discovery')) {
      return;
    }
    // Already answered (including via an EPP-prepopulated campaign link) — do
    // not ask twice.
    if (!$profile->get('field_member_discovery')->isEmpty()) {
      return;
    }
    $options = $this->discoveryOptions($profile);
    if (!$options) {
      return;
    }

    $form['discovery'] = [
      '#type' => 'radios',
      '#title' => $this->t('How did you discover MakeHaven?'),
      '#options' => $options,
      // No '#attributes' here on purpose: Drupal copies a radios element's
      // attributes onto every child <input> as well as the wrapper, so a layout
      // class added here lands on all 12 inputs. The CSS keys off the prefix
      // class below and Barrio's own fieldset structure instead.
      '#prefix' => '<div class="mh-interest-picker__discovery">',
      '#suffix' => '</div>',
    ];
    // Follow-ups for the two answers where the detail is the point: who to
    // thank for a referral, and which event earned the signup.
    if ($profile->hasField('field_member_referring')) {
      $form['discovery_referring'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Who referred you?'),
        '#description' => $this->t('Their full name, so we can thank them.'),
        '#maxlength' => 255,
        '#states' => ['visible' => [':input[name="discovery"]' => ['value' => 'member']]],
        '#prefix' => '<div class="mh-interest-picker__discovery-detail">',
        '#suffix' => '</div>',
      ];
    }
    if ($profile->hasField('field_member_discovery_event_det')) {
      $form['discovery_event'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Which workshop or event?'),
        '#maxlength' => 255,
        '#states' => ['visible' => [':input[name="discovery"]' => ['value' => 'event']]],
        '#prefix' => '<div class="mh-interest-picker__discovery-detail">',
        '#suffix' => '</div>',
      ];
    }
  }

  /**
   * Discovery options: the field's own allowed values, member-facing wording.
   */
  protected function discoveryOptions(object $profile): array {
    $allowed = $profile->get('field_member_discovery')
      ->getFieldDefinition()
      ->getFieldStorageDefinition()
      ->getSetting('allowed_values') ?: [];

    // Drupal has carried allowed_values both as value => label and as a list of
    // ['value' => .., 'label' => ..] maps; normalise either shape.
    $field_labels = [];
    foreach ($allowed as $key => $item) {
      if (is_array($item)) {
        if (isset($item['value'])) {
          $field_labels[$item['value']] = $item['label'] ?? $item['value'];
        }
      }
      else {
        $field_labels[$key] = $item;
      }
    }

    $options = [];
    foreach ($this->discoveryLabels() as $value => $label) {
      if (isset($field_labels[$value])) {
        $options[$value] = $label;
      }
    }
    // Anything on the field but not in our map still gets asked, so an option
    // added in the field UI is never silently dropped from the question.
    foreach ($field_labels as $value => $label) {
      if (!isset($options[$value])) {
        $options[$value] = $label;
      }
    }
    return $options;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $profile = $this->memberProfile();
    if (!$profile) {
      $this->messenger()->addError($this->t('Sorry — we could not save your interests. Please try again.'));
      return;
    }
    $selected = array_values(array_filter((array) $form_state->getValue('interests')));
    $profile->set('field_member_areas_interest', $selected);

    // Discovery is only rendered when the field was empty, so a value here is
    // always a first answer — we never overwrite an existing one.
    $discovery = $form_state->getValue('discovery');
    if ($discovery && $profile->hasField('field_member_discovery')) {
      $profile->set('field_member_discovery', [$discovery]);
      if ($discovery === 'member' && $profile->hasField('field_member_referring')) {
        $referring = trim((string) $form_state->getValue('discovery_referring'));
        if ($referring !== '') {
          $profile->set('field_member_referring', $referring);
        }
      }
      if ($discovery === 'event' && $profile->hasField('field_member_discovery_event_det')) {
        $event = trim((string) $form_state->getValue('discovery_event'));
        if ($event !== '') {
          $profile->set('field_member_discovery_event_det', $event);
        }
      }
    }

    $profile->save();
    $this->messenger()->addStatus($this->t('Thanks! Your interests are saved — we will use them to tailor your Slack channels and weekly email.'));
  }

}
