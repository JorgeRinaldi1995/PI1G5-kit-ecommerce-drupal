<?php

declare(strict_types=1);

namespace Drupal\product_kit\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\product_kit\Field\LineItemList;
use Drupal\product_kit\Form\ProductDeleteForm;
use Drupal\product_kit\Form\ProductForm;
use Drupal\product_kit\ProductKitAccessControlHandler;
use Drupal\product_kit\ProductListBuilder;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Defines the Product content entity.
 */
#[ContentEntityType(
  id: 'product',
  label: new TranslatableMarkup('Product'),
  label_collection: new TranslatableMarkup('Products'),
  label_singular: new TranslatableMarkup('product'),
  label_plural: new TranslatableMarkup('products'),
  label_count: [
    'singular' => '@count product',
    'plural' => '@count products',
  ],
  handlers: [
    'view_builder' => EntityViewBuilder::class,
    'list_builder' => ProductListBuilder::class,
    'views_data' => EntityViewsData::class,
    'access' => ProductKitAccessControlHandler::class,
    'form' => [
      'default' => ProductForm::class,
      'add' => ProductForm::class,
      'edit' => ProductForm::class,
      'delete' => ProductDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  base_table: 'product',
  admin_permission: 'administer product kit',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'published' => 'status',
    'owner' => 'uid',
    'langcode' => 'langcode',
  ],
  links: [
    'canonical' => '/admin/content/product/{product}',
    'add-form' => '/admin/content/product/add',
    'edit-form' => '/admin/content/product/{product}/edit',
    'delete-form' => '/admin/content/product/{product}/delete',
    'collection' => '/admin/content/product',
  ],
  field_ui_base_route: 'entity.product.settings',
)]
class Product extends ContentEntityBase implements ProductInterface {

  use EntityChangedTrait;
  use EntityPublishedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function getPrice(): int {
    return (int) $this->get('price')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getStock(): int {
    return max(0, (int) $this->get('stock')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function canBeSold(): bool {
    return $this->isPublished() && $this->getStock() > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getLines(): array {
    return $this->isNew()
      ? []
      : \Drupal::service('product_kit.composition_manager')->loadLines('product', $this->id());
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::publishedBaseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('ID'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(new TranslatableMarkup('UUID'))
      ->setReadOnly(TRUE);

    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(new TranslatableMarkup('Language'));

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -5])
      ->setDisplayOptions('view', ['label' => 'hidden', 'type' => 'string', 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status']
      ->setLabel(new TranslatableMarkup('Enabled'))
      ->setDescription(new TranslatableMarkup('Disabled products are hidden from catalogues. They still count toward kit contents, price and stock.'))
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 10])
      ->setDisplayConfigurable('form', TRUE);

    $fields['price'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Price'))
      ->setDescription(new TranslatableMarkup('Unit price, e.g. 10.50. Stored in minor currency units (cents) as a 64-bit integer.'))
      ->setRequired(TRUE)
      ->setDefaultValue(0)
      ->setSetting('size', 'big')
      ->setSetting('min', 0)
      ->setDisplayOptions('form', ['type' => 'product_kit_price', 'weight' => 0])
      ->setDisplayOptions('view', ['type' => 'product_kit_price', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['stock'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Stock quantity'))
      ->setDescription(new TranslatableMarkup('Units currently held in inventory. Kit stock is derived from this. (Not called "quantity": that name belongs to the per-kit quantity on kit lines.)'))
      ->setDefaultValue(0)
      ->setSetting('min', 0)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 1])
      ->setDisplayOptions('view', ['type' => 'number_integer', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 2])
      ->setDisplayOptions('view', ['type' => 'text_default', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['image'] = BaseFieldDefinition::create('image')
      ->setLabel(new TranslatableMarkup('Image'))
      ->setSettings([
        'file_directory' => 'products/[date:custom:Y]-[date:custom:m]',
        'file_extensions' => 'png jpg jpeg gif webp',
        'alt_field' => TRUE,
        'alt_field_required' => FALSE,
      ])
      ->setDisplayOptions('form', ['type' => 'image_image', 'weight' => 3])
      ->setDisplayOptions('view', ['type' => 'image', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['items'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Kits'))
      ->setDescription(new TranslatableMarkup('The kit_product lines that place this product in kits. A product belongs to at least one kit.'))
      ->setSetting('target_type', 'kit_product')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setComputed(TRUE)
      ->setClass(LineItemList::class)
      ->setDisplayOptions('view', ['type' => 'entity_reference_label', 'weight' => 5])
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid']
      ->setLabel(new TranslatableMarkup('Author'))
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 20])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
