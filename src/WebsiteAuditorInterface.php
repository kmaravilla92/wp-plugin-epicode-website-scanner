<?php

namespace Epicode\WebsiteScanner;

interface WebsiteAuditorInterface
{
  public function getName();
  public function setUrlToScan( $url_to_scan );
  public function includeInResults();
  public function audit();
}