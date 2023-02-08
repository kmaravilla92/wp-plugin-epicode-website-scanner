<?php

namespace Epicode\WebsiteScanner;

trait WebsiteAuditorTrait
{
  protected $url_to_scan;

  public function setUrlToScan( $url_to_scan )
  {
    $this->url_to_scan = $url_to_scan;
    return $this;
  }

  public function getName()
  {
    return $this->name;
  }

  public function includeInResults()
  {
    return true;
  }

  private function forceNewResults()
  {
    return isset( $_REQUEST['force-new-results'] );
  }
}