<?php

declare(strict_types=1);

namespace Drupal\kit_catalog\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\kit_catalog\Form\KitGridForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Block that lists enabled Product Kit kits in a grid with an add-to-cart CTA.
 */
#[Block(
  id: 'kit_catalog_grid',
  admin_label: new TranslatableMarkup('Kits grid (Product Kit)'),
  category: new TranslatableMarkup('Product Kit'),
)]
class KitGridBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected readonly FormBuilderInterface $formBuilder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'limit' => 0,
      'columns' => 3,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of kits to show'),
      '#description' => $this->t('0 shows every enabled kit.'),
      '#min' => 0,
      '#default_value' => $config['limit'],
    ];

    $form['columns'] = [
      '#type' => 'select',
      '#title' => $this->t('Grid columns'),
      '#options' => [2 => 2, 3 => 3, 4 => 4],
      '#default_value' => $config['columns'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['limit'] = (int) $form_state->getValue('limit');
    $this->configuration['columns'] = (int) $form_state->getValue('columns');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->getConfiguration();
    return $this->formBuilder->getForm(KitGridForm::class, (int) $config['limit'], (int) $config['columns']);
  }

  /**
   * {@inheritdoc}
   *
   * The block embeds a form (CSRF-bound build ID) and reflects per-session
   * cart state, so it must never be render-cached.
   */
  public function getCacheMaxAge(): int {
    return 0;
  }

}
