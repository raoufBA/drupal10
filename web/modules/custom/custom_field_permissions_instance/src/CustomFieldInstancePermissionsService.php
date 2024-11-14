<?php

namespace Drupal\custom_field_permissions_instance;

use Drupal\field_permissions\FieldPermissionsService;
use Drupal\field_permissions\Plugin\FieldPermissionTypeInterface;
use Drupal\field_permissions\Plugin\CustomPermissionsInterface;

/**
 * The field permission service for group roles and permissions.
 */
class CustomFieldInstancePermissionsService extends FieldPermissionsService {

  /**
   * {@inheritdoc}
   */
  public function getInstancePermissionsByRole() {
    /** @var \Drupal\group\Entity\GroupRoleInterface[] $roles */
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    $field_field_permissions = [];
    $field_permission_perm = $this->getAllInstancePermissions();
    foreach ($roles as $role_name => $role) {
      $role_permissions = $role->getPermissions();
      $field_field_permissions[$role_name] = [];
      foreach ($role_permissions as $key => $role_permission) {
        if (in_array($role_permission, array_keys($field_permission_perm))) {
          $field_field_permissions[$role_name][] = $role_permission;
        }
      }
    }
    return $field_field_permissions;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllInstancePermissions() {
    $permissions = [];
    /** @var \Drupal\field\FieldStorageConfigInterface[] $fields */
    $fields = $this->entityTypeManager->getStorage('field_storage_config')->loadMultiple();
    foreach ($fields as $key => $field) {
      // Check if this plugin defines custom permissions.
      $permission_type = $this->fieldGetPermissionType($field);
      if ($permission_type !== FieldPermissionTypeInterface::ACCESS_PUBLIC) {
        $plugin = $this->permissionTypeManager->createInstance($permission_type, [], $field);
        if ($plugin instanceof CustomPermissionsInterface) {
          $permissions += $plugin->getPermissions();
        }
      }
    }
    return $permissions;
  }

}
