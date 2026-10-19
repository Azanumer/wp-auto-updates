<?php
/**
 * Plugin Name: WP Auto Updates
 * Description: Granular control over WordPress auto-updates — core major versions, per-plugin and per-theme toggles, translation updates, email update reports, and suppression of WP's default update emails.
 * Version:     1.0.0
 * Author:      Azan Umer
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-auto-updates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WAU_VERSION', '1.0.0' );
define( 'WAU_OPTION', 'wau_settings' );

/**
 * Settings merged with defaults. Modes: 'inherit' | 'all' | 'none' | 'custom'.
 *
 * @return array
 */
function wau_get_settings() {
    $defaults = array(
        'core_major'        => 'inherit', // minor updates always follow core behaviour
        'plugins_mode'      => 'inherit',
        'plugins_custom'    => array(),
        'themes_mode'       => 'inherit',
        'themes_custom'     => array(),
        'translations'      => 'inherit',
        'email_report'      => 0,
        'report_email'      => '',
        'disable_wp_emails' => 0,
    );

    $saved = get_option( WAU_OPTION, array() );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }

    return array_merge( $defaults, $saved );
}

/* ------------------------------------------------------------------------- *
 * Auto-update filters
 * ------------------------------------------------------------------------- */

/**
 * Core: minor updates are always on in WordPress; we only control majors.
 */
function wau_allow_major_core_updates( $allow ) {
    $settings = wau_get_settings();
    if ( 'all' === $settings['core_major'] ) {
        return true;
    }
    if ( 'none' === $settings['core_major'] ) {
        return false;
    }
    return $allow; // 'inherit'
}
add_filter( 'allow_major_auto_core_updates', 'wau_allow_major_core_updates' );

/**
 * Plugins: $item is an object with ->slug for bulk updates (bool when queried per-plugin).
 */
function wau_auto_update_plugin( $update, $item ) {
    $settings = wau_get_settings();
    $mode     = $settings['plugins_mode'];

    if ( 'all' === $mode ) {
        return true;
    }
    if ( 'none' === $mode ) {
        return false;
    }
    if ( 'custom' === $mode && is_object( $item ) && isset( $item->slug ) ) {
        return in_array( $item->slug, (array) $settings['plugins_custom'], true );
    }
    return $update; // 'inherit' or unknown shape
}
add_filter( 'auto_update_plugin', 'wau_auto_update_plugin', 10, 2 );

/**
 * Themes: $item is an object with ->theme (stylesheet slug) in bulk updates.
 */
function wau_auto_update_theme( $update, $item ) {
    $settings = wau_get_settings();
    $mode     = $settings['themes_mode'];

    if ( 'all' === $mode ) {
        return true;
    }
    if ( 'none' === $mode ) {
        return false;
    }
    if ( 'custom' === $mode && is_object( $item ) && isset( $item->theme ) ) {
        return in_array( $item->theme, (array) $settings['themes_custom'], true );
    }
    return $update;
}
add_filter( 'auto_update_theme', 'wau_auto_update_theme', 10, 2 );

/**
 * Translation updates.
 */
function wau_auto_update_translation( $update ) {
    $settings = wau_get_settings();
    if ( 'all' === $settings['translations'] ) {
        return true;
    }
    if ( 'none' === $settings['translations'] ) {
        return false;
    }
    return $update;
}
add_filter( 'auto_update_translation', 'wau_auto_update_translation' );

/**
 * Optionally suppress WordPress's own per-update emails (we send our own digest).
 */
function wau_maybe_suppress_wp_emails() {
    $settings = wau_get_settings();
    if ( empty( $settings['disable_wp_emails'] ) ) {
        return;
    }
    add_filter( 'auto_core_update_send_email', '__return_false' );
    add_filter( 'auto_plugin_update_send_email', '__return_false' );
    add_filter( 'auto_theme_update_send_email', '__return_false' );
}
add_action( 'init', 'wau_maybe_suppress_wp_emails' );

/* ------------------------------------------------------------------------- *
 * Update report email
 * ------------------------------------------------------------------------- */

/**
 * After WP-Cron runs automatic updates, email a one-page summary.
 */
function wau_send_update_report( $update_results ) {
    $settings = wau_get_settings();
    if ( empty( $settings['email_report'] ) ) {
        return;
    }

    $to = ! empty( $settings['report_email'] ) ? $settings['report_email'] : get_option( 'admin_email' );
    if ( ! is_email( $to ) ) {
        return;
    }

    $success = 0;
    $failed  = 0;
    $lines   = array();

    foreach ( array( 'plugin', 'theme', 'translation', 'core' ) as $type ) {
        if ( empty( $update_results[ $type ] ) || ! is_array( $update_results[ $type ] ) ) {
            continue;
        }
        foreach ( $update_results[ $type ] as $result ) {
            $name = '';
            if ( is_object( $result ) ) {
                $name = isset( $result->name ) ? $result->name : '';
            }
            $ok = is_object( $result ) && ! empty( $result->result );
            if ( $ok ) {
                $success++;
            } else {
                $failed++;
            }
            $lines[] = sprintf(
                '[%s] %s: %s',
                strtoupper( $type ),
                $name !== '' ? $name : '(unknown)',
                $ok ? 'updated' : 'FAILED'
            );
        }
    }

    if ( 0 === $success && 0 === $failed ) {
        return; // nothing ran — don't spam
    }

    $subject = sprintf(
        '[%s] Auto-update report: %d succeeded, %d failed',
        wp_parse_url( home_url(), PHP_URL_HOST ),
        $success,
        $failed
    );

    $message = "WordPress automatic updates ran on " . home_url() . "\n\n"
        . implode( "\n", $lines )
        . "\n\nReview: " . admin_url( 'update-core.php' ) . "\n";

    wp_mail( $to, $subject, $message );
}
add_action( 'automatic_updates_complete', 'wau_send_update_report' );

/* ------------------------------------------------------------------------- *
 * Settings page
 * ------------------------------------------------------------------------- */

function wau_mode_options() {
    return array(
        'inherit' => 'Inherit (let WordPress / filters decide)',
        'all'     => 'Enable for all',
        'none'    => 'Disable for all',
        'custom'  => 'Custom — pick below',
    );
}

function wau_register_settings() {
    register_setting( 'wau_group', WAU_OPTION, 'wau_sanitize' );
    add_settings_section( 'wau_main', 'Auto-update policy', '__return_null', 'wau' );

    add_settings_field( 'core_major', 'Core major versions', 'wau_mode_field', 'wau', 'wau_main', array( 'key' => 'core_major' ) );
    add_settings_field( 'plugins_mode', 'Plugins', 'wau_mode_field', 'wau', 'wau_main', array( 'key' => 'plugins_mode' ) );
    add_settings_field( 'plugins_custom', 'Plugins — custom selection', 'wau_plugins_field', 'wau', 'wau_main', array() );
    add_settings_field( 'themes_mode', 'Themes', 'wau_mode_field', 'wau', 'wau_main', array( 'key' => 'themes_mode' ) );
    add_settings_field( 'themes_custom', 'Themes — custom selection', 'wau_themes_field', 'wau', 'wau_main', array() );
    add_settings_field( 'translations', 'Translations', 'wau_mode_field', 'wau', 'wau_main', array( 'key' => 'translations' ) );
    add_settings_field( 'email_report', 'Email me an update report', 'wau_checkbox_field', 'wau', 'wau_main', array( 'key' => 'email_report' ) );
    add_settings_field( 'report_email', 'Report email address', 'wau_email_field', 'wau', 'wau_main', array() );
    add_settings_field( 'disable_wp_emails', 'Suppress WordPress default update emails', 'wau_checkbox_field', 'wau', 'wau_main', array( 'key' => 'disable_wp_emails' ) );
}
add_action( 'admin_init', 'wau_register_settings' );

function wau_sanitize( $input ) {
    $clean = array();
    $modes = wau_mode_options();
    foreach ( array( 'core_major', 'plugins_mode', 'themes_mode', 'translations' ) as $key ) {
        $clean[ $key ] = isset( $input[ $key ], $modes[ $input[ $key ] ] ) ? $input[ $key ] : 'inherit';
    }
    $clean['plugins_custom']    = isset( $input['plugins_custom'] ) ? array_map( 'sanitize_text_field', (array) $input['plugins_custom'] ) : array();
    $clean['themes_custom']     = isset( $input['themes_custom'] ) ? array_map( 'sanitize_text_field', (array) $input['themes_custom'] ) : array();
    $clean['email_report']      = empty( $input['email_report'] ) ? 0 : 1;
    $clean['disable_wp_emails'] = empty( $input['disable_wp_emails'] ) ? 0 : 1;
    $clean['report_email']      = isset( $input['report_email'] ) ? sanitize_email( $input['report_email'] ) : '';
    return $clean;
}

function wau_mode_field( $args ) {
    $settings = wau_get_settings();
    printf( '<select name="%s[%s]">', esc_attr( WAU_OPTION ), esc_attr( $args['key'] ) );
    foreach ( wau_mode_options() as $value => $label ) {
        printf(
            '<option value="%s" %s>%s</option>',
            esc_attr( $value ),
            selected( $value, $settings[ $args['key'] ], false ),
            esc_html( $label )
        );
    }
    echo '</select>';
}

function wau_checkbox_field( $args ) {
    $settings = wau_get_settings();
    printf(
        '<input type="checkbox" name="%1$s[%2$s]" value="1" %3$s>',
        esc_attr( WAU_OPTION ),
        esc_attr( $args['key'] ),
        checked( 1, (int) $settings[ $args['key'] ], false )
    );
}

function wau_email_field() {
    $settings = wau_get_settings();
    printf(
        '<input type="email" name="%s[report_email]" value="%s" class="regular-text" placeholder="%s">',
        esc_attr( WAU_OPTION ),
        esc_attr( $settings['report_email'] ),
        esc_attr( get_option( 'admin_email' ) )
    );
}

/**
 * Checkbox list of installed plugins (slugs) for custom mode.
 */
function wau_plugins_field() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $settings = wau_get_settings();
    $plugins  = get_plugins();
    if ( empty( $plugins ) ) {
        echo '<p class="description">No plugins found.</p>';
        return;
    }
    foreach ( $plugins as $file => $data ) {
        $slug = dirname( $file );
        if ( '.' === $slug ) {
            $slug = basename( $file, '.php' );
        }
        printf(
            '<label style="display:block;margin:2px 0;"><input type="checkbox" name="%1$s[plugins_custom][]" value="%2$s" %3$s> %4$s <span class="description">(%2$s)</span></label>',
            esc_attr( WAU_OPTION ),
            esc_attr( $slug ),
            checked( 1, in_array( $slug, (array) $settings['plugins_custom'], true ), false ),
            esc_html( $data['Name'] )
        );
    }
    echo '<p class="description">Used only when Plugins mode is "Custom".</p>';
}

/**
 * Checkbox list of installed themes (stylesheet slugs) for custom mode.
 */
function wau_themes_field() {
    $settings = wau_get_settings();
    $themes   = wp_get_themes();
    if ( empty( $themes ) ) {
        echo '<p class="description">No themes found.</p>';
        return;
    }
    foreach ( $themes as $stylesheet => $theme ) {
        printf(
            '<label style="display:block;margin:2px 0;"><input type="checkbox" name="%1$s[themes_custom][]" value="%2$s" %3$s> %4$s <span class="description">(%2$s)</span></label>',
            esc_attr( WAU_OPTION ),
            esc_attr( $stylesheet ),
            checked( 1, in_array( $stylesheet, (array) $settings['themes_custom'], true ), false ),
            esc_html( $theme->get( 'Name' ) )
        );
    }
    echo '<p class="description">Used only when Themes mode is "Custom".</p>';
}

function wau_add_menu() {
    add_options_page( 'Auto Updates', 'Auto Updates', 'manage_options', 'wau', 'wau_render_page' );
}
add_action( 'admin_menu', 'wau_add_menu' );

function wau_render_page() {
    ?>
    <div class="wrap">
        <h1>Auto Updates</h1>
        <p>Decide exactly what WordPress updates by itself. "Custom" mode uses the checkbox lists below it. Reports arrive only when updates actually ran.</p>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'wau_group' );
            do_settings_sections( 'wau' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
