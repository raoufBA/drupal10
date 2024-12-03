<?php

namespace Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType;

use Drupal;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\custom_field_permissions_instance\CustomFieldPermissionsServiceInterface;
use Drupal\custom_field_permissions_instance\Plugin\FieldPermissionTypeInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\user\RoleStorageInterface;
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
   * @var \Drupal\custom_field_permissions_instance\CustomFieldPermissionsServiceInterface
   */
  protected $customFieldPermissionsService;

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
   * @param \Drupal\custom_field_permissions_instance\CustomFieldPermissionsServiceInterface|null $field_permissions_service
   *   Field permissions service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FieldStorageConfigInterface $field_storage, CustomFieldPermissionsServiceInterface $field_permissions_service = null) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->fieldStorage = $field_storage;
    if ($field_permissions_service === null) {
      @trigger_error('Calling ' . __METHOD__ . '() without the $field_permissions_service argument is deprecated in custom_field_permissions_instance:8.x-1.4 and will be required in custom_field_permissions_instance:8.x-2.0. See https://www.drupal.org/node/3359471', E_USER_DEPRECATED);
      // @phpstan-ignore-next-line
      $this->customFieldPermissionsService = Drupal::service('custom_field_permissions_instance.permissions_service');
    }
    else {
      $this->customFieldPermissionsService = $field_permissions_service;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, FieldStorageConfigInterface $field_storage = null) {
    return new static($configuration, $plugin_id, $plugin_definition, $field_storage, $container->get('custom_field_permissions_instance.permissions_service'),);
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
    return true;
  }

  /**
   * Determines if the given account may view the field, regardless of entity.
   *
   * This should only return TRUE if:
   *
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
   */
  public function hasFieldViewAccessForEveryEntity(AccountInterface $account): bool {
    return false;
  }

  /**
   * @return array<mixed>
   */
  public function getPermissions(): array {
    $permissions = [];
    $field_name = $this->fieldStorage->getName();
    $permission_list = $this->customFieldPermissionsService->getList($field_name);
    $perms_name = array_keys($permission_list);
    /* @phpstan-ignore-next-line */
    foreach ($perms_name as $perm_name) {
      $permissions[$perm_name] = $permission_list[$perm_name];
    }

    return $permissions;
  }

  /**
   * @param AccountInterface $account
   * @param $operation
   *
   * @return bool
   */
  public function UserHasPermission(AccountInterface $account, $operation): bool {
    foreach ($account->getRoles() as $role) {
      if ($operation[$role]) {
        return true;
      }
    }

    return false;
  }

  /**
   * {@inheritdoc}
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function submitAdminForm(array &$form, FormStateInterface $form_state, RoleStorageInterface $role_storage): void {
    $type_is_active = $form_state->getValue('type') === $this->getPluginId();
    // Récupérer les valeurs du formulaire.
    $instance_permissions = $form_state->getValue(static::FORM_ID);
    // Charger la configuration du stockage de champ.
    $this->savePermissions(static::KEY_CONFIG_PERMISSIONS, $instance_permissions, $type_is_active);
  }

  /**
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function savePermissions(string $key_config_permissions, array $permissions, bool $type_is_active): void {
    // reload storage, it's important when the field is new
    $this->reloadStorage();

    // Retrieve field name
    $field_name = $this->fieldStorage->getName();

    if (!$this->fieldStorage) {
      $this->messenger->addError(t('The field @field_name does not exist.', ['@field_name' => $field_name]));

      return;
    }
    if ($type_is_active) {
      $current_config = $this->getConfigPermissions($key_config_permissions);
      $current_config = array_merge($current_config, $permissions);
    }
    else {
      $current_config = [];
    }

    $this->setConfigPermissions($key_config_permissions, $current_config);
  }

  private function reloadStorage(): void {
    $field_name = $this->fieldStorage->getName();
    $target_entity_type_id = $this->fieldStorage->getTargetEntityTypeId();
    $this->fieldStorage = FieldStorageConfig::loadByName($target_entity_type_id, $field_name);
  }

  /**
   * @param string $key_config_permissions
   *
   * @return mixed
   */
  public function getConfigPermissions(string $key_config_permissions): mixed {
    return $this->fieldStorage->getThirdPartySetting('custom_field_permissions_instance', $key_config_permissions, []);
  }

  /**
   * @param string $key_config_permissions
   * @param mixed $config
   *
   * @return void
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function setConfigPermissions(string $key_config_permissions, mixed $config): void {
    // Retrieve field name
    $field_name = $this->fieldStorage->getName();
    // Check if the field supports the "third_party_settings".
    if (method_exists($this->fieldStorage, 'setThirdPartySetting')) {
      // Save permissions in "third_party_settings".
      $this->fieldStorage->setThirdPartySetting('custom_field_permissions_instance', $key_config_permissions, $config);
      $this->fieldStorage->save();
      $this->messenger()->addMessage(t('Permissions have been saved for the field @field_name.', ['@field_name' => $field_name]));
    }
    else {
      $this->messenger()->addError(t('The field @field_name does not support third party settings.', ['@field_name' => $field_name]));
    }
  }

}
