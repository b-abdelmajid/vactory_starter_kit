<?php

namespace Drupal\vactory_views_pretty_path\PathProcessor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\PathProcessor\OutboundPathProcessorInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\language\ConfigurableLanguageManager;
use Drupal\node\NodeInterface;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class VactoryViewsPrettyPathProcessor.
 *
 * @package Drupal\vactory_views_pretty_path\PathProcessor
 */
class VactoryViewsPrettyPathProcessor implements InboundPathProcessorInterface, OutboundPathProcessorInterface {

  /**
   * True once path is outbounding processed.
   *
   * @var bool
   */
  protected $isOutboundProcessed;

  /**
   * Entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Entity repository manager service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Vactory Views Pretty Path module settings.
   *
   * @var array
   */
  protected $config;

  /**
   * Current route match object.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $routeMatch;

  /**
   * Current language code.
   *
   * @var string
   */
  protected $langcode;

  /**
   * Site terms.
   *
   * @var array
   */
  protected $terms;

  /**
   * VactoryViewsPrettyPathProcessor constructor.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityRepositoryInterface $entityRepository, ConfigFactoryInterface $configFactory, CurrentRouteMatch $routeMatch, ConfigurableLanguageManager $languageManager) {
    $this->isOutboundProcessed = FALSE;
    $this->entityTypeManager = $entityTypeManager;
    $this->entityRepository = $entityRepository;
    $this->config = $configFactory->get('vactory_views_pretty_path.settings');
    $this->routeMatch = $routeMatch;
    $this->langcode = $languageManager->getCurrentLanguage()->getId();
    $this->terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadMultiple();
  }

  /**
   * Processes the inbound path.
   *
   * Implementations may make changes to the request object passed in but should
   * avoid all other side effects. This method can be called to process requests
   * other than the current request.
   *
   * @param string $path
   *   The path to process, with a leading slash.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HttpRequest object representing the request to process. Note, if this
   *   method is being called via the path_processor_manager service and is not
   *   part of routing, the current request object must be cloned before being
   *   passed in.
   *
   * @return string
   *   The processed path.
   */
  public function processInbound($path, Request $request) {
    // Get vactory views pretty path module settings.
    $paths_infos = $this->config->get('paths');
    if (is_array($paths_infos)) {
      // Get concerned paths from module settings.
      $concerned_paths = $this->getConcernedPaths($paths_infos);
      $terms = $this->terms;
      // Loop through all concerned paths to get associated concerned path.
      foreach ($concerned_paths as $index => $concerned_path) {
        if (strpos($path, $concerned_path) === 0 && $this->pathShouldBeRewritten($path)) {
          // Get associated view infos from module settings.
          $view_name = $paths_infos[$index]['view'];
          $view_display_id = $paths_infos[$index]['display'];
          // Get filter name mapping from module settings.
          $view_filter_name_map_str = $paths_infos[$index]['filter_map_container'][$this->langcode]['views_filter_name_map'];
          $view_filter_name_map = get_view_filter_name_map($view_filter_name_map_str);
          $has_one_term_filter_field = count($view_filter_name_map) === 1;
          // Get only taxonomy term exposed filter from concerned view.
          $taxonomy_exposed_filters = $this->getViewExposedTaxonomyTermFilters($view_name, $view_display_id);
          $taxonomy_exposed_filters_identifiers = [];
          foreach ($taxonomy_exposed_filters as $taxonomy_exposed_filter) {
            $taxonomy_exposed_filters_identifiers[$taxonomy_exposed_filter['id']]['identifier'] = $taxonomy_exposed_filter['expose']['identifier'];
            $taxonomy_exposed_filters_identifiers[$taxonomy_exposed_filter['id']]['is_multiple'] = isset($taxonomy_exposed_filter['expose']['multiple']) ? $taxonomy_exposed_filter['expose']['multiple'] : FALSE;
          }
          $path_tmp = $path;
          $path_tmp = ltrim(str_replace($concerned_path, '', $path_tmp), '/');
          $tid = NULL;
          if (!empty($path_tmp) && $has_one_term_filter_field) {
            $term_name = str_replace('-', ' ', $path_tmp);
            $concerned_filter_id = array_keys($view_filter_name_map)[0];
            if ($concerned_filter_id !== FALSE) {
              $filter_identifier = $taxonomy_exposed_filters_identifiers[$concerned_filter_id]['identifier'];
              $has_multiple_values = $taxonomy_exposed_filters_identifiers[$concerned_filter_id]['is_multiple'];
              $tid = $this->getConcernedTermsIds($term_name, $concerned_filter_id, $terms, $has_multiple_values);
              if ($tid) {
                // Add path founded filter by taxonomy to the request.
                $request->request->set($filter_identifier, $tid);
                $request->query->set($filter_identifier, $tid);
              }
            }
          }
          if (!empty($path_tmp) && !$has_one_term_filter_field) {
            // Get taxonomy filter params from path.
            $path_params = explode('/', $path_tmp);
            if ($this->langcode == 'ar') {
              $path_params = array_reverse($path_params);
            }
            $path_params_tmp = [];
            if (!empty($path_params)) {
              foreach ($path_params as $key => $value) {
                if ($key % 2 === 0 && isset($path_params[$key + 1])) {
                  $path_params_tmp[$value] = $path_params[$key + 1];
                }
              }
              $path_params = $path_params_tmp;
              // Loop through path params to get term id from term name.
              foreach ($path_params as $key => $term_name) {
                $term_name = str_replace('-', ' ', $term_name);
                $key = str_replace('-', ' ', $key);
                $concerned_filter_id = array_search($key, $view_filter_name_map);
                if ($concerned_filter_id !== FALSE) {
                  $filter_identifier = $taxonomy_exposed_filters_identifiers[$concerned_filter_id]['identifier'];
                  $has_multiple_values = $taxonomy_exposed_filters_identifiers[$concerned_filter_id]['is_multiple'];
                  $tid = $this->getConcernedTermsIds($term_name, $concerned_filter_id, $terms, $has_multiple_values);
                  if (isset($tid)) {
                    // Add path founded filter by taxonomy to the request.
                    $request->request->set($filter_identifier, $tid);
                    $request->query->set($filter_identifier, $tid);
                  }
                }
              }
            }
          }
          if (isset($tid)) {
            $request->overrideGlobals();
            // Set current path to the original path.
            $path = $concerned_path;
          }
          break;
        }
      }
    }

    return $path;
  }

  /**
   * Processes the outbound path.
   *
   * @param string $path
   *   The path to process, with a leading slash.
   * @param mixed $options
   *   (optional) An associative array of additional options, with the following
   *   elements:
   *   - 'query': An array of query key/value-pairs (without any URL-encoding)
   *     to append to the URL.
   *   - 'fragment': A fragment identifier (named anchor) to append to the URL.
   *     Do not include the leading '#' character.
   *   - 'absolute': Defaults to FALSE. Whether to force the output to be an
   *     absolute link (beginning with http:). Useful for links that will be
   *     displayed outside the site, such as in an RSS feed.
   *   - 'language': An optional language object used to look up the alias
   *     for the URL. If $options['language'] is omitted, it defaults to the
   *     current language for the language type LanguageInterface::TYPE_URL.
   *   - 'https': Whether this URL should point to a secure location. If not
   *     defined, the current scheme is used, so the user stays on HTTP or HTTPS
   *     respectively. TRUE enforces HTTPS and FALSE enforces HTTP.
   *   - 'base_url': Only used internally by a path processor, for example, to
   *     modify the base URL when a language dependent URL requires so.
   *   - 'prefix': Only used internally, to modify the path when a language
   *     dependent URL requires so.
   *   - 'route': The route object for the given path. It will be set by
   *     \Drupal\Core\Routing\UrlGenerator::generateFromRoute().
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HttpRequest object representing the current request.
   * @param \Drupal\Core\Render\BubbleableMetadata $bubbleable_metadata
   *   (optional) Object to collect path processors' bubbleable metadata.
   *
   * @return string
   *   The processed path.
   */
  public function processOutbound($path, &$options = [], Request $request = NULL, BubbleableMetadata $bubbleable_metadata = NULL) {
    // Get vactory views pretty path module settings.
    $paths_infos = $this->config->get('paths');
    if (is_array($paths_infos)) {
      // Get concerned paths from module settings.
      $concerned_paths = $this->getConcernedPaths($paths_infos);
      // Check if current path should be processed.
      if (in_array($path, $concerned_paths) && !$this->isOutboundProcessed && $request->query && $this->pathShouldBeRewritten($path)) {
        // Get the query params.
        $query_params = $request->query->all();
        // Get concerned path infos.
        $current_path_index = array_search($path, $concerned_paths);
        $view_name = $paths_infos[$current_path_index]['view'];
        $view_display_id = $paths_infos[$current_path_index]['display'];
        // Get associated filter name mapping from module settings.
        $view_filter_name_map_str = $paths_infos[$current_path_index]['filter_map_container'][$this->langcode]['views_filter_name_map'];
        $view_filter_name_map = get_view_filter_name_map($view_filter_name_map_str);
        $has_one_term_filter_field = count($view_filter_name_map) === 1;
        // Get only taxonomy term exposed filter from concerned view.
        $taxonomy_exposed_filters = $this->getViewExposedTaxonomyTermFilters($view_name, $view_display_id);
        foreach ($taxonomy_exposed_filters as $taxonomy_exposed_filter) {
          // Get filter identifier.
          $identifier = $taxonomy_exposed_filter['expose']['identifier'];
          // Get the filter mapped name.
          $filter_name = $view_filter_name_map[$taxonomy_exposed_filter['id']];
          // Check if filter identifier appears on query params.
          if (isset($query_params[$identifier])) {
            // Get the filter value (the taxonomy id).
            $tid = $query_params[$identifier];
            if (is_array($tid)) {
              // Filter with multiple values case.
              $terms = $this->entityTypeManager->getStorage('taxonomy_term')
                ->loadMultiple($tid);
              if (!empty($terms)) {
                $this->addMultipleTermFilterToPath($path, $filter_name, $this->langcode, $terms, $has_one_term_filter_field);
              }
            }
            else {
              // Load the associated term object.
              $term = $this->entityTypeManager->getStorage('taxonomy_term')
                ->load($tid);
              if ($term) {
                $this->addTermFilterToPath($path, $filter_name, $this->langcode, $term, $has_one_term_filter_field);
              }
            }
          }
        }
        // Now it's time to delete the term identifier from query params.
        if (!empty($query_params)) {
          foreach ($query_params as $key => $value) {
            foreach ($taxonomy_exposed_filters as $taxonomy_exposed_filter) {
              // Also delete query params which have an empty value.
              if ($key === $taxonomy_exposed_filter['expose']['identifier'] || empty($value)) {
                $request->query->remove($key);
                $request->request->set($key, $value);
              }
            }
          }
          $request->overrideGlobals();
        }
        $this->isOutboundProcessed = TRUE;
      }
    }
    return $path;
  }

  /**
   * Get concerned paths.
   */
  protected function getConcernedPaths($paths_infos) {
    return array_map(function ($el) {
      return $el['path'];
    }, $paths_infos);
  }

  /**
   * Get view exposed filters.
   */
  protected function getViewExposedTaxonomyTermFilters($view_name, $view_display_id) {
    $view = Views::getView($view_name);
    $view_filters = $view->getHandlers('filter', $view_display_id);
    $view_exposed_taxonomy_term_filters = [];
    foreach ($view_filters as $filter) {
      if (isset($filter['exposed']) && $filter['exposed'] && $filter['plugin_id'] == 'taxonomy_index_tid') {
        $view_exposed_taxonomy_term_filters[] = $filter;
      }
    }
    return $view_exposed_taxonomy_term_filters;
  }

  /**
   * Add given term object name and filter name to the path.
   */
  protected function addTermFilterToPath(&$path, $filter_name, $langcode, $term_object, $has_one_term_filter_field) {
    // Get the term translation to use the translated term name.
    $term_translation = $this->entityRepository->getTranslationFromContext($term_object, $langcode);
    // Use urlencode to format the term name.
    $term_name = $term_translation->getName();
    $filter_name = str_replace(' ', '-', $filter_name);
    $term_name = str_replace(' ', '-', $term_name);
    if ($langcode == 'ar') {
      $path_suffix = '/' . $term_name . '/' . $filter_name;
    }
    else {
      $path_suffix = '/' . $filter_name . '/' . $term_name;
    }
    $path_suffix = $has_one_term_filter_field ? '/' . $term_name : $path_suffix;
    $path .= $path_suffix;
  }

  /**
   * Add given terms object names and filter name to the path.
   */
  protected function addMultipleTermFilterToPath(&$path, $filter_name, $langcode, $terms, $has_one_term_filter_field) {
    $path_suffix = '/';
    $index = 0;
    if (!empty($terms)) {
      foreach ($terms as $key => $term) {
        // Get the term translation to use the translated term name.
        $term_translation = $this->entityRepository->getTranslationFromContext($term, $langcode);
        // Use urlencode to format the term name.
        $term_name = $term_translation->getName();
        $term_name = str_replace(' ', '-', $term_name);
        $path_suffix .= $index === 0 ? $term_name : '.' . $term_name;
        $index++;
      }
      $filter_name = str_replace(' ', '-', $filter_name);
      $new_path_suffix = $langcode == 'ar' ? $path_suffix . '/' . $filter_name : '/' . $filter_name . $path_suffix;
      $new_path_suffix = $has_one_term_filter_field ? $path_suffix : $new_path_suffix;
      $path .= $new_path_suffix;
    }
  }

  /**
   * Check if given path should be rewritten.
   */
  protected function pathShouldBeRewritten($path) {
    $is_to_rewrite = TRUE;
    $node = $this->routeMatch->getParameter('node');
    // Except page content type in case when a view is injected to paragraphs.
    if (isset($node) && $node instanceof NodeInterface && $node->bundle() !== 'vactory_page') {
      $is_to_rewrite = FALSE;
    }
    return $is_to_rewrite;
  }

  /**
   * Get IDs of path terms params.
   */
  protected function getConcernedTermsIds($term_name, $concerned_filter_id, $terms, $has_multiple_values) {
    global $_concerned_term_name;
    global $_has_multiple_values;
    $_has_multiple_values = $has_multiple_values;
    $tid = NULL;
    $term_name = str_replace('-', ' ', $term_name);
    $term_name = strpos($term_name, '.') > 0 ? explode('.', $term_name) : $term_name;
    $term_name = $_has_multiple_values && !is_array($term_name) ? [$term_name] : $term_name;
    $_concerned_term_name = $term_name;
    $filtered_term = array_filter($terms, function ($term) {
      global $_concerned_term_name;
      global $_has_multiple_values;
      $term_translation = $this->entityRepository->getTranslationFromContext($term, $this->langcode);
      $name = $term_translation->getName();
      return $_has_multiple_values ? in_array($name, $_concerned_term_name) : $name == $_concerned_term_name;
    });
    if (!empty($filtered_term) && $concerned_filter_id) {
      $tid = array_keys($filtered_term)[0];
      if ($_has_multiple_values) {
        $tid = array_map(function ($el) {
          return (string) $el;
        }, array_keys($filtered_term));
      }
    }
    unset($_concerned_term_name, $_has_multiple_values);
    return $tid;
  }

}
