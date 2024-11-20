<?php

namespace Drupal\custom_field_permissions_instance;

use Drupal;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field_permissions\FieldPermissionsService;
use Drupal\field_permissions\Plugin\CustomPermissionsInterface;
use Drupal\field_permissions\Plugin\FieldPermissionType\Manager;
use Drupal\field_permissions\Plugin\FieldPermissionTypeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The field permission service for instance roles and permissions.
 */
class FieldInstancePermissionsService extends FieldPermissionsService {

  /**
   * @var \Symfony\Component\Yaml\Yaml
   */
  private $yamlService;

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private $cacheBackend;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, Manager $permission_type_manager, Yaml $yamlService, CacheBackendInterface $cacheBackend, LoggerInterface $logger) {
    parent::__construct($entity_type_manager, $permission_type_manager);
    $this->yamlService = $yamlService;
    $this->cacheBackend = $cacheBackend;
    $this->logger = $logger;
  }

  // File to get all instances.
  const HOSTS_FILE = DRUPAL_ROOT . '/../config/hosts.yaml';

  /**
   * @return array<mixed>
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getAllInstancePermissions() :array {
    $permissions = [];
    /** @var \Drupal\field\FieldStorageConfigInterface[] $fields */
    $fields = $this->entityTypeManager->getStorage('field_storage_config')
                                      ->loadMultiple();
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
   * @return array<mixed>
   */
  public function getPrdInstances(): array {
    // Access the cache service
    // Define a unique cache key to store and retrieve this data
    $cache_key = 'custom_field_permissions_instance';
    // Retrieve the cached data using the same cache key
    if ($cached_data = $this->cacheBackend->get($cache_key)) {
      // Cache data was found; retrieve it from the 'data' property
      /* @phpstan-ignore-next-line */
      return $cached_data->data;
    }

    $instances = [];

    if (file_exists(self::HOSTS_FILE)) {
      $donnees = $this->yamlService::parseFile(self::HOSTS_FILE);

      // Accéder aux données sous la clé 'prd'
      if (isset($donnees['prd']) && is_array($donnees['prd'])) {
        foreach ($donnees['prd'] as $key => $valeur) {
          $instances[] = $key;
        }
        if ($instances) {
          sort($instances);
          // Store the data in the cache
          $this->cacheBackend->set($cache_key, $instances, Cache::PERMANENT);
        }
      }
      else {
        $this->logger->error('La clé "prd" est introuvable ou n\'est pas un tableau dans hosts.yaml.');
      }
    }
    else {
      $this->logger->error('Le fichier YAML n\'a pas été trouvé au chemin : @chemin', ['@chemin' => self::HOSTS_FILE]);
    }

    return $instances;
  }

}
