<?php

namespace Drupal\custom_field_permissions_instance\Plugin;

/**
 * Denotes that this field permission type generates custom permissions.
 */
interface CustomPermissionsInterface {

  /**
   * Returns an array of permissions suitable for use in a permission callback.
   *
   * @return array
   *   An array of permissions.
   */
  public function getPermissions(): array;

}
