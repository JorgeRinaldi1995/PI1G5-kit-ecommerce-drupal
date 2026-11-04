<?php

declare(strict_types=1);

namespace Drupal\product_kit\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Add/edit form for a single kit line (kit_product).
 *
 * The kit and product forms are the primary way to manage lines; this form is
 * for adding one product to a kit directly (".../add?kit=ID&product=ID"
 * pre-selects them) and for changing a line's quantity. A saved line's kit and
 * product cannot be changed: that would silently remove the product from one
 * kit and add it to another, which could leave either without its minimum.
 */
class KitProductForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    if ($this->entity->isNew()) {
      foreach (['kit', 'product'] as $type) {
        $id = $this->getRequest()->query->get($type);
        if ($id && ($target = $this->entityTypeManager->getStorage($type)->load($id))) {
          $this->entity->set($type, $target->id());
          if (isset($form[$type]['widget'][0]['target_id'])) {
            $form[$type]['widget'][0]['target_id']['#default_value'] = $target;
          }
        }
      }
    }
    else {
      foreach (['kit', 'product'] as $type) {
        $form[$type]['#disabled'] = TRUE;
      }
      $form['moved_note'] = [
        '#markup' => '<p>' . $this->t('Only the quantity can be changed. A saved line cannot be moved to another kit or product: delete it and add a new one instead.') . '</p>',
        '#weight' => -10,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $this->messenger()->addStatus($this->t('The kit line has been saved.'));
    /** @var \Drupal\product_kit\Entity\KitProductInterface $line */
    $line = $this->entity;
    $form_state->setRedirectUrl(($kit = $line->getKit()) ? $kit->toUrl() : $line->toUrl('collection'));

    return $result;
  }

}
