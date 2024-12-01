<?php

namespace Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\custom_field_permissions_instance\FieldPermissionsServiceInterface;
use Drupal\custom_field_permissions_instance\Plugin\FieldPermissionTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * An abstract implementation of FieldPermissionTypeInterface.
 */
abstract class Base extends PluginBase implements FieldPermissionTypeInterface, ContainerFactoryPluginInterface {

  /**
   * The field storage.
   *
   * @var \Drupal\field\FieldStorageConfigInterface
   */
  protected $fieldStorage;

  /**
   * The fields permissions service.
   *
   * @var \Drupal\custom_field_permissions_instance\FieldPermissionsServiceInterface
   */
  protected $fieldPermissionsService;

  /**
   * Constructs the plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\field\FieldStorageConfigInterface $field_storage
   *   The field storage.
   * @param \Drupal\custom_field_permissions_instance\FieldPermissionsServiceInterface|null $field_permissions_service
   *   Field permissions service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FieldStorageConfigInterface $field_storage, FieldPermissionsServiceInterface $field_permissions_service = NULL) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->fieldStorage = $field_storage;
    if ($field_permissions_service === NULL) {
      @trigger_error('Calling ' . __METHOD__ . '() without the $field_permissions_service argument is deprecated in custom_field_permissions_instance:8.x-1.4 and will be required in custom_field_permissions_instance:8.x-2.0. See https://www.drupal.org/node/3359471', E_USER_DEPRECATED);
      // @phpstan-ignore-next-line
      $this->fieldPermissionsService = \Drupal::service('custom_field_permissions_instance.permissions_service');
    }
    else {
      $this->fieldPermissionsService = $field_permissions_service;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, FieldStorageConfigInterface $field_storage = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $field_storage,
      $container->get('custom_field_permissions_instance.permissions_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return $this->pluginDefinition['title'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->pluginDefinition['description'];
  }

  /**
   * {@inheritdoc}
   */
  public function appliesToField(FieldDefinitionInterface $field_definition): bool {
    return TRUE;
  }

  /**
   * Determines if the given account may view the field, regardless of entity.
   *
   * This should only return TRUE if:
   * @code
   * $this->hasFieldAccess('view', $entity, $account);
   * @endcode
   * returns TRUE for all possible $entity values.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user to check access for.
   *
   * @return bool
   *   The access result.
   *
   * @todo Move this to an interface: either FieldPermissionTypeInterface or a
   *   new one.
   */
  public function hasFieldViewAccessForEveryEntity(AccountInterface $account) {
    return FALSE;
  }

}
