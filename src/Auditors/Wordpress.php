<?php

namespace Epicode\WebsiteScanner\Auditors;

use Epicode\WebsiteScanner\WebsiteAuditorInterface;
use Epicode\WebsiteScanner\WebsiteAuditorTrait;
use Symfony\Component\DomCrawler\Crawler;

class Wordpress implements WebsiteAuditorInterface
{
  use WebsiteAuditorTrait;

  protected $name = 'wordpress';

  protected function getDOMCrawler()
  {
    $response = wp_remote_get( $this->url_to_scan );
    
    if ( ! is_wp_error( $response ) ) {
      $html = wp_remote_retrieve_body( $response );
      return new Crawler( $html );
    }

    return null;
  }

  public function includeInResults()
  {
    $theme_style_data = $this->getThemeStyleData();
    return ! empty( $theme_style_data['theme_name'] );
  }

  public function getPossibleTheme()
  {
    $crawler = $this->getDOMCrawler();
    if ( is_null( $crawler ) ) {
      return null;
    }

    $crawled_themes = $crawler
      ->filter( '[href*="/wp-content/themes/"]' )
      ->eq(0)
      ->each( function ( Crawler $node, $i ) {
        $theme_path           = $node->attr('href');
        $theme_possible_slug  = '';
        $theme_possible_version  = '';

        if ($theme_path) {
          // Get slug
          $theme_path_segments = explode( '/', $theme_path );
          foreach ( $theme_path_segments as $j => $theme_path_segment ) {
            if ( 'themes' === $theme_path_segment ) {
              $theme_possible_slug = $theme_path_segments[$j + 1];
              break;
            }
          }
        
          return $theme_possible_slug;
        }
        
        return null;
      } );

    return $crawled_themes[0] ?? null;
  }

  public function getThemeStyleData( $override_file_headers = [] )
  {
    $theme_slug = $this->getPossibleTheme();

    $stylesheet_content = '';

    $stylesheet_uri = sprintf(
      '%s/wp-content/themes/%s/style.css',
      $this->url_to_scan,
      $theme_slug
    );

    $default_file_headers = [
      'theme_name'    => 'Theme Name',
      'homepage'      => 'Theme URI',
      'description'   => 'Description',
      'version'       => 'Version',
      'requires_wp'   => 'Requires at least',
      'tested_wp'     => 'Tested up to',
      'requires_php'  => 'Requires PHP',
    ];

    $file_headers = array_merge( $default_file_headers, $override_file_headers );
    
    $response = wp_remote_get( $stylesheet_uri );

    if ( ! is_wp_error( $response ) ) {
      $stylesheet_content = wp_remote_retrieve_body( $response );
    }

    $current_theme_data = epic_get_content_data(
      $stylesheet_content,
      $file_headers
    );

    $current_theme_data['theme_slug'] = $theme_slug;
    
    return $current_theme_data;
  }

  public function getPossiblePlugins()
  {
    $possible_plugins = [];

    $crawler = $this->getDOMCrawler();

    if ( is_null( $crawler ) ) {
      return $possible_plugins;
    }

    $crawled_plugins = $crawler
      ->filter( '[href*="/wp-content/plugins/"],[src*="/wp-content/plugins/"]' )
      ->each(function ( Crawler $node, $i ) {
        $plugin_path              = $node->attr('href') ?? $node->attr('src') ?? '';
        $plugin_possible_slug     = '';
        $plugin_possible_version  = '';
        
        if ($plugin_path) {
          // Get slug
          $plugin_path_segments = explode( '/', $plugin_path );
          foreach ( $plugin_path_segments as $j => $plugin_path_segment ) {
            if ( 'plugins' === $plugin_path_segment ) {
              $plugin_possible_slug = $plugin_path_segments[$j + 1];
              break;
            }
          }

          // Get version lazily
          $plugin_path_parts = explode( '=', $plugin_path );

          $plugin_possible_version = null;
          if ( count( $plugin_path_parts ) > 1 ) {
            $plugin_possible_version = end( $plugin_path_parts );
          }

          return [
            'slug' => $plugin_possible_slug,
            'version' => $plugin_possible_version,
          ];
        }
        
        return null;
      });
    
    // Filter for duplicate
    foreach ( $crawled_plugins as $crawled_plugin ) {
      if (
        ! is_null( $crawled_plugin )
        && ! in_array( $crawled_plugin, $possible_plugins )
      ) {
        $possible_plugins[] = $crawled_plugin;
      }
    }

    return $possible_plugins;
  }

  public function getPossibleThemeInfo()
  {
    include_once( ABSPATH . 'wp-admin/includes/theme-install.php' );

    $results = [];
    $theme_style_data = $this->getThemeStyleData();
    $theme_slug = $theme_style_data['theme_slug'];
    $current_version = $theme_style_data['version'];

    /**
     * Check theme if hosted on wordpress.org
     */
    $response = themes_api(
      'theme_information',
      [
        'slug' => $theme_slug,
      ]
    );

    /**
     * Theme not found from wordress.org and fallback
     * to detected style.css
     */
    if ( is_wp_error( $response ) ) {
      $results['data'] = [
        'name'              => $theme_style_data['theme_name'],
        'slug'              => $theme_slug,
        'requires_wp'       => $theme_style_data['requires_wp'],
        'tested_wp'         => $theme_style_data['tested_wp'],
        'requires_php'      => $theme_style_data['requires_php'],
        'homepage'          => $theme_style_data['homepage'],
        'current_version'   => $current_version,
        'new_version'       => null, // unknown
        'outdated'          => null, // unknown
      ];
    } else {
      /**
       * Data from wordpress.org
       */
      $data = (array) $response;
      $new_version = $data['version'];
      $results['data'] = [
        'name'              => $data['name'],
        'slug'              => $theme_slug,
        'requires_wp'       => null, // Unknown
        'tested_wp'         => null, // Unknown
        'requires_php'      => null, // Unknown
        'homepage'          => $theme_style_data['homepage'],
        'current_version'   => $current_version,
        'new_version'       => $new_version,
        'outdated'          => version_compare( $current_version, $new_version, '<' ), // unknown
      ];
    }

    $results['data'] = array_map( function ( $data ) {
      return empty( $data ) ? null : $data;
    }, $results['data'] );

    return $results;
  }

  public function getPossiblePluginsInfo()
  {
    include_once( ABSPATH . 'wp-admin/includes/plugin-install.php' );

    $results = [];

    $possible_plugins = $this->getPossiblePlugins();

    foreach ( $possible_plugins as $possible_plugin ) {

      $possible_plugin_slug = $possible_plugin['slug'];

      $response = plugins_api(
        'plugin_information',
        [
          'slug' => $possible_plugin_slug
        ]
      );
      
      if ( ! is_wp_error( $response ) ) {
        $data             = (array) $response;
        $current_version  = $possible_plugin['version'];
        $new_version      = $data['version'];

        $results[$possible_plugin_slug] = [
          'data' => [
            'name'              => $data['name'],
            'slug'              => $data['slug'],
            'requires_wp'       => $data['requires'],
            'tested_wp'         => $data['tested'],
            'requires_php'      => $data['requires_php'],
            'homepage'          => $data['homepage'],
            'current_version'   => $current_version,
            'new_version'       => $new_version,
            'outdated'          => version_compare( $current_version, $new_version, '<' ),
          ],
        ];

        $results[$possible_plugin_slug] = array_map( function ( $data ) {
          return empty( $data ) ? null : $data;
        }, $results[$possible_plugin_slug] );
      } else {
        $results[$possible_plugin_slug] = [
          'data' => [
            'name'              => $possible_plugin_slug,
            'slug'              => $possible_plugin_slug,
            'requires_wp'       => null,
            'tested_wp'         => null,
            'requires_php'      => null,
            'homepage'          => null,
            'current_version'   => null,
            'new_version'       => null,
            'outdated'          => false,
          ],
        ];
      }
    }

    return $results;
  }

  // public function greedyPluginsCheck()
  // {
  //   echo '<pre>';
  //   $page = 1;
  //   $pages = 215;
  //   $per_page = 250;
  //   $found_plugins = [];

  //   while ($page <= $pages) {
  //     $plugins = plugins_api(
  //       'query_plugins',
  //       [
  //         'page' => $page,
  //         'per_page' => $per_page,
  //       ]
  //     );
  // 
  //     // Save in cache
  //     $found_plugins = array_merge(
  //       $found_plugins,
  //       $plugins->plugins
  //     );

  //     $page++;
  //   }

  //   wp_die();
  // }

  public function audit()
  {
    $cached_results_key = 'ws-wordpress-audit-' . $this->url_to_scan;
    $cached_results = get_transient( $cached_results_key );

    if ( $this->forceNewResults() ) {
      delete_transient( $cached_results_key );

      $cached_results = get_transient( $cached_results_key );
    }

    if ( $cached_results ) {
      return $cached_results;
    }

    $data = [
      'theme' => $this->getPossibleThemeInfo(),
      'plugins' => $this->getPossiblePluginsInfo(),
    ];

    set_transient( $cached_results_key, $data, MINUTE_IN_SECONDS  * 5 );

    return $data;
  }
}