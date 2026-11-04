<?php

declare(strict_types=1);

namespace Drupal\product_kit\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\product_kit\KitCompositionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delete form for a kit line (kit_product).
 *
 * Refuses to remove the last product of a kit — a kit always needs at least
 * one. To get rid of the whole kit, delete that instead: it removes its lines
 * without this restriction, since a product does not need to be in any kit.
 */
class KitProductDeleteForm extends ContentEntityDeleteForm {

  protected KitCompositionManager $composition;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->composition = $container->get('product_kit.composition_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    if ($blocker = $this->getBlocker()) {
      $form['description']['#access'] = FALSE;
      $form['product_kit_block'] = [
        '#markup' => '<p>' . $blocker . '</p>',
        '#weight' => -10,
      ];
      if (isset($form['actions']['submit'])) {
        $form['actions']['submit']['#access'] = FALSE;
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ($blocker = $this->getBlocker()) {
      $form_state->setErrorByName('', $blocker);
    }
  }

  /**
   * Explains why this line cannot be deleted, or NULL if it can.
   */
  protected function getBlocker(): ?string {
    /** @var \Drupal\product_kit\Entity\KitProductInterface $line */
    $line = $this->entity;
    return $this->composition->getLineDeletionBlocker($line);
  }

}
