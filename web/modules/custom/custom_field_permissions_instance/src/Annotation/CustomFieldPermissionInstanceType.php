<?php

namespace Drupal\custom_field_permissions_instance\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a custom field permission instance type plugin.
 *
 * @Annotation
 */
class CustomFieldPermissionInstanceType extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The human-readable title.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $title;

  /**
   * The permission type description.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $description;

  /**
   * The weight for ordering the plugins on the field settings page.
   *
   * @var int
   */
  public $weight;

}
