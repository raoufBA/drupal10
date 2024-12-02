<?php

namespace Drupal\custom_field_permissions_instance;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\custom_field_permissions_instance\Plugin\CustomPermissionsInterface;
use Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType\Base;
use Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType\Manager;
use Drupal\custom_field_permissions_instance\Plugin\FieldPermissionTypeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The field permission service.
 */
class CustomFieldPermissionsService implements CustomFieldPermissionsServiceInterface, ContainerInjectionInterface {

  // File to get all instances.
  const HOSTS_FILE = DRUPAL_ROOT . '/../config/hosts.yaml';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The permission type plugin manager.
   *
   * @var \Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType\Manager
   */
  protected $permissionTypeManager;

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

  /**
   * Construct the field permission service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType\Manager $permission_type_manager
   *   The permission type plugin manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Manager $permission_type_manager, Yaml $yamlService, CacheBackendInterface $cacheBackend, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->permissionTypeManager = $permission_type_manager;
    $this->yamlService = $yamlService;
    $this->cacheBackend = $cacheBackend;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('plugin.custom_field_permissions_instance.types.manager'),
      $container->get('custom_field_permissions_instance.yaml_service'),
      $container->get('cache.default'),
      $container->get( 'logger.channel.custom_field_permissions_instance'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getList($field_label = '') {
    return [
      'create' => [
        'label' => t('Create field'),
        'title' => t('Create own value for field @field', ['@field' => $field_label]),
      ],
      'edit own' => [
        'label' => t('Edit own field'),
        'title' => t('Edit own value for field @field', ['@field' => $field_label]),
      ],
      'edit' => [
        'label' => t('Edit field'),
        'title' => t("Edit anyone's value for field @field", ['@field' => $field_label]),
      ],
      'view own' => [
        'label' => t('View own field'),
        'title' => t('View own value for field @field', ['@field' => $field_label]),
      ],
      'view' => [
        'label' => t('View field'),
        'title' => t("View anyone's value for field @field", ['@field' => $field_label]),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getPermissionsByRole() {
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    $field_field_permissions = [];
    $field_permission_perm = $this->getAllPermissions();
    foreach ($roles as $role_name => $role) {
      $role_permissions = $role->getPermissions();
      $field_field_permissions[$role_name] = [];
      // For all element set admin permission.
      if ($role->isAdmin()) {
        foreach (array_keys($field_permission_perm) as $perm_name) {
          $field_field_permissions[$role_name][] = $perm_name;
        }
      }
      else {
        foreach ($role_permissions as $key => $role_permission) {
          if (in_array($role_permission, array_keys($field_permission_perm))) {
            $field_field_permissions[$role_name][] = $role_permission;
          }
        }
      }
    }
    return $field_field_permissions;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllPermissions() {
    $permissions = [];

    return $permissions;
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
   * {@inheritdoc}
   * @param FieldStorageDefinitionInterface $field
   * @param string $current_bundle
   */
  public function fieldGetPermissionType(FieldStorageDefinitionInterface $field, string $current_bundle) {
    if (method_exists($field, 'getThirdPartySetting')) {
      $thirdPartySetting = $field->getThirdPartySetting('custom_field_permissions_instance', 'bundles_types_permissions', []);

      return $thirdPartySetting[$current_bundle] ?? FieldPermissionTypeInterface::ACCESS_PUBLIC;
    }
    return FieldPermissionTypeInterface::ACCESS_PUBLIC;
  }

  /**
   * {@inheritdoc}
   */
  public static function isCommentField(FieldDefinitionInterface $field_definition) {
    if (!\Drupal::hasService('comment.manager')) {
      // Comment module isn't enabled.
      return FALSE;
    }
    $field_name = $field_definition->getName();
    $field_names = \Drupal::service('comment.manager')->getFields($field_definition->getTargetEntityTypeId());
    // Comment field.
    if (in_array($field_name, array_keys($field_names))) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldAccess($operation, FieldItemListInterface $items, AccountInterface $account, FieldDefinitionInterface $field_definition) {
    $permission_type = $this->fieldGetPermissionType($field_definition->getFieldStorageDefinition());
    if (in_array('administrator', $account->getRoles()) || $permission_type == FieldPermissionTypeInterface::ACCESS_PUBLIC) {
      return TRUE;
    }
    // Field add to comment entity.
    if (static::isCommentField($field_definition)) {
      return TRUE;
    }

    // Pass access control to the plugin.
    $plugin = $this->permissionTypeManager->createInstance($permission_type, [], $field_definition->getFieldStorageDefinition());
    return $plugin->appliesToField($field_definition) && $plugin->hasFieldAccess($operation, $items->getEntity(), $account);
  }

  /**
   * Determines if the given account may view the field, regardless of entity.
   *
   * This should only return TRUE if:
   * @code
   * $this->getFieldAccess('view', $items, $account, $field_definition);
   * @endcode
   * returns TRUE for all possible values of $items.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Account for which to check access.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field for which to check access.
   *
   * @return bool
   *   The access result.
   *
   * @todo Move this to an interface: either FieldPermissionsServiceInterface
   *   or a new one.
   */
  public function hasFieldViewAccessForEveryEntity(AccountInterface $account, FieldDefinitionInterface $field_definition) {
    $permission_type = $this->fieldGetPermissionType($field_definition->getFieldStorageDefinition());
    if (in_array('administrator', $account->getRoles()) || $permission_type == FieldPermissionTypeInterface::ACCESS_PUBLIC) {
      return TRUE;
    }
    // Field add to comment entity.
    if (static::isCommentField($field_definition)) {
      return TRUE;
    }

    // Pass access control to the plugin.
    $plugin = $this->permissionTypeManager->createInstance($permission_type, [], $field_definition->getFieldStorageDefinition());
    if ($plugin instanceof Base) {
      return $plugin->appliesToField($field_definition) && $plugin->hasFieldViewAccessForEveryEntity($account);
    }
    else {
      return FALSE;
    }
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
