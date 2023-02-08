<?php

namespace Epicode\WebsiteScanner;

use Epicode\WebsiteScanner\Auditors\Google as GoogleAuditor;
use Epicode\WebsiteScanner\Auditors\Wordpress as WordpressAuditor;

class WebsiteScanner
{
  protected static $auditors = [];

  public static function initAuditors()
  {
    $auditors = [
      new GoogleAuditor,
      new WordpressAuditor,
    ];
    
    foreach ($auditors as $auditor) {
      static::$auditors[$auditor->getName()] = $auditor;
    }
  }

  public static function init()
  {
    $class = static::class;

    add_action( 'init', [$class, 'initAuditors'] );
    add_action( 'init', [$class, 'setRequestHandlers'] );
  }

  public static function setRequestHandlers()
  {
    $class = static::class;

    add_action( 'wp_ajax_website_scan', [$class, 'websiteScan'] );
    add_action( 'wp_ajax_nopriv_website_scan', [$class, 'websiteScan'] );
  }

  public static function websiteScan()
  {
    $post_data = $_POST;

    $url_to_scan = $post_data['url'] ?? '';
    $url_to_scan = trim( $url_to_scan, '/' );

    $audit_source = $post_data['audit_source'] ?? '';
    $auditor = static::$auditors[$audit_source];

    if ($auditor) {
      $auditor = $auditor->setUrlToScan( $url_to_scan );

      if ( $auditor->includeInResults() ) {
        $audit = $auditor->audit();

        if ( $error = $audit['error'] ) {
          wp_send_json(
            [
              'success' => false,
              'error' => $error
            ]
          );  
        }

        if ( empty( $audit ) ) {
          wp_send_json(
            [
              'success' => false,
              'error' =>[
                'code' => 'ews_empty_audit',
                'message' =>  __('Audit returns empty results', 'epicode'),
              ],
            ]
          );
        }

        wp_send_json(
          [
            'success' => true,
            'data' => $audit
          ]
        );
      }

      wp_send_json(
        [
          'success' => false,
          'error' => [
            'code' => 'ews_auditor_results_invalid',
            'message' => __( "`{$audit_source}` auditor source is valid but the scan returns non essential audit results.", 'epicode' ),
          ],
        ],
        200
      );
    }

    wp_send_json(
      [
        'success' => false,
        'error' => [
          'code' => 'ews_invalid_auditor_name',
          'message' => __( "`{$audit_source}` is an invalid auditor name.", 'epicode' ),
        ],
      ],
      400
    );
  }
}