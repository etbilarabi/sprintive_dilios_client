<?php

namespace Drupal\sprintive_dilios_client;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rabbit_hole\BehaviorSettingsManagerInterface;

/**
 * Class DiliosEntityManager.
 */
class DiliosEntityManager {

    public const ENTITIES = [
        'node_type' => 'node',
        'taxonomy_vocabulary' => 'taxonomy_term',
        'media_type' => 'media',
        'entity_queue' => 'entity_subqueue',
        'user_role' => 'user',
    ];

    protected const FIELDS_TO_SKIP = ['status', 'promote'];

    protected const CONTENT_PERMISSIONS = [
        'create %s content',
        'edit any %s content',
        'edit own %s content',
        'revert %s revisions',
        'view %s revisions',
        'translate %s node',
    ];

    protected const TAXONOMY_PERMISSIONS = [
        'create terms in %s',
        'edit terms in %s',
        'reorder terms in %s',
        'translate %s taxonomy_term',
        'view terms in %s',
    ];

    protected const QUEUES_PERMISSIONS = [
        'update %s entityqueue',
    ];

    protected const OTHER_PERMISSIONS = [
        'manipulate all entityqueues',
        'manipulate entityqueues',
    ];

    /**
     * The entity type manager interface.
     *
     * @var EntityTypeManagerInterface $entityTypeManager
     */
    protected $entityTypeManager;

    /**
     * The entity field manager interface.
     *
     * @var EntityFieldManagerInterface $entityFieldManager
     */
    protected $entityFieldManager;

    /**
     * The config manager service.
     *
     * @var ConfigFactoryInterface $configFactory
     */
    private $configFactory;

    /**
     * Rabbit Hole Behavior Settings Manager Interface
     *
     * @var BehaviorSettingsManagerInterface $behaviorSettingsManager
     */
    private $behaviorSettingsManager;

    /**
     * Constructs a new DiliosManager object.
     *
     * @param EntityTypeManagerInterface $entity_type_manager
     * @param EntityFieldManagerInterface $entity_field_manager
     * @param ConfigFactoryInterface $config_factory
     * @param BehaviorSettingsManagerInterface $behavior_settings_manager
     */
    public function __construct(
        EntityTypeManagerInterface $entity_type_manager,
        EntityFieldManagerInterface $entity_field_manager,
        ConfigFactoryInterface $config_factory,
        BehaviorSettingsManagerInterface $behavior_settings_manager
    ) {
        $this->entityTypeManager = $entity_type_manager;
        $this->entityFieldManager = $entity_field_manager;
        $this->configFactory = $config_factory;
        $this->behaviorSettingsManager = $behavior_settings_manager;
    }

    /**
     * @param string $entity_type_id
     *
     * @return array
     */
    public function getEntityBundles($entity_type_id) {
        $bundles = [];
        /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface[] $entity_types */
        $entity_types = $this->entityTypeManager->getStorage($entity_type_id)->loadMultiple();
        foreach ($entity_types as $entity_type) {
            $description = $entity_type->get('description') ?? $entity_type->getThirdPartySetting('seeds_pollination', 'description');
            $bundles[$entity_type->id()] = [
                'name' => $entity_type->label(),
                'description' => $description,
            ];
        }
        return $bundles;
    }

    /**
     * @param string $entity_type_id
     *
     * @return array
     */
    public function getEntityBundleInfo($entity_type_id) {
        $bundles = $this->getEntityBundles($entity_type_id);
        foreach ($bundles as $bundle_key => $bundle_value) {
            // Getting fields of the bundle
            $fields = $this->entityFieldManager->getFieldDefinitions(self::ENTITIES[$entity_type_id], $bundle_key);
            foreach ($fields as $field) {
                // Skipping unnecessary fields
                if ($field->getTargetBundle() !== NULL && !in_array($field->getName(), self::FIELDS_TO_SKIP)) {
                    $bundles[$bundle_key]['fields'][$field->getName()] = [
                        'field_name' => $field->getLabel(),
                        'field_description' => $field->getDescription(),
                    ];
                }
            }

            // Getting Rabbit Hole config
            $rabbit_hole = $this->behaviorSettingsManager->loadBehaviorSettingsAsConfig($entity_type_id, $bundle_key);
            $bundles[$bundle_key]['config']['rabbit_hole'] = [
                'action' => $rabbit_hole->get('action'),
                'redirect' => $rabbit_hole->get('redirect'),
            ];

            // Getting Simple Sitemap config
            $simple_sitemap = $this->configFactory->get('simple_sitemap.bundle_settings.default.' . self::ENTITIES[$entity_type_id] . '.' . $bundle_key);
            $bundles[$bundle_key]['config']['simple_sitemap'] = [
                'priority' => $simple_sitemap->get('priority'),
                'changefreq' => $simple_sitemap->get('changefreq'),
            ];
        }
        return $bundles;
    }

    // TODO: To be used later
    public function getBundlePermissions($role_id, $type, $bundle, $project_code, &$counter) {
        $permission_template = $this->getPermissionTemplate($type);
        $permissions = array_map(static function ($permission) use ($bundle) {
            return sprintf($permission, $bundle);
        }, $permission_template);

        $role = $this->loadRole($role_id);
        $rows = [];
        foreach ($permissions as $permission) {
            if (isset($role) && $role->hasPermission($permission)) {
                $rows[] = [
                    $project_code . '_' . $counter++,
                    $role->label() . ' can ' . ucwords(str_replace('_', ' ', $permission)),
                ];
            }
        }
        return $rows;
    }

    public function getOtherPermissions($role_id, $project_code, &$counter) {
        $role = $this->loadRole($role_id);
        $rows = [];
        foreach (self::OTHER_PERMISSIONS as $permission) {
            if (isset($role) && $role->hasPermission($permission)) {
                $rows[] = [
                    $project_code . '_' . $counter++,
                    $role->label() . ' can ' . ucwords(str_replace('_', ' ', $permission)),
                ];
            }
        }
        return $rows;
    }

    /**
     * @param $role_id
     *
     * @return \Drupal\user\RoleInterface|null
     */
    private function loadRole($role_id) {
        return $this->entityTypeManager->getStorage('user_role')->load($role_id);
    }

    private function getPermissionTemplate($type) {
        switch ($type) {
        case 'content_types':
            return self::CONTENT_PERMISSIONS;

        case 'taxonomy_term':
            return self::TAXONOMY_PERMISSIONS;

        case 'entity_queues':
            return self::QUEUES_PERMISSIONS;
        }
    }
}
