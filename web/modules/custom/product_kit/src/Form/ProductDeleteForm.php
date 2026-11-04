<?php

declare(strict_types=1);

namespace Drupal\product_kit\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\product_kit\KitCompositionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delete form for products.
 *
 * Deleting a product takes it out of its kits (their price and stock follow
 * automatically). A kit that contains nothing else would be left without a
 * product, which is not allowed: the form lists those kits and asks whether to
 * delete them along with the product.
 */
class ProductDeleteForm extends ContentEntityDeleteForm {

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

    /** @var \Drupal\product_kit\Entity\ProductInterface $product */
    $product = $this->entity;
    if ($emptied = $this->composition->getEmptiedKits($product)) {
      $form['emptied'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('This is the only product in these kits:'),
        '#items' => array_map(static fn (EntityInterface $k): string => (string) $k->label(), $emptied),
        '#weight' => -5,
      ];
      $form['delete_kits'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Also delete these kits'),
        '#description' => $this->t('Every kit needs at least one product, so the product cannot be deleted unless these kits are deleted with it (or first given another product).'),
        '#weight' => -4,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if (isset($form['delete_kits']) && !$form_state->getValue('delete_kits')) {
      $form_state->setError($form['delete_kits'], $this->t('Tick "Also delete these kits", or add another product to them first.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\product_kit\Entity\ProductInterface $product */
    $product = $this->entity;
    $label = $product->label();

    $kits = $this->composition->deleteProduct($product, (bool) $form_state->getValue('delete_kits'));

    $this->messenger()->addStatus($this->t('The product %label has been deleted.', ['%label' => $label]));
    if ($kits) {
      $this->messenger()->addStatus($this->formatPlural($kits, 'One kit that contained only this product was deleted too.', '@count kits that contained only this product were deleted too.'));
    }
    $this->logDeletionMessage();
    $form_state->setRedirectUrl($this->getRedirectUrl());
  }

}
