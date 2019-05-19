<?php

namespace Afinode;

$autoloader = require_once 'autoload.php';

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\TempStore\SharedTempStore;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\Core\Update\UpdateRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\ParameterBag;


class MyUpdateController extends \Drupal\Core\Controller\ControllerBase {


  /**
   * A cache backend interface.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The app root.
   *
   * @var string
   */
  protected $root;

  /**
   * The post update registry.
   *
   * @var \Drupal\Core\Update\UpdateRegistry
   */
  protected $postUpdateRegistry;

  protected $sharedTempStoreFactory;

  /**
   * Constructs a new UpdateController.
   *
   * @param string $root
   *   The app root.
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $key_value_expirable_factory
   *   The keyvalue expirable factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   A cache backend interface.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\Core\Render\BareHtmlPageRendererInterface $bare_html_page_renderer
   *   The bare HTML page renderer.
   * @param \Drupal\Core\Update\UpdateRegistry $post_update_registry
   *   The post update registry.
   */
  public function __construct($root, KeyValueExpirableFactoryInterface $key_value_expirable_factory, CacheBackendInterface $cache, StateInterface $state,
                              ModuleHandlerInterface $module_handler, AccountInterface $account, UpdateRegistry $post_update_registry, SharedTempStoreFactory $sharedTempStoreFactory) {
    $this->root = $root;
    $this->cache = $cache;
    $this->state = $state;
    $this->moduleHandler = $module_handler;
    $this->postUpdateRegistry = $post_update_registry;
    $this->sharedTempStoreFactory = $sharedTempStoreFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('app.root'),
      $container->get('keyvalue.expirable'),
      $container->get('cache.default'),
      $container->get('state'),
      $container->get('module_handler'),
      $container->get('current_user'),
      $container->get('update.post_update_registry'),
      $container->get('user.shared_tempstore')
    );
  }

  public function handle(Request $request) {
    require_once $this->root . '/core/includes/install.inc';
    require_once $this->root . '/core/includes/update.inc';

    drupal_load_updates();
    update_fix_compatibility();

    $store = $this->sharedTempStoreFactory->get('_afinode_update');

    // Saves current maintenance mode
    // Sets maintenance mode
    $maintenance_mode = $this->state->get('system.maintenance_mode', FALSE);
    if (!$maintenance_mode) {
     // $this->state->set('system.maintenance_mode', TRUE);
    }
    
    $start = $this->getModuleUpdates();
    $updates = update_resolve_dependencies($start);
    $module = '';
    // If no updates are needed, script ends
    if (count($updates) > 0) {
      $gotOne = FALSE;
      foreach ($updates as $update) {
        if ($update['allowed']) {
          $gotOne = TRUE;
          $module = $update['module'];
          drupal_set_installed_schema_version($update['module'], $update['number'] - 1);
          $function = $update['module'] . '_update_' . $update['number'];
          try {
            include_once $this->root . '/modules/contrib/' . $module . '/' . $module . '.install';
            $sandbox = $store->get($function);
            if (!$sandbox) {
              $sandbox = ['#finished' => 1];
            }
            $function($sandbox);
          } catch (\Exception $e) {
            $this->state->set('system.maintenance_mode', FALSE);
            return $this->response(420,
              'ERROR',
              'An error occurred during installation of Module ' . $module . ', error:' . $e->getMessage());
          }
          if ($sandbox['#finished'] == 1) {
            drupal_set_installed_schema_version($update['module'], $update['number']);
            $store->delete($function);
          }
          else {
            $store->set($function, $sandbox);
          }
          break;
        }
      }

      if (!$gotOne && !empty($updates)) {
        drupal_flush_all_caches();
        return $this->response(420,
          'Some updates are not allowed to update',
          'Updates not allowed to update: ' . $this->av_updates_string());
      }

      return $this->response(206, 'Some modules can still be updated', 'Modules to update: ' . $this->av_updates_string());
    }
    elseif ($post_updates = $this->postUpdateRegistry->getPendingUpdateFunctions()) {
      $cleared_cache_key = 'post_update_cache_cleared';
      if (empty($store->get($cleared_cache_key))) {
        drupal_flush_all_caches();
        $store->set($cleared_cache_key, 1);
      }
      foreach ($post_updates as $function) {
        list($module, $name) = explode('_post_update_', $function, 2);
        module_load_include('php', $module, $module . '.post_update');
        if (function_exists($function)) {
          try {
            $sandbox = $store->get($function);
            if (!$sandbox) {
              $sandbox = ['#finished' => 1];
            }
            $function($sandbox);
            if (!isset($sandbox['#finished']) || (isset($sandbox['#finished']) && $sandbox['#finished'] >= 1)) {
              $this->postUpdateRegistry->registerInvokedUpdates([$function]);
            }
            $remaining = count($post_updates) - 1;
            if (empty($remaining)) {
              $store->delete($cleared_cache_key);
            }
          } catch (\Exception $e) {
            $this->state->set('system.maintenance_mode', FALSE);
            return $this->response(420,
              'ERROR',
              'An error occurred during installation of Module ' . $module . ', error:' . $e->getMessage());
          }
          if ($sandbox['#finished'] == 1) {
            $store->delete($function);
          }
          else {
            $store->set($function, $sandbox);
          }
          break;
        }
      }

      return $this->response(206, 'Some modules can still be POST updated', 'Modules to POST update: ' . $this->av_updates_string());
    }

    //$this->state->set('system.maintenance_mode', FALSE);

    drupal_flush_all_caches();
    return $this->response(200, 'No updates to run');
  }

  protected function getModuleUpdates() {
    $return = [];
    $updates = update_get_update_list();
    foreach ($updates as $module => $update) {
      $return[$module] = $update['start'];
    }
    return $return;
  }

  protected function response($code, $message, $die_message = NULL) {
    header('HTTP/1.1 '.$code.' '.$message);
    $die_message == NULL ? exit($message) : exit($die_message);
  }

  protected function av_updates_string() {
    $updates = update_resolve_dependencies($this->getModuleUpdates());
    $post_updates = $this->postUpdateRegistry->getPendingUpdateFunctions();
    $up = '';
    foreach ($updates as $update) {
      $up .= $update['module'] . ' | ';
    }
    foreach ($post_updates as $function) {
      $function_explode = explode('_post_update_', $function, 2);
      $up .= $function_explode[0] . ' | ';
    }
    return $up;
  }
}

class AfinodeKernel extends \Drupal\Core\Update\UpdateKernel {
  public function __construct($environment, $class_loader, $allow_dumping = TRUE, $app_root = NULL) {
    parent::__construct($environment, $class_loader, $allow_dumping, $app_root);
  }
  public function handle(\Symfony\Component\HttpFoundation\Request $request, $type = self::MASTER_REQUEST, $catch = TRUE) {
    return \Drupal\Core\Update\UpdateKernel::handle($request, $type, $catch); // TODO: Change the autogenerated stub
  }

  protected function handleRaw(Request $request) {
    $container = $this->getContainer();

    $this->handleAccess($request, $container);

    /** @var \Drupal\Core\Controller\ControllerResolverInterface $controller_resolver */
    $controller_resolver = $container->get('controller_resolver');

    /** @var callable $db_update_controller */
    $db_update_controller = $controller_resolver->getControllerFromDefinition('\Afinode\MyUpdateController::handle');

    $this->setupRequestMatch($request);

    $argument_resolver = $container->get('http_kernel.controller.argument_resolver');
    $arguments = $argument_resolver->getArguments($request, $db_update_controller);
    return call_user_func_array($db_update_controller, $arguments);
  }

  protected function setupRequestMatch(Request $request) {
    $path = $request->getPathInfo();
    $args = explode('/', ltrim($path, '/'));

    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'system.db_update');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $this->getContainer()->get('router.route_provider')->getRouteByName('system.db_update'));
    $op = $args[0] ?: 'info';
    $request->attributes->set('op', 'run');
    $request->attributes->set('_raw_variables', new ParameterBag(['op' => 'run']));
  }

  protected function handleAccess(Request $request) {

  }

}

/*
 * If Drupal is not installed yet, then do nothing
 */
if (!file_exists(__DIR__.'/sites/default/settings.php')){
  header('HTTP/1.1 200 OK');
  die('OK');
}

require_once 'core/includes/bootstrap.inc';
require_once 'core/includes/install.inc';
require_once 'core/includes/update.inc';
require_once 'core/includes/schema.inc';

if (drupal_valid_test_ua()) {
  gc_collect_cycles();
  gc_disable();
}

$kernel = new AfinodeKernel('prod', $autoloader, FALSE);
$request = Request::createFromGlobals();

$kernel->handle($request);
