<?php

namespace Drupal\interests_civi_bridge\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\interests_civi_bridge\Form\InterestPickerForm;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Areas-of-Interest picker for the onboarding thank-you page.
 *
 * @Block(
 *   id = "interests_civi_bridge_picker",
 *   admin_label = @Translation("Areas of Interest picker"),
 *   category = @Translation("MakeHaven"),
 * )
 */
class InterestPickerBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected FormBuilderInterface $formBuilder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('form_builder'));
  }

  public function build() {
    return $this->formBuilder->getForm(InterestPickerForm::class);
  }

  /**
   * The picker reflects the current member's saved interests.
   */
  public function getCacheContexts() {
    return array_merge(parent::getCacheContexts(), ['user']);
  }

  public function getCacheMaxAge() {
    return 0;
  }

}
