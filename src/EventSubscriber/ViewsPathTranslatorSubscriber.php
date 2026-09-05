<?php

declare(strict_types=1);

namespace Drupal\druxt\EventSubscriber;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Url;
use Drupal\decoupled_router\EventSubscriber\RouterPathTranslatorSubscriber;
use Drupal\decoupled_router\PathTranslatorEvent;
use Drupal\views\Views;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Event subscriber that processes a path translation with the router info.
 */
class ViewsPathTranslatorSubscriber extends RouterPathTranslatorSubscriber {

  /**
   * {@inheritdoc}
   */
  #[\Override]
  public function onPathTranslation(PathTranslatorEvent $event): void {
    $response = $event->getResponse();
    if (!$this->moduleHandler->moduleExists('jsonapi_views')) {
      return;
    }

    $path = $event->getPath();
    $path = $this->cleanSubdirInPath($path, $event->getRequest());

    // The router request carries no language prefix of its own, so the
    // language has to be read back off the requested path. Without it a
    // view route always resolves in the site's default language and a
    // decoupled frontend sends /es straight back to /.
    $language_manager = $this->container->get('language_manager');
    $language = $this->getPathLanguage($path);
    // A path with no prefix is the default language. The original example
    // output on the issue reports 'en' for that case rather than nothing,
    // so a frontend always has a langcode to work with.
    $langcode = ($language ?: $language_manager->getDefaultLanguage())->getId();
    // Leave the language option unset for an unprefixed path, so generated
    // URLs keep the shape they had before.
    $url_options = ['absolute' => TRUE, 'language' => $language];

    // Config overrides follow the router request's own negotiated language,
    // not the path parameter's, so the view would load with default-language
    // config and getTitle() would return the untranslated title. Point the
    // override language at the path's language before the view is loaded.
    // Drupal strips the language prefix in an inbound path processor, which
    // does not run for a router request. The route is registered against the
    // unprefixed path, so strip the prefix here or the match fails.
    if ($language instanceof LanguageInterface) {
      $path = $this->stripLanguagePrefix($path, $language);
    }

    try {
      $match_info = $this->router->match($path);
    }
    catch (ResourceNotFoundException) {
      // If URL is external, we won't perform checks for content in Drupal,
      // but assume that it's working.
      if (UrlHelper::isExternal($path)) {
        $response->setStatusCode(200);
        $response->setData([
          'resolved' => $path,
        ]);
      }
      return;
    }
    catch (MethodNotAllowedException) {
      $response->setStatusCode(403);
      return;
    }

    if (!isset($match_info['view_id']) || !$match_info['view_id']) {
      return;
    }

    // Config overrides follow the router request's own negotiated language,
    // not the path parameter's, so the view would load with default-language
    // config and getTitle() would return the untranslated title. Point the
    // override language at the path's language, and put it back afterwards:
    // the language manager is shared, so leaving it set would change every
    // later config read in this request.
    $previous_override = $language_manager->getConfigOverrideLanguage();
    if ($language instanceof LanguageInterface) {
      $language_manager->setConfigOverrideLanguage($language);
    }

    try {
      $this->buildViewResponse($event, $response, $match_info, $url_options, $langcode);
    }
    finally {
      $language_manager->setConfigOverrideLanguage($previous_override);
    }
  }

  /**
   * Builds the path translation response for a matched view route.
   *
   * @param \Drupal\decoupled_router\PathTranslatorEvent $event
   *   The path translation event.
   * @param \Drupal\Core\Cache\CacheableJsonResponse $response
   *   The response to populate.
   * @param array $match_info
   *   The matched route information.
   * @param array $url_options
   *   Options for generated URLs, carrying the path's language.
   * @param string $langcode
   *   The langcode to report for the view.
   */
  protected function buildViewResponse(PathTranslatorEvent $event, $response, array $match_info, array $url_options, string $langcode): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $views_storage = $entity_type_manager->getStorage('view');
    $view = $views_storage->load($match_info['view_id']);
    $executable = Views::executableFactory()->get($view);
    $executable->setDisplay($match_info['display_id']);

    $route = $match_info[RouteObjectInterface::ROUTE_OBJECT];
    $route_name = $match_info[RouteObjectInterface::ROUTE_NAME];
    $route_parameters = array_intersect_key(
      $match_info,
      array_flip($route->compile()->getPathVariables())
    );
    $resolved_url = Url::fromRoute($route_name, $route_parameters, $url_options)->toString(TRUE);
    $response->addCacheableDependency($resolved_url);

    $is_home_path = $this->resolvedPathIsHomePath($resolved_url->getGeneratedUrl());
    $response->addCacheableDependency(
      (new CacheableMetadata())->setCacheContexts(['url.path.is_front'])
    );

    $output = [
      'resolved' => $resolved_url->getGeneratedUrl(),
      'isHomePath' => $is_home_path,
      'view' => [
        'uuid' => $view->get('uuid'),
        'view_id' => $match_info['view_id'],
        'display_id' => $match_info['display_id'],
        'langcode' => $langcode,
      ],
      'label' => $executable->getTitle(),
    ];

    // If the route is JSON API, it means that JSON API is installed and its
    // services can be used.
    if ($this->moduleHandler->moduleExists('jsonapi')) {
      $view_type_id = $view->getEntityTypeId();

      /** @var \Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface $rt_repo */
      $rt_repo = $this->container->get('jsonapi.resource_type.repository');
      $rt = $rt_repo->get($view_type_id, $view->bundle());
      $type_name = $rt->getTypeName();
      $jsonapi_base_path = $this->container->getParameter('jsonapi.base_path');
      if (!is_string($jsonapi_base_path)) {
        $jsonapi_base_path = '';
      }
      $entry_point_url = Url::fromRoute('jsonapi.resource_list', [], $url_options)->toString(TRUE);
      $route_name = sprintf('jsonapi.%s.individual', $type_name);
      $individual = Url::fromRoute(
        $route_name,
        [
          static::getEntityRouteParameterName($route_name, $view_type_id) => $view->uuid(),
        ],
        $url_options
      )->toString(TRUE);
      $response->addCacheableDependency($entry_point_url);
      $response->addCacheableDependency($individual);

      $output['jsonapi'] = [
        'individual' => $individual->getGeneratedUrl(),
        'resourceName' => $type_name,
        'pathPrefix' => trim($jsonapi_base_path, '/'),
        'basePath' => $jsonapi_base_path,
        'entryPoint' => $entry_point_url->getGeneratedUrl(),
      ];
      $deprecation_message = 'This property has been deprecated and will be removed in the next version of Decoupled Router. Use @alternative instead.';
      $output['meta'] = [
        'deprecated' => [
          //phpcs:disable
          'jsonapi.pathPrefix' => $this->t($deprecation_message, ['@alternative' => 'basePath']),
        ],
      ];
    }

    $parts = [
      'jsonapi_views',
      $match_info['view_id'],
      $match_info['display_id'],
    ];
    $jsonapi_views_route = implode('.', $parts);
    // JSON:API Views does not give a route to every view. It skips a view that
    // has no base entity type, and a view whose bundles have no JSON:API
    // resource type. Such a view has no JSON:API endpoint. That is not an
    // error, so keep the view data and leave the jsonapi_views key out.
    try {
      $resolved_jsonapi_views_url = Url::fromRoute($jsonapi_views_route, [], $url_options)->toString(TRUE);
      $response->addCacheableDependency($resolved_jsonapi_views_url);

      $output['jsonapi_views'] = $resolved_jsonapi_views_url->getGeneratedUrl();
    }
    catch (RouteNotFoundException) {
      // The view has no JSON:API Views route.
    }

    $response->addCacheableDependency($view);
    $response->setStatusCode(200);
    $response->setData($output);

    $event->stopPropagation();
  }

  /**
   * Removes a language prefix from the start of a path.
   *
   * @param string $path
   *   The requested path.
   * @param \Drupal\Core\Language\LanguageInterface $language
   *   The language the path is prefixed with.
   *
   * @return string
   *   The path with the prefix removed.
   */
  protected function stripLanguagePrefix(string $path, LanguageInterface $language): string {
    $prefixes = $this->configFactory->get('language.negotiation')->get('url.prefixes');
    $prefix = is_array($prefixes) ? ($prefixes[$language->getId()] ?? '') : '';
    if ($prefix === '') {
      return $path;
    }

    // Only strip a prefix the path actually starts with. Taking the length
    // off blindly would eat the first characters of an unprefixed path.
    $segment = '/' . $prefix;
    if ($path !== $segment && !str_starts_with($path, $segment . '/')) {
      return $path;
    }

    // substr() returns a string here, so an empty result means the path was
    // just the prefix and the site root is what was asked for.
    $stripped = substr($path, strlen($segment));
    return $stripped === '' ? '/' : $stripped;
  }

  /**
   * Gets the language a requested path is prefixed with, if any.
   *
   * Only a prefix the site actually has configured counts, so a path that
   * merely starts with a two letter segment is not mistaken for one, and an
   * unprefixed path resolves in the default language as before.
   *
   * @param string $path
   *   The requested path.
   *
   * @return \Drupal\Core\Language\LanguageInterface|null
   *   The language, or NULL when the site is monolingual or the path
   *   carries no language prefix.
   */
  protected function getPathLanguage(string $path): ?LanguageInterface {
    $language_manager = $this->container->get('language_manager');
    if (!$language_manager->isMultilingual()) {
      return NULL;
    }

    // Read the prefix straight off the path. LanguageNegotiator's own
    // getNegotiationMethodInstance() cannot be called here: it sets the
    // current user on the method instance without checking there is one,
    // and nothing sets a current user on the negotiator for a router
    // request, so it raises a TypeError.
    $prefixes = $this->configFactory->get('language.negotiation')->get('url.prefixes');
    if (!is_array($prefixes)) {
      return NULL;
    }

    $segment = explode('/', ltrim($path, '/'), 2)[0];
    if ($segment === '') {
      return NULL;
    }

    foreach ($prefixes as $langcode => $prefix) {
      if ($prefix !== '' && $prefix === $segment) {
        return $language_manager->getLanguage($langcode);
      }
    }

    return NULL;
  }

}
