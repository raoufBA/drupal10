<?php

namespace Drupal\custom_field_permissions_instance;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Routing\CurrentRouteMatch;


class CurrentInstanceType {

  /**
   * Current route match service
   */
  var $currentRouteMatch;

  /*
   * Entity type manager service
   */
  var $entityTypeManager;

  /**
   * CurrentGroup constructor.
   *
   * @param \Drupal\Core\Routing\CurrentRouteMatch $current_route_match
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   */
  public function __construct(CurrentRouteMatch $current_route_match, EntityTypeManager $entity_type_manager) {
    $this->currentRouteMatch = $current_route_match;
    $this->entityTypeManager = $entity_type_manager;
  }



}
