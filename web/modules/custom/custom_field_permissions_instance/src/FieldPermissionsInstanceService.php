<?php

namespace Drupal\custom_field_permissions_instance;

use Drupal\Core\Cache\Cache;
use Drupal\field_permissions\FieldPermissionsService;
use Drupal\field_permissions\Plugin\FieldPermissionTypeInterface;
use Drupal\field_permissions\Plugin\CustomPermissionsInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The field permission service for group roles and permissions.
 */
class FieldPermissionsInstanceService extends FieldPermissionsService {

  // File to get all instances.
  const HOSTS_FILE = DRUPAL_ROOT.'/../config/hosts.yaml';
  private static $currentInstance;


  /**
   * {@inheritdoc}
   */
//  public static function getList($field_label = '') {
//    $instance = self::$currentInstance ? self::$currentInstance.' ' : '';
//
//    return [
//      $instance."create" => [
//        'label' => t($instance."Create field"),
//        'title' => t($instance."Create own value for field @field", ['@field' => $field_label]),
//      ],
//      $instance."edit own" => [
//        'label' => t($instance."Edit own field"),
//        'title' => t($instance."Edit own value for field @field", ['@field' => $field_label]),
//      ],
//      $instance."edit" => [
//        'label' => t($instance."Edit field"),
//        'title' => t($instance."Edit anyone's value for field @field", ['@field' => $field_label]),
//      ],
//      $instance."view own" => [
//        'label' => t($instance."View own field"),
//        'title' => t($instance."View own value for field @field", ['@field' => $field_label]),
//      ],
//      $instance." view" => [
//        'label' => t($instance."View field"),
//        'title' => t($instance."View anyone's value for field @field", ['@field' => $field_label]),
//      ],
//    ];
//  }



  /**
   * {@inheritdoc}
   */
  public function getGroupPermissionsByRole() {
    /** @var \Drupal\group\Entity\GroupRoleInterface[] $roles */
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    $field_field_permissions = [];
    $field_permission_perm = $this->getAllGroupPermissions();
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

    /**
     * @return array
     */
    public static function getPrdInstances(): array {
      // Access the cache service
      $cache = \Drupal::cache();
      // Define a unique cache key to store and retrieve this data
      $cache_key = 'custom_field_permissions_instance';

      // Retrieve the cached data using the same cache key
      if ($cached_data = $cache->get($cache_key)) {
        // Cache data was found; retrieve it from the 'data' property
        return $cached_data->data;
      }

      $instances = [];

      if (file_exists(self::HOSTS_FILE)) {
        $donnees = Yaml::parseFile(self::HOSTS_FILE);

        // Accéder aux données sous la clé 'prd'
        if (isset($donnees['prd']) && is_array($donnees['prd'])) {
          foreach ($donnees['prd'] as $key => $valeur) {
            $instances[] = $key;
          }
          if ($instances) {
            sort($instances);
            // Store the data in the cache
            $cache->set($cache_key, $instances, Cache::PERMANENT);
          }
        } else {
          \Drupal::logger('mon_module')->error('La clé "prd" est introuvable ou n\'est pas un tableau dans hosts.yaml.');
        }
      } else {
        \Drupal::logger('mon_module')->error(
          'Le fichier YAML n\'a pas été trouvé au chemin : @chemin', ['@chemin' => self::HOSTS_FILE]
        );
      }

      return $instances;
    }


  public function setCurrentInstance($currentInstance): void {
    self::$currentInstance = $currentInstance;
  }

}
