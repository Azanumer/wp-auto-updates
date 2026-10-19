<?php
/**
 * Uninstall cleanup for WP Auto Updates.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wau_settings' );
