<?php

/**
 * Inspired by https://developer.wordpress.org/reference/functions/get_file_data/
 */
if ( ! function_exists( 'epic_get_content_data' ) ) {
  function epic_get_content_data( $content = '', $default_headers = [] ) {
    // Make sure we catch CR-only line endings.
    $file_data = str_replace( "\r", "\n", $content );
 
    /**
     * Filters extra file headers by context.
     *
     * The dynamic portion of the hook name, `$context`, refers to
     * the context where extra headers might be loaded.
     *
     * @since 2.9.0
     *
     * @param array $extra_context_headers Empty array by default.
     */
    $extra_headers = $context ? apply_filters( "extra_{$context}_headers", array() ) : array();
    if ( $extra_headers ) {
      $extra_headers = array_combine( $extra_headers, $extra_headers ); // Keys equal values.
      $all_headers   = array_merge( $extra_headers, (array) $default_headers );
    } else {
      $all_headers = $default_headers;
    }
 
    foreach ( $all_headers as $field => $regex ) {
      if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] ) {
        $all_headers[ $field ] = _cleanup_header_comment( $match[1] );
      } else {
         $all_headers[ $field ] = '';
      }
    }
 
    return $all_headers;
  }
}