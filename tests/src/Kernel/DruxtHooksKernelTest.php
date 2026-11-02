<?php

declare(strict_types=1);

namespace Drupal\Tests\druxt\Kernel;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Drupal\jsonapi\Routing\Routes;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * Tests the hooks in druxt.module and druxt.install.
 *
 * @group druxt
 */
#[Group('druxt')]
#[RunTestsInSeparateProcesses]
class DruxtHooksKernelTest extends KernelTestBase {

  /**
   * The key of the access result that the filter access hook gives.
   */
  private const AMONG_ALL = 'filter_among_all';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'field',
    'file',
    'text',
    'filter',
    'path_alias',
    'decoupled_router',
    'jsonapi',
    'druxt',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'druxt']);
    \Drupal::moduleHandler()->loadInclude('druxt', 'install');
  }

  /**
   * Sets the route of the current request.
   */
  private function setRoute(?Route $route): void {
    $route_match = $this->createStub(RouteMatchInterface::class);
    $route_match->method('getRouteObject')->willReturn($route);
    $this->container->set('current_route_match', $route_match);
  }

  /**
   * Gives a JSON:API route.
   */
  private function jsonApiRoute(string $resource, string $method = 'GET'): Route {
    return (new Route('/jsonapi/test', [
      RouteObjectInterface::CONTROLLER_NAME => Routes::CONTROLLER_SERVICE_NAME . ':getCollection',
      Routes::RESOURCE_TYPE_KEY => $resource,
    ]))->setMethods([$method]);
  }

  /**
   * Gives an account that has, or does not have, the Druxt permission.
   */
  private function account(bool $has_permission): AccountInterface {
    $account = $this->createStub(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      fn(string $permission): bool => $has_permission && $permission === 'access druxt resources'
    );
    return $account;
  }

  /**
   * Gives a required resource that exists on this site.
   */
  private function presentResource(): string {
    $entity_type_manager = \Drupal::entityTypeManager();
    foreach (druxt_resources() as $resource) {
      if ($entity_type_manager->hasDefinition(explode('--', $resource)[0])) {
        return $resource;
      }
    }
    $this->fail('The list has no resource for an entity type on this site.');
  }

  /**
   * Makes the JSON:API repository give one answer for each resource.
   */
  private function setResourceType(?ResourceType $resource_type): void {
    $repository = $this->createStub(ResourceTypeRepositoryInterface::class);
    $repository->method('get')->willReturn($resource_type);
    $this->container->set('jsonapi.resource_type.repository', $repository);
  }

  /**
   * Gives a resource type that is internal.
   */
  private function internalResourceType(): ResourceType {
    $resource_type = $this->createStub(ResourceType::class);
    $resource_type->method('isInternal')->willReturn(TRUE);
    return $resource_type;
  }

  /**
   * Replaces the URL generator, so these tests do not need the routes.
   */
  private function stubUrlGenerator(): void {
    $url_generator = $this->createStub(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->willReturn('/admin/reports/status');
    $this->container->set('url_generator', $url_generator);
  }

  /**
   * Tells whether a severity is a warning on every supported core.
   *
   * The severity is an enum from Drupal 11.2, and an integer before that.
   */
  private function isWarning(mixed $severity): bool {
    return $severity instanceof \UnitEnum ? $severity->name === 'Warning' : $severity === 1;
  }

  /**
   * Tests that the filter access hook allows a Druxt read.
   */
  public function testFilterAccessAllowsPermittedRead(): void {
    $this->setRoute($this->jsonApiRoute($this->presentResource()));
    $entity_type = \Drupal::entityTypeManager()->getDefinition('user');

    $result = druxt_jsonapi_entity_filter_access($entity_type, $this->account(TRUE));

    $this->assertIsArray($result);
    $this->assertSame([self::AMONG_ALL], array_keys($result));
    $access = $result[self::AMONG_ALL];
    $this->assertTrue($access->isAllowed());
    // The answer comes from the list, so it must change when the list does.
    $this->assertInstanceOf(CacheableDependencyInterface::class, $access);
    $this->assertContains('config:druxt.settings', $access->getCacheTags());
  }

  /**
   * Tests that the filter access hook grants nothing outside a Druxt read.
   *
   * @param bool $has_permission
   *   Whether the account has the Druxt permission.
   * @param string $case
   *   The kind of route.
   *
   * @dataProvider providerFilterAccessRefused
   */
  #[DataProvider('providerFilterAccessRefused')]
  public function testFilterAccessGrantsNothing(bool $has_permission, string $case): void {
    $route = match ($case) {
      'no route' => NULL,
      'not JSON:API' => new Route('/node'),
      'other resource' => $this->jsonApiRoute('node--page'),
      'write' => $this->jsonApiRoute($this->presentResource(), 'POST'),
      default => $this->jsonApiRoute($this->presentResource()),
    };
    $this->setRoute($route);
    $entity_type = \Drupal::entityTypeManager()->getDefinition('user');

    $this->assertNull(druxt_jsonapi_entity_filter_access($entity_type, $this->account($has_permission)));
  }

  /**
   * Provides the requests that the filter access hook refuses.
   */
  public static function providerFilterAccessRefused(): array {
    return [
      'no route' => [TRUE, 'no route'],
      'not JSON:API' => [TRUE, 'not JSON:API'],
      'other resource' => [TRUE, 'other resource'],
      'write' => [TRUE, 'write'],
      'no permission' => [FALSE, 'read'],
    ];
  }

  /**
   * Tests the report of a resource that JSON:API does not have.
   */
  public function testMissingResourceIsReported(): void {
    $this->setResourceType(NULL);

    $requirements = druxt_requirements('runtime');

    $this->assertArrayHasKey('druxt_resources_missing', $requirements);
    $this->assertContains($this->presentResource(), $requirements['druxt_resources_missing']['description']['#items']);
    $this->assertTrue($this->isWarning($requirements['druxt_resources_missing']['severity']));
    $this->assertEmpty(\Drupal::messenger()->messagesByType('warning'));
  }

  /**
   * Tests the report of a resource that is disabled.
   */
  public function testDisabledResourceIsReported(): void {
    $this->setResourceType($this->internalResourceType());

    $requirements = druxt_requirements('runtime');

    $this->assertArrayHasKey('druxt_resources_disabled', $requirements);
    $this->assertArrayNotHasKey('druxt_resources_missing', $requirements);
    $this->assertContains($this->presentResource(), $requirements['druxt_resources_disabled']['description']['#items']);
    $this->assertTrue($this->isWarning($requirements['druxt_resources_disabled']['severity']));
    $this->assertEmpty(\Drupal::messenger()->messagesByType('warning'));
  }

  /**
   * Tests that installing the module also says what is wrong.
   */
  public function testInstallPhaseAddsWarning(): void {
    $this->stubUrlGenerator();

    $this->setResourceType(NULL);
    druxt_requirements('install');
    $messages = \Drupal::messenger()->deleteByType('warning');
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('are missing', (string) $messages[0]);

    $this->setResourceType($this->internalResourceType());
    druxt_requirements('install');
    $messages = \Drupal::messenger()->deleteByType('warning');
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('are disabled', (string) $messages[0]);
  }

  /**
   * Tests that JSON:API Extras gets a link to enable a disabled resource.
   */
  public function testDisabledResourceLinksToJsonApiExtras(): void {
    $this->stubUrlGenerator();
    $module_handler = $this->createStub(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->willReturnCallback(
      fn(string $module): bool => $module === 'jsonapi_extras'
    );
    $this->container->set('module_handler', $module_handler);
    $this->setResourceType($this->internalResourceType());

    $requirements = druxt_requirements('runtime');

    $items = $requirements['druxt_resources_disabled']['description']['#items'];
    $this->assertNotEmpty($items);
    $url = $items[0]->getUrl();
    $this->assertInstanceOf(Url::class, $url);
    $this->assertSame('entity.jsonapi_resource_config.edit_form', $url->getRouteName());
  }

  /**
   * Tests that the update hook creates the missing view displays.
   */
  public function testUpdate9000CreatesViewDisplays(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('entity_view_display');
    $this->assertNull($storage->load('user.user.default'));

    druxt_update_9000();

    $this->assertNotNull($storage->load('user.user.default'));
  }

  /**
   * Tests that the update hook installs the resource list.
   */
  public function testUpdate10301InstallsTheResourceList(): void {
    $expected = $this->config('druxt.settings')->get('resources');
    $this->assertNotEmpty($expected);
    $this->config('druxt.settings')->delete();

    druxt_update_10301();

    $this->assertSame($expected, $this->config('druxt.settings')->get('resources'));
  }

  /**
   * Tests that a config import does not get extra view displays.
   */
  public function testBundleCreateSkipsConfigImport(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('entity_view_display');

    $this->container->get('config.installer')->setSyncing(TRUE);
    druxt_entity_bundle_create('user', 'user');
    $this->assertNull($storage->load('user.user.default'));

    $this->container->get('config.installer')->setSyncing(FALSE);
    druxt_entity_bundle_create('user', 'user');
    $this->assertNotNull($storage->load('user.user.default'));
  }

  /**
   * Tests that only fieldable entity types on this site get view displays.
   */
  public function testViewDisplaysSkipOtherEntityTypes(): void {
    $repository = $this->createStub(EntityDisplayRepositoryInterface::class);
    $repository->method('getAllViewModes')->willReturn([
      'not_on_this_site' => [],
      // A configuration entity type is not fieldable.
      'entity_view_display' => [],
    ]);
    $repository->method('getViewModeOptionsByBundle')->willThrowException(new \LogicException('The entity type was not skipped.'));
    $this->container->set('entity_display.repository', $repository);

    druxt_ensure_entity_view_displays();

    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('entity_view_display')->loadMultiple());
  }

}
