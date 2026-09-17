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

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
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

  /**
   * {@inheritdoc}
   */
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
      // The class used by the theme's global interest-hierarchy.js,
      // so the parent/child rollup + non-selectable top level apply here.
      '#prefix' => '<div class="field--name-field-member-areas-interest">',
      '#suffix' => '</div>',
    ];

    $this->buildDiscovery($form, $profile);
    $form_state->set('referral_capture', isset($form['discovery']['referral_detail']) || isset($form['referral_detail']));
    $form_state->set('referral_existing_member', $this->hasMemberDiscovery($profile));
    $form['#attached']['library'][] = 'interests_civi_bridge/referral_capture';

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      // The button names what it saves, so it stays honest once the discovery
      // question is on the form too.
      '#value' => isset($form['discovery']) || isset($form['referral_detail']) ? $this->t('Save and continue') : $this->t('Save my interests'),
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
    // Preserve campaign attribution, but still allow a missing referral name.
    if (!$profile->get('field_member_discovery')->isEmpty()) {
      if ($this->needsReferrer($profile)) {
        if (!$this->hasMemberDiscovery($profile)) {
          $form['referral_add'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('A MakeHaven member also referred me'),
          ];
        }
        $form['referral_detail'] = $this->referralElement();
        if (isset($form['referral_add'])) {
          $condition = [':input[name="referral_add"]' => ['checked' => TRUE]];
          $form['referral_detail']['name']['#states'] = ['visible' => $condition, 'required' => $condition];
        }
        else {
          $form['referral_detail']['name']['#required'] = TRUE;
          $form['referral_detail']['name']['#description'] = $this->t('You told us a member referred you. Add their full name so staff can review your referral.');
        }
      }
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
    if ($this->needsReferrer($profile) && isset($options['member'])) {
      // Put the name field directly after the member option, with or without
      // JavaScript. It cannot be done with a fractional weight: Radios assigns
      // its children .001, .002 and so on, and Element::children() sorts on
      // floor($weight * 1000), so .0015 and .001 land in the same bucket and
      // fall back to insertion order — which puts the field above every option,
      // measured 2026-09-16. Renumber the built children in whole steps instead
      // (see orderDiscoveryChildren), which survives that truncation.
      $form['discovery']['referral_detail'] = $this->referralElement();
      $form['discovery']['#after_build'][] = [static::class, 'orderDiscoveryChildren'];
      $condition = [':input[name="discovery"]' => ['value' => 'member']];
      $form['discovery']['referral_detail']['#states'] = ['visible' => $condition];
      $form['discovery']['referral_detail']['name']['#states'] = ['required' => $condition];
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
   * Whether a member referral was recorded among the discovery answers.
   */
  protected function hasMemberDiscovery(?object $profile): bool {
    return $profile && $profile->hasField('field_member_discovery')
      && in_array('member', array_column($profile->get('field_member_discovery')->getValue(), 'value'), TRUE);
  }

  /**
   * Never replace an existing referral answer through this capture form.
   */
  protected function needsReferrer(object $profile): bool {
    return $profile->hasField('field_member_referring')
      && trim((string) $profile->get('field_member_referring')->value) === '';
  }

  /**
   * Re-weights the built radios so the referral name follows its own option.
   *
   * Runs after Radios::processRadios has created the option children, in whole
   * weight steps because Element::children() truncates to three decimals. It
   * also drops #sorted, which FormBuilder sets before the children exist, so
   * the render layer sorts the final order rather than the insertion order.
   */
  public static function orderDiscoveryChildren(array $element, FormStateInterface $form_state): array {
    if (!isset($element['referral_detail'])) {
      return $element;
    }
    $weight = 0;
    foreach (array_keys($element['#options'] ?? []) as $key) {
      if (!isset($element[$key])) {
        continue;
      }
      $element[$key]['#weight'] = ++$weight;
      if ($key === 'member') {
        $element['referral_detail']['#weight'] = ++$weight;
      }
    }
    unset($element['#sorted']);
    return $element;
  }

  /**
   * Builds the same name field for first-time and returning members.
   */
  protected function referralElement(): array {
    // A picker rather than a box to type a name into. Typing was measured and
    // it does not work: of 544 stored answers, 23% arrived in a shape the
    // matcher could not suggest anything for — single words like "Michael" and
    // "regina", and "Lior!" — so somebody had to research each one, and 544
    // answers produced two decisions. Pointing at a face resolves it at the
    // moment the person actually knows the answer. 841 of 853 active members
    // have a photo.
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mh-interest-picker__referral-detail', 'mh-referral-picker'],
        'data-mh-referral-picker' => 'true',
      ],
      '#attached' => ['library' => ['makerspace_referrals/member_picker']],
      'uid' => [
        '#type' => 'hidden',
        '#parents' => ['discovery_referrer_uid'],
        '#default_value' => '',
        '#attributes' => ['data-mh-referral-uid' => 'true'],
      ],
      'name' => [
        '#type' => 'textfield',
        '#parents' => ['discovery_referring'],
        '#title' => $this->t('Who referred you?'),
        '#description' => $this->t('Start typing their name and pick them from the list.'),
        '#maxlength' => 255,
        '#attributes' => [
          'data-mh-referral-search' => 'true',
          'autocomplete' => 'off',
        ],
      ],
      'results' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mh-referral-picker__results'],
          'data-mh-referral-results' => 'true',
        ],
      ],
      'chosen' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mh-referral-picker__chosen'],
          'data-mh-referral-chosen' => 'true',
        ],
      ],
      // Without JavaScript the box is still a box, and the typed name still
      // goes to the queue exactly as it does today. Nobody is blocked.
      'fallback' => [
        '#markup' => '<p class="mh-referral-picker__fallback description">'
        . $this->t("Can't find them? Type their name anyway and staff will work it out.")
        . '</p>',
      ],
    ];
  }

  /**
   * Determines whether this submission claims a referral with a missing name.
   */
  protected function referralSelected(FormStateInterface $form_state): bool {
    return $form_state->get('referral_capture') && (
      $form_state->get('referral_existing_member')
      || $form_state->getValue('discovery') === 'member'
      || $form_state->getValue('referral_add')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // #states is browser-only; enforce the same rule for non-JS submissions
    // and whitespace answers before either interests or discovery are saved.
    if ($this->referralSelected($form_state) && trim((string) $form_state->getValue('discovery_referring')) === '') {
      $form_state->setErrorByName('discovery_referring', $this->t('Please enter the full name of the member who referred you.'));
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

  /**
   * {@inheritdoc}
   */
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
    if ($discovery && $profile->hasField('field_member_discovery') && $profile->get('field_member_discovery')->isEmpty()) {
      $profile->set('field_member_discovery', [$discovery]);
      if ($discovery === 'event' && $profile->hasField('field_member_discovery_event_det')) {
        $event = trim((string) $form_state->getValue('discovery_event'));
        if ($event !== '') {
          $profile->set('field_member_discovery_event_det', $event);
        }
      }
    }

    $referral_saved = FALSE;
    $referrer_uid = 0;
    if ($this->referralSelected($form_state) && $this->needsReferrer($profile)) {
      $referring = trim((string) $form_state->getValue('discovery_referring'));
      if ($referring !== '') {
        // The typed text is kept whatever happens. It is the person's own
        // answer and the provenance for anything resolved from it.
        $profile->set('field_member_referring', $referring);
        $referral_saved = TRUE;

        // What the browser posted is a claim, not evidence — re-check that the
        // uid really is an active member and is not the person themselves.
        $claimed = (int) $form_state->getValue('discovery_referrer_uid');
        if ($claimed > 0 && \Drupal::hasService('makerspace_referrals.member_finder')) {
          $finder = \Drupal::service('makerspace_referrals.member_finder');
          if ($finder->isSelectableMember($claimed, (int) $profile->getOwnerId())) {
            $referrer_uid = $claimed;
          }
        }
      }
    }
    $profile->save();

    if ($referrer_uid > 0) {
      // Picking somebody from the list IS the resolution (JR, 2026-09-17).
      // Recorded as a review decision rather than written straight to the
      // projection, so there is one audit trail and it says how this was
      // resolved: reviewer 0 means the member selected it themselves.
      try {
        $review = \Drupal::service('makerspace_referrals.review');
        $review->decide(
          (int) $profile->id(),
          hash('sha256', $review->source($profile)),
          0,
          'confirmed',
          $referrer_uid,
          0,
        );
        \Drupal::service('makerspace_referrals.projector')->project((int) $profile->id());
      }
      catch (\Throwable $e) {
        // A failure here must not cost the member their submission. The typed
        // name is already saved, so the referral simply falls back to the
        // staff queue exactly as it would have before.
        \Drupal::logger('interests_civi_bridge')->error('Could not auto-confirm a self-picked referrer for profile @p: @m', [
          '@p' => $profile->id(),
          '@m' => $e->getMessage(),
        ]);
        $referrer_uid = 0;
      }
    }

    if ($referrer_uid > 0) {
      $this->messenger()->addStatus($this->t('Thanks! We have recorded who introduced you, and they will hear from us.'));
    }
    elseif ($referral_saved) {
      $this->messenger()->addStatus($this->t('Thanks! Your referral has been saved for staff review. This does not yet confirm a membership credit.'));
    }

    if ($selected) {
      $this->messenger()->addStatus($this->t('Thanks! Your interests are saved — we will use them to tailor your Slack channels and weekly email.'));
      // Land the member on their personalized guide rather than back at the
      // top of the page (the reload-to-top made the save feel like nothing
      // happened — JR, 2026-08-10). The anchor is rendered by
      // _interests_civi_bridge_guide().
      $form_state->setRedirect(
        'entity.node.canonical',
        ['node' => INTERESTS_CIVI_BRIDGE_THANKYOU_NID],
        ['fragment' => 'your-guide'],
      );
    }
    else {
      $this->messenger()->addWarning($this->t('No interests picked yet — choose a few to unlock a guide built around them, or use the skip link below.'));
    }
  }

}
