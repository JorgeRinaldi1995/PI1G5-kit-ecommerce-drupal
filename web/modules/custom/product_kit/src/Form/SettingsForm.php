<?php

declare(strict_types=1);

namespace Drupal\product_kit\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Placeholder settings form so Field UI has a base route to attach tabs to.
 */
class SettingsForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'product_kit_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = [
      '#markup' => $this->t('Products, kits and kit lines are fieldable entities. Enable the core <em>Field UI</em> module to add extra fields, and to manage form and display settings, from the tabs on this page.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
