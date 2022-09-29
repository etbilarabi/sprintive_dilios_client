<?php
namespace Drupal\sprintive_dilios_client\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\sprintive_dilios_client\DiliosEntityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Class DiliosController
 * @package Drupal\sprintive_dilios_client\Controller
 */
class DiliosEntityController extends ControllerBase {

    /**
     * Dilios Manager
     *
     * @var DiliosEntityManager $diliosEntityManager
     */
    protected $diliosEntityManager;

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container) {
        $instance = parent::create($container);
        $instance->diliosEntityManager = $container->get('sprintive_dilios_client.entity_manager');
        return $instance;
    }

    public function getEntities($entity_type_id = NULL) {
        $entities = [];
        if ($entity_type_id && array_key_exists($entity_type_id, $this->diliosEntityManager::ENTITIES)) {
            return new JsonResponse($this->diliosEntityManager->getEntityBundleInfo($entity_type_id));
        }
        foreach ($this->diliosEntityManager::ENTITIES as $ENTITY_KEY => $ENTITY_VALUE) {
            $entities[$ENTITY_KEY][] = $this->diliosEntityManager->getEntityBundles($ENTITY_KEY);
        }
        return new JsonResponse($entities);
    }
}
