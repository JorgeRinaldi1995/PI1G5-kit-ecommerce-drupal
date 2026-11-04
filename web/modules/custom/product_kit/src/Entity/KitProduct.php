<?php

declare(strict_types=1);

namespace Drupal\product_kit\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\product_kit\Form\KitProductDeleteForm;
use Drupal\product_kit\Form\KitProductForm;
use Drupal\product_kit\KitProductListBuilder;
use Drupal\product_kit\KitProductStorageSchema;
use Drupal\views\EntityViewsData;

/**
 * Defines the Kit line (kit_product) content entity.
 *
 * This is the associative entity that resolves the many-to-many relationship
 * between kits and products, and carries the per-kit quantity. A product
 * appears at most once per kit (enforced by a unique key on kit + product);
 * asking for several units of it is what the quantity is for.
 */
#[ContentEntityType(
  id: 'kit_product',
  label: new TranslatableMarkup('Kit line'),
  label_collection: new TranslatableMarkup('Kit lines'),
  label_singular: new TranslatableMarkup('kit line'),
  label_plural: new TranslatableMarkup('kit lines'),
  label_count: [
    'singular' => '@count kit line',
    'plural' => '@count kit lines',
  ],
  handlers: [
    'view_builder' => EntityViewBuilder::class,
    'list_builder' => KitProductListBuilder::class,
    'views_data' => EntityViewsData::class,
    'access' => EntityAccessControlHandler::class,
    'form' => [
      'default' => KitProductForm::class,
      'add' => KitProductForm::class,
      'edit' => KitProductForm::class,
      'delete' => KitProductDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
    'storage_schema' => KitProductStorageSchema::class,
  ],
  base_table: 'kit_product',
  admin_permission: 'administer product kit',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
  ],
  links: [
    'canonical' => '/admin/content/kit-line/{kit_product}',
    'add-form' => '/admin/content/kit-line/add',
    'edit-form' => '/admin/content/kit-line/{kit_product}/edit',
    'delete-form' => '/admin/content/kit-line/{kit_product}/delete',
    'collection' => '/admin/content/kit-line',
  ],
  field_ui_base_route: 'entity.kit_product.settings',
)]
class KitProduct extends ContentEntityBase implements KitProductInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    $product = $this->getProduct();
    $kit = $this->getKit();
    return (string) new TranslatableMarkup('@qty x @product in @kit', [
      '@qty' => $this->getQuantity(),
      '@product' => $product ? $product->label() : new TranslatableMarkup('missing product'),
      '@kit' => $kit ? $kit->label() : new TranslatableMarkup('missing kit'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getKit(): ?KitInterface {
    $kit = $this->get('kit')->entity;
    return $kit instanceof KitInterface ? $kit : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getProduct(): ?ProductInterface {
    $product = $this->get('product')->entity;
    return $product instanceof ProductInterface ? $product : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuantity(): int {
    return (int) $this->get('quantity')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getLineTotal(): int {
    $product = $this->getProduct();
    return $product ? $this->getQuantity() * $product->getPrice() : 0;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('ID'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(new TranslatableMarkup('UUID'))
      ->setReadOnly(TRUE);

    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(new TranslatableMarkup('Language'));

    $fields['kit'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Kit'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'kit')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 0])
      ->setDisplayOptions('view', ['type' => 'entity_reference_label', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['product'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Product'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'product')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 1])
      ->setDisplayOptions('view', ['type' => 'entity_reference_label', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['quantity'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Quantity'))
      ->setDescription(new TranslatableMarkup('How many units of this product one kit includes. Cannot exceed the product\'s stock (a single unit is always allowed).'))
      ->setRequired(TRUE)
      ->setDefaultValue(1)
      ->setSetting('min', 1)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 2])
      ->setDisplayOptions('view', ['type' => 'number_integer', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
