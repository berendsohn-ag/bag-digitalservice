<?php
namespace BDS;

if ( ! defined( 'ABSPATH' ) ) exit;

class Helpers {
    public static function sanitize_html( $html ) {
        $allowed = wp_kses_allowed_html( 'post' );
        return wp_kses( $html, $allowed );
    }

    public static function read_text( $filename ) {
        $filename = basename( $filename );
        $path = BDS_PATH . 'texts/' . $filename;

        if ( ! file_exists( $path ) ) return '';

        $key = 'bds_text_' . md5( $filename . filemtime( $path ) );
        $cached = get_transient( $key );
        if ( false !== $cached ) return $cached;

        $content = file_get_contents( $path );
        $content = is_string( $content ) ? $content : '';
        $content = self::sanitize_html( $content );

        set_transient( $key, $content, DAY_IN_SECONDS );
        return $content;
    }
}