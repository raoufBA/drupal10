<?php

namespace Drupal\custom_field_permissions_instance;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Implement FieldPermission Interface.
 */
interface CustomFieldPermissionsServiceInterface {

  /**
   * Obtain the list of field permissions.
   *
   * @param string $field_label
   *   The human readable name of the field to use when constructing permission
   *   names. Usually this will be derived from one or more of the field
   *   instance labels.
   *
   * @return array
   *   An array keyed by the permission machine name, with label and description
   *   keys. Note that this machine name doesn't include the field name.
   *
   * @todo This is really only relevant to the custom field permission type
   *   plugin. However, since it is used in the report page, it would be
   *   difficult to abstract down to the plugin level the way the report
   *   currently works.
   */
  public static function getList($field_label = '');

  /**
   * Field is attached to comment entity.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Fields to get permissions.
   *
   * @return bool
   *   TRUE if in a comment entity.
   */
  public static function isCommentField(FieldDefinitionInterface $field_definition);

  /**
   * Get all field permissions keyed by role ID.
   *
   * @return array
   *   An array of permission information keyed by role ID.
   */
  public function getPermissionsByRole();

  /**
   * Returns permissions for all fields that are using custom permissions.
   *
   * This is suitable for use as a permission callback.
   *
   * @return array
   *   An array of all permissions for all fields that implement the custom
   *   permission type.
   */
  public function getAllPermissions();

  /**
   * Get the permission type for a given field.
   *
   * @param FieldStorageDefinitionInterface $field
   *   The field to return permissions for.
   * @param string $current_bundle
   *   The current Bundle.
   */
  public function fieldGetPermissionType(FieldStorageDefinitionInterface $field, string $current_bundle);

  /**
   * Get access for field by operations and account permissions.
   *
   * @param string $operation
   *   String operation on field.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The entity field object on which to check access.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Account to get permissions.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Fields to get permissions.
   */
  public function getFieldAccess($operation, FieldItemListInterface $items, AccountInterface $account, FieldDefinitionInterface $field_definition);

}
