<?php

namespace Epicode\WebsiteScanner\Auditors;

use Epicode\WebsiteScanner\WebsiteAuditorInterface;
use Epicode\WebsiteScanner\WebsiteAuditorTrait;

class Google implements WebsiteAuditorInterface
{
  use WebsiteAuditorTrait;

  protected $name = 'google';

  public function audit()
  {
    $url_to_scan = $this->url_to_scan;
    $cached_results_key = 'ws-google-audit-' . $url_to_scan;
    $cached_results = get_transient( $cached_results_key );
    
    if ( $this->forceNewResults() ) {
      delete_transient( $cached_results_key );

      $cached_results = get_transient( $cached_results_key );
    }

    if ( $cached_results && empty( $cached_results['errors'] ) ) {
      return $cached_results;
    }

    $audit_base_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    $audit_query_params = http_build_query( [
      'key' => 'AIzaSyCJFps857j_qVDn0UBOXUQM7MS3T8TKX88',
      'locale' => 'en_US',
      'url' => $url_to_scan,
      'strategy' => 'desktop',
    ] );

    $google_audit_categories_arr = array_map( function ( $category ) {
      return sprintf( 'category=%s', $category );
    }, [
      'ACCESSIBILITY',
      'BEST_PRACTICES',
      'PERFORMANCE',
      'SEO',
    ] );
    $google_audit_categories_query_params = implode( '&', $google_audit_categories_arr );
    $audit_query_params .= '&' . $google_audit_categories_query_params;

    $audit_url = $audit_base_url . '?' . $audit_query_params;

    $response = wp_remote_get( $audit_url, [
      'timeout' => HOUR_IN_SECONDS
    ] );
    $response_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $response_body, true );

    $results = [];

    if ( is_wp_error( $response ) ) {
      $results['error'] = $response->get_error_message();
    } else {
      $results = $this->formatDataResults( $data );
      set_transient( $cached_results_key, $results, MINUTE_IN_SECONDS  * 5 );
    }

    return $results;
  }

  private function formatDataResults( $data = [] )
  {
    $results = [];
    // $low_score = 49;
    $priority_audit_ids = [
      'first-meaningful-paint',
      'render-blocking-resources',
      'unminified-javascript',
      'uses-optimized-images',
      'modern-image-formats',
      'uses-long-cache-ttl',
      'bootup-time',
      'mainthread-work-breakdown',
      'third-party-summary',
      'unsized-images',
      'button-name',
      'heading-order',
      'link-name',
      'external-anchors-use-rel-noopener',
      'no-vulnerable-libraries',
      'meta-description',
      'tap-targets',
    ];

    if ( isset( $data['lighthouseResult'] ) ) {
      $categories = $data['lighthouseResult']['categories'];

      foreach ( $categories as $category ) {
        unset(
          $category['description'],
          $category['manualDescription'],
          $category['auditRefs']
        );

        $results['lhrSlim'][$category['id']] = $category;
      }
    }

    if ( isset( $data['lighthouseResult'] ) ) {
      $lhr = $data['lighthouseResult'];
      $scorable_audits = array_filter( $lhr['audits'], function ( $audit ) {
        return ! is_null( $audit['score'] );
      } );
      $categories = $lhr['categories'];

      $performance_audit_refs = $lhr['audits'];

      $new_audits = [];
      $max_audits_per_category = 5;

      foreach ( $categories as $category_key => $category ) {
        $audit_refs = $category['auditRefs'];

        $category_audits = array_filter(
          $audit_refs,
          function ( $audit_ref )
          use( $category_groups, $priority_audit_ids )
          {
            return ! in_array( $audit_ref['group'], ['metrics'] )
              && in_array( $audit_ref['id'], $priority_audit_ids );
          }
        );
        $category_audits = array_slice( $category_audits, 0, $max_audits_per_category );

        $new_category_audits = [];

        if ( $category_audits ) {
          foreach ( $category_audits as $category_audit ) {
            $category_audit_id = $category_audit['id'];

            if ( isset( $scorable_audits[$category_audit_id] ) ) {
              $category_audit_audit = $scorable_audits[$category_audit_id];
              $category_audit_audit_score = $category_audit_audit['score'];

              $impact = 'low';

              if ( !is_null( $category_audit_audit_score ) ) {
                $category_audit_audit_score *= 100;
                if ( $category_audit_audit_score <= 49 ) {
                  $impact = 'high';
                } else if ( $category_audit_audit_score >= 50 && $category_audit_audit_score <= 89 ) {
                  $impact = 'medium';
                }
              }

              $new_category_audits[] = [
                'impact'      => $impact,
                'score'       => $category_audit_audit_score,
                'category'    => $category['title'],
                'audit'       => $category_audit_audit['title'],
                'guide'       => sprintf( 'https://web.dev/%s/', $category_audit_id )
              ];
            }
          }
        }

        $new_audits[$category_key] = $new_category_audits;
      }

      $results['lhr'] = $new_audits;
    }

    return $results;
  }
}