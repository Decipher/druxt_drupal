<?php

declare(strict_types=1);

namespace Drupal\Tests\druxt\Kernel;

use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\decoupled_router\PathTranslatorEvent;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests language handling in the ViewsPathTranslatorSubscriber.
 *
 * The router request has no language prefix of its own. The language has to
 * be read off the requested path instead. Without that a view route always
 * resolves in the default language, so a frontend that asks about /es is
 * told to go to /.
 *
 * @group druxt
 */
#[Group('druxt')]
#[RunTestsInSeparateProcesses]
class ViewsPathTranslationMultilingualKernelTest extends KernelTestBase {

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
    'node',
    'views',
    'path_alias',
    'language',
    'jsonapi',
    'jsonapi_resources',
    'jsonapi_views',
    'decoupled_router',
    'druxt',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('configurable_language');
    $this->installConfig(['system', 'field', 'filter', 'node', 'language']);

    // Add a second language, so the site is multilingual and 'es' is a
    // prefix the site actually has.
    ConfigurableLanguage::createFromLangcode('es')->save();

    // JSON:API Views gives a route only to a view whose base entity type has
    // a bundle carrying a JSON:API resource type. Without a node type there
    // is no jsonapi_views route to assert a language prefix on.
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // Turn on URL language negotiation with the default prefixes: none for
    // the default language, the langcode for every other language.
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', ['language-url' => 0])
      ->save();
    $this->config('language.negotiation')
      ->set('url.source', 'path_prefix')
      ->set('url.prefixes', ['en' => '', 'es' => 'es'])
      ->save();

    $view = View::create([
      'id' => 'druxt_test_view',
      'label' => 'Druxt test view',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'status' => TRUE,
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => [
            'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
          ],
        ],
        'page_1' => [
          'display_plugin' => 'page',
          'id' => 'page_1',
          'display_title' => 'Page',
          'position' => 1,
          'display_options' => [
            'path' => 'druxt-test-view',
          ],
        ],
      ],
    ]);
    $view->save();

    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * Translates a path through the ViewsPathTranslatorSubscriber.
   */
  private function translatePath(string $path): array {
    $request = Request::create('/router/translate-path', 'GET');
    $event = new PathTranslatorEvent(
      $this->container->get('http_kernel'),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      $path
    );
    $this->container->get('druxt.views_path_translator.subscriber')->onPathTranslation($event);

    $response = $event->getResponse();
    return [
      'status' => $response->getStatusCode(),
      'data' => json_decode((string) $response->getContent(), TRUE),
    ];
  }

  /**
   * Tests that a prefixed path reports the language it was asked about.
   */
  public function testPrefixedPathReportsLangcode(): void {
    $result = $this->translatePath('/es/druxt-test-view');

    $this->assertSame(200, $result['status']);
    $this->assertArrayHasKey('langcode', $result['data']['view']);
    $this->assertSame('es', $result['data']['view']['langcode']);
  }

  /**
   * Tests that a prefixed path resolves to a prefixed URL.
   *
   * This is the reported symptom. A frontend asking about /es was handed a
   * resolved URL with no prefix, which sent it back to the default language.
   */
  public function testPrefixedPathResolvesToPrefixedUrl(): void {
    $this->skipUnlessOutboundLanguageProcessing();

    $result = $this->translatePath('/es/druxt-test-view');

    $this->assertSame(200, $result['status']);
    $this->assertStringContainsString('/es/druxt-test-view', $result['data']['resolved']);
  }

  /**
   * Tests that the JSON:API URLs carry the prefix too.
   *
   * A langcode on the view block is not enough on its own. The frontend
   * fetches from these URLs, so an unprefixed one returns default-language
   * content for a page the user asked for in Spanish.
   */
  public function testPrefixedPathReturnsPrefixedJsonapiUrls(): void {
    $this->skipUnlessOutboundLanguageProcessing();

    $result = $this->translatePath('/es/druxt-test-view');

    $this->assertSame(200, $result['status']);
    $this->assertStringContainsString('/es/jsonapi/views/druxt_test_view/page_1', $result['data']['jsonapi_views']);
    $this->assertStringContainsString('/es/jsonapi', $result['data']['jsonapi']['entryPoint']);
    $this->assertStringContainsString('/es/jsonapi', $result['data']['jsonapi']['individual']);
  }

  /**
   * Skips a test that needs outbound language path processing.
   *
   * Generating a prefixed URL runs Url::fromRoute() through
   * PathProcessorLanguage, which builds its processor list from the
   * negotiator. A kernel test does not wire that chain up, so a language
   * option produces an unprefixed URL here even though it prefixes correctly
   * in a real request. Probing rather than skipping outright means these
   * start running on their own if the harness gains the chain.
   */
  private function skipUnlessOutboundLanguageProcessing(): void {
    $language = $this->container->get('language_manager')->getLanguage('es');
    $url = Url::fromRoute('view.druxt_test_view.page_1', [], [
      'absolute' => TRUE,
      'language' => $language,
    ])->toString(TRUE)->getGeneratedUrl();

    if (!str_contains($url, '/es/')) {
      $this->markTestSkipped(
        'Outbound language path processing is not wired up in a kernel test, '
        . 'so a language option does not prefix the generated URL.'
      );
    }
  }

  /**
   * Tests stripping does nothing for a language with no prefix.
   */
  public function testStripLanguagePrefixWithoutPrefix(): void {
    $subscriber = $this->container->get('druxt.views_path_translator.subscriber');
    $method = new \ReflectionMethod($subscriber, 'stripLanguagePrefix');
    $language_manager = $this->container->get('language_manager');

    // The default language has an empty prefix, so there is nothing to strip
    // and the path must come back untouched.
    $english = $language_manager->getLanguage('en');
    $this->assertSame('/druxt-test-view', $method->invoke($subscriber, '/druxt-test-view', $english));

    // A language the prefix configuration says nothing about behaves the same.
    ConfigurableLanguage::createFromLangcode('de')->save();
    $german = $language_manager->getLanguage('de');
    $this->assertSame('/druxt-test-view', $method->invoke($subscriber, '/druxt-test-view', $german));
  }

  /**
   * Tests stripping a prefix from a path.
   */
  public function testStripLanguagePrefix(): void {
    $subscriber = $this->container->get('druxt.views_path_translator.subscriber');
    $method = new \ReflectionMethod($subscriber, 'stripLanguagePrefix');
    $spanish = $this->container->get('language_manager')->getLanguage('es');

    $this->assertSame('/druxt-test-view', $method->invoke($subscriber, '/es/druxt-test-view', $spanish));
    // A path that is only the prefix asks for the site root.
    $this->assertSame('/', $method->invoke($subscriber, '/es', $spanish));
  }

  /**
   * Tests a path with no first segment reports no language.
   */
  public function testRootPathHasNoLanguage(): void {
    $subscriber = $this->container->get('druxt.views_path_translator.subscriber');
    $method = new \ReflectionMethod($subscriber, 'getPathLanguage');

    // There is no first segment to compare against a prefix.
    $this->assertNull($method->invoke($subscriber, '/'));
    $this->assertNull($method->invoke($subscriber, ''));
    $this->assertNull($method->invoke($subscriber, '///'));
  }

  /**
   * Tests an unset prefix configuration is ignored rather than fatal.
   */
  public function testMissingPrefixConfigIsIgnored(): void {
    // A site that has not configured URL negotiation has no prefixes value,
    // so the config get() returns NULL rather than an array.
    $this->config('language.negotiation')->clear('url.prefixes')->save();

    $subscriber = $this->container->get('druxt.views_path_translator.subscriber');
    $method = new \ReflectionMethod($subscriber, 'getPathLanguage');

    $this->assertNull($method->invoke($subscriber, '/es/druxt-test-view'));
  }

  /**
   * Tests a monolingual site reports no language for any path.
   */
  public function testMonolingualSiteHasNoPathLanguage(): void {
    // Removing the second language takes the site back to monolingual, even
    // though the prefix configuration still mentions 'es'.
    ConfigurableLanguage::load('es')->delete();
    $this->container->get('language_manager')->reset();

    $subscriber = $this->container->get('druxt.views_path_translator.subscriber');
    $method = new \ReflectionMethod($subscriber, 'getPathLanguage');

    $this->assertFalse($this->container->get('language_manager')->isMultilingual());
    $this->assertNull($method->invoke($subscriber, '/es/druxt-test-view'));
  }

  /**
   * Tests language prefix detection straight off a requested path.
   */
  public function testPathLanguageDetection(): void {
    $subscriber = $this->container->get('druxt.views_path_translator.subscriber');
    $method = new \ReflectionMethod($subscriber, 'getPathLanguage');

    $this->assertSame('es', $method->invoke($subscriber, '/es/druxt-test-view')?->getId());
    $this->assertSame('es', $method->invoke($subscriber, '/es')?->getId());

    // The default language has an empty prefix, so an unprefixed path says
    // nothing about language and must not be treated as prefixed.
    $this->assertNull($method->invoke($subscriber, '/druxt-test-view'));
    $this->assertNull($method->invoke($subscriber, '/en/druxt-test-view'));

    // A segment that is not a configured prefix is just a path segment.
    $this->assertNull($method->invoke($subscriber, '/de/druxt-test-view'));
  }

  /**
   * Tests that an unprefixed path still resolves in the default language.
   *
   * The default language has an empty prefix, so nothing about this path
   * says which language it is. It must keep behaving as it did before.
   */
  public function testUnprefixedPathResolvesInDefaultLanguage(): void {
    $result = $this->translatePath('/druxt-test-view');

    $this->assertSame(200, $result['status']);
    $this->assertSame('en', $result['data']['view']['langcode']);
    $this->assertStringNotContainsString('/es/', $result['data']['resolved']);
    $this->assertStringNotContainsString('/es/', $result['data']['jsonapi_views']);
  }

}
