<?php

declare(strict_types=1);

namespace Drupal\product_kit\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Add/edit form for products, with inline management of the product's kits.
 *
 * Placing the product in a kit is optional: pick existing kits, and/or name a
 * new kit to create around this product (which is also how the very first
 * product of a catalogue can get its own kit in one step). A product with no
 * kit saves fine; it just does not show up in any bundle yet. What is not
 * allowed is removing the product from a kit that has no other product — a
 * kit always needs at least one. The product and its lines are saved together
 * in one transaction.
 */
class ProductForm extends CompositionFormBase {

  /**
   * {@inheritdoc}
   */
  protected function targetType(): string {
    return 'kit';
  }

  /**
   * {@inheritdoc}
   */
  protected function initialRows(): array {
    /** @var \Drupal\product_kit\Entity\ProductInterface $product */
    $product = $this->entity;

    $rows = [];
    foreach ($product->getLines() as $line) {
      $rows[] = [
        'target' => (int) $line->get('kit')->target_id ?: NULL,
        'quantity' => $line->getQuantity(),
      ];
    }
    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  protected function rowHint(EntityInterface $target): ?string {
    /** @var \Drupal\product_kit\Entity\KitInterface $target */
    return (string) $this->t('Kit price @price · kit stock @stock', [
      '@price' => $this->priceFormatter->format($target->getPrice()),
      '@stock' => $target->getStock(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['lines'] = $this->buildRows([
      '#title' => $this->t('Kits containing this product'),
      '#description' => $this->t('Optional. The quantity is how many units of this product one kit includes; it cannot exceed the stock above (one unit is always allowed).'),
      '#weight' => 6,
    ], $form_state, (string) $this->t('Add to another kit'));

    $form['lines']['new_kit_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Or create a new kit with this product'),
      '#description' => $this->t('Name of a new, enabled kit that will contain one unit of this product. Add more products to it later from the kit form.'),
      '#maxlength' => 255,
      '#weight' => 10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Judge the quantities against the stock being submitted, not the stored one.
    /** @var \Drupal\product_kit\Entity\ProductInterface $product */
    $product = $this->buildEntity($form, $form_state);

    $prepared = $this->composition->prepareProductRows($product, $this->readRowsFromValues($form_state));
    $this->reportRowErrors($prepared['errors'], $form, $form_state);

    // Leaving a kit must not empty it: a kit always needs at least one product.
    if (!$this->entity->isNew()) {
      $emptied = $this->composition->getEmptiedKits($this->entity, array_keys($prepared['rows']));
      if ($emptied) {
        $form_state->setError($form['lines'], $this->t('This product is the only one in these kits, and every kit needs at least one product: @kits. Keep the product in them, or add another product to them first.', [
          '@kits' => implode(', ', array_map(static fn (EntityInterface $k): string => (string) $k->label(), $emptied)),
        ]));
      }
    }

    $form_state->set('composition_prepared', $prepared['rows']);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\product_kit\Entity\ProductInterface $product */
    $product = $this->entity;
    $rows = $form_state->get('composition_prepared');
    $new_kit_name = trim((string) $form_state->getValue(['lines', 'new_kit_name']));

    $new_kit = NULL;
    $result = $this->composition->transactional(function () use ($form, $form_state, $product, &$rows, $new_kit_name, &$new_kit): int {
      $result = parent::save($form, $form_state);

      if ($new_kit_name !== '') {
        $new_kit = $this->entityTypeManager->getStorage('kit')->create([
          'name' => $new_kit_name,
          'status' => TRUE,
          'uid' => $this->currentUser()->id(),
        ]);
        $new_kit->save();
        $rows[(int) $new_kit->id()] = ['target' => (int) $new_kit->id(), 'quantity' => 1, 'index' => -1, 'merged' => FALSE];
      }

      $this->composition->syncProductLines($product, $rows);
      return $result;
    });

    $this->reportMergedRows($rows);
    $this->messenger()->addStatus($rows
      ? $this->t('The product %label has been saved in @count kit(s).', [
        '%label' => $product->label(),
        '@count' => count($rows),
      ])
      : $this->t('The product %label has been saved. It is not in any kit yet.', ['%label' => $product->label()]));
    if ($new_kit) {
      $this->messenger()->addStatus($this->t('The kit %label was created.', ['%label' => $new_kit->label()]));
    }

    $form_state->setRedirectUrl($product->toUrl('collection'));

    return $result;
  }

}
