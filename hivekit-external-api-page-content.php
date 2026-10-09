<?php
/**
 * Plugin Name: HiveKit External API Page Content
 * Plugin URI: https://github.com/corradofiore/wp-external-api-page-content
 * Description: Replaces the body of configured WordPress pages with raw HTML or Markdown retrieved from external HTTP APIs.
 * Version: 1.3.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Author: Corrado Fiore
 * Author URI: https://corradofiore.it
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hivekit-external-api-page-content
 *
 * @package HiveKit_EAPC
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-hivekit-eapc-markdown.php';

final class HiveKit_EAPC_Plugin {
    const VERSION                = '1.3.0';
    const OPTION_SETTINGS        = 'hivekit_eapc_settings';
    const OPTION_GENERATION      = 'hivekit_eapc_cache_generation';
    const OPTION_TRANSIENT_INDEX = 'hivekit_eapc_transient_index';
    const SETTINGS_GROUP         = 'hivekit_eapc_settings_group';
    const SETTINGS_PAGE          = 'hivekit-eapc-settings';
    const DEFAULT_CACHE_TTL      = 3600;

    private static $instance = null;

    /**
     * Hook suffix of the settings screen, captured from add_menu_page().
     *
     * Comparing against this rather than a guessed "toplevel_page_..."
     * string keeps the asset enqueue correct if the menu slug ever changes.
     *
     * @var string
     */
    private $settings_hook = '';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_filter( 'the_content', array( $this, 'replace_page_content' ), 20 );
        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_hivekit_eapc_clear_cache', array( $this, 'handle_clear_cache' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_hivekit_eapc_test_mapping', array( $this, 'handle_test_mapping' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
    }

    public static function activate() {
        /*
         * Run before anything is created: an install that predates the 1.3.0 rename still
         * holds its mappings under the old option name, and adding an empty option first
         * would hide them from the migration.
         */
        self::instance()->migrate_renamed_options();

        if ( false === get_option( self::OPTION_SETTINGS, false ) ) {
            add_option(
                self::OPTION_SETTINGS,
                array(
                    'mappings' => array(),
                )
            );
        }

        if ( false === get_option( self::OPTION_GENERATION, false ) ) {
            add_option( self::OPTION_GENERATION, 1 );
        }
    }

    public function add_settings_link( $links ) {
        $url = admin_url( 'admin.php?page=' . self::SETTINGS_PAGE );
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'hivekit-external-api-page-content' ) . '</a>' );
        return $links;
    }

    public function enqueue_admin_assets( $hook_suffix ) {
        if ( $this->settings_hook !== $hook_suffix ) {
            return;
        }

        $base_url = plugin_dir_url( __FILE__ );

        wp_enqueue_style( 'hivekit-eapc-admin', $base_url . 'assets/css/admin.css', array(), self::VERSION );
        wp_enqueue_script( 'hivekit-eapc-admin', $base_url . 'assets/js/admin.js', array(), self::VERSION, true );

        $settings = $this->get_settings();

        wp_localize_script(
            'hivekit-eapc-admin',
            'hivekitEapcAdmin',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'hivekit_eapc_test_mapping' ),
                'nextIndex' => count( $settings['mappings'] ),
                'i18n'      => array(
                    'enterUrl'      => __( 'Enter an API URL before testing.', 'hivekit-external-api-page-content' ),
                    'testFailed'    => __( 'API test failed.', 'hivekit-external-api-page-content' ),
                    'requestFailed' => __( 'The API test request could not be completed.', 'hivekit-external-api-page-content' ),
                    'noContentType' => __( 'Content-Type not provided', 'hivekit-external-api-page-content' ),
                    'interpretedAs' => __( 'interpreted as', 'hivekit-external-api-page-content' ),
                ),
            )
        );
    }

    public function register_settings_page() {
        $this->settings_hook = add_menu_page(
            __( 'External API Page Content', 'hivekit-external-api-page-content' ),
            __( 'External API Content', 'hivekit-external-api-page-content' ),
            'manage_options',
            self::SETTINGS_PAGE,
            array( $this, 'render_settings_page' ),
            'dashicons-rest-api'
        );
    }

    public function register_settings() {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_SETTINGS,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
                'default'           => array( 'mappings' => array() ),
            )
        );
    }

    public function sanitize_settings( $input ) {
        $previous = $this->get_settings();
        $input    = is_array( $input ) ? $input : array();
        $rows     = isset( $input['mappings'] ) && is_array( $input['mappings'] ) ? $input['mappings'] : array();
        $mappings = array();
        $page_ids = array();
        $ids      = array();

        foreach ( $rows as $row_index => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $page_id = isset( $row['page_id'] ) ? absint( $row['page_id'] ) : 0;
            $url     = isset( $row['api_url'] ) ? trim( (string) $row['api_url'] ) : '';
            $label   = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';

            // Ignore completely empty rows created by the repeatable UI.
            if ( 0 === $page_id && '' === $url && '' === $label ) {
                continue;
            }

            if ( '' !== $url ) {
                $url = esc_url_raw( $url, array( 'http', 'https' ) );
                if ( ! $url || ! wp_http_validate_url( $url ) ) {
                    add_settings_error(
                        self::OPTION_SETTINGS,
                        'hivekit_eapc_invalid_url_' . absint( $row_index ),
                        sprintf(
                            /* translators: %d: mapping row number */
                            __( 'Mapping %d has an invalid public HTTP/HTTPS API URL.', 'hivekit-external-api-page-content' ),
                            absint( $row_index ) + 1
                        )
                    );
                    $url = '';
                }
            }

            if ( $page_id > 0 && isset( $page_ids[ $page_id ] ) ) {
                add_settings_error(
                    self::OPTION_SETTINGS,
                    'hivekit_eapc_duplicate_page_' . $page_id,
                    sprintf(
                        /* translators: %d: WordPress page ID */
                        __( 'Page ID %d is mapped more than once. Only the first mapping was saved.', 'hivekit-external-api-page-content' ),
                        $page_id
                    )
                );
                continue;
            }

            if ( $page_id > 0 ) {
                $page_ids[ $page_id ] = true;
            }

            $format = isset( $row['format'] ) ? strtolower( (string) $row['format'] ) : 'auto';
            $format = in_array( $format, array( 'auto', 'html', 'markdown' ), true ) ? $format : 'auto';

            $ttl = isset( $row['cache_ttl'] ) ? absint( $row['cache_ttl'] ) : self::DEFAULT_CACHE_TTL;
            $ttl = min( $ttl, WEEK_IN_SECONDS );

            $headers = isset( $row['headers'] ) ? $this->sanitize_headers_text( $row['headers'], $row_index ) : '';

            $bearer_constant = isset( $row['bearer_constant'] ) ? trim( (string) $row['bearer_constant'] ) : '';
            if ( '' !== $bearer_constant && ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $bearer_constant ) ) {
                add_settings_error(
                    self::OPTION_SETTINGS,
                    'hivekit_eapc_invalid_constant_' . absint( $row_index ),
                    sprintf(
                        /* translators: %d: mapping row number */
                        __( 'Mapping %d has an invalid Bearer token constant name. It was cleared.', 'hivekit-external-api-page-content' ),
                        absint( $row_index ) + 1
                    )
                );
                $bearer_constant = '';
            }

            $id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
            if ( '' === $id || isset( $ids[ $id ] ) ) {
                $id = sanitize_key( wp_generate_uuid4() );
            }
            $ids[ $id ] = true;

            $mappings[] = array(
                'id'              => $id,
                'label'           => $label,
                'page_id'         => $page_id,
                'api_url'         => $url,
                'format'          => $format,
                'cache_ttl'       => $ttl,
                'headers'         => $headers,
                'bearer_constant' => $bearer_constant,
                'enabled'         => ! empty( $row['enabled'] ) ? 1 : 0,
            );
        }

        $output = array( 'mappings' => $mappings );

        $this->delete_removed_mapping_fallbacks( $previous, $output );

        if ( wp_json_encode( $previous ) !== wp_json_encode( $output ) ) {
            $this->bump_cache_generation();
        }

        return $output;
    }

    private function get_settings() {
        $this->migrate_renamed_options();

        $settings = get_option( self::OPTION_SETTINGS, array() );
        $settings = is_array( $settings ) ? $settings : array();

        // Migrate version 1.0 fixed-slot settings on first read after upgrade.
        if ( ! isset( $settings['mappings'] ) ) {
            $settings = $this->migrate_legacy_settings( $settings );
            update_option( self::OPTION_SETTINGS, $settings, false );
            $this->bump_cache_generation();
        }

        if ( ! isset( $settings['mappings'] ) || ! is_array( $settings['mappings'] ) ) {
            $settings['mappings'] = array();
        }

        return $settings;
    }

    /**
     * Carries settings over from the option names used before the 1.3.0 rename.
     *
     * The plugin is renamed to HiveKit External API Page Content in 1.3.0, which moved every
     * option name from the eapc_ prefix to hivekit_eapc_. Without this an install that
     * already had mappings would come back with none, and would quietly stop replacing its
     * pages. Safe to delete once no pre-rename install is left.
     */
    private function migrate_renamed_options() {
        if ( false !== get_option( self::OPTION_SETTINGS, false ) ) {
            return;
        }

        $legacy = get_option( 'eapc_settings', false );

        if ( false === $legacy ) {
            return;
        }

        update_option( self::OPTION_SETTINGS, $legacy, false );
        delete_option( 'eapc_settings' );

        $generation = get_option( 'eapc_cache_generation', false );

        if ( false !== $generation ) {
            update_option( self::OPTION_GENERATION, $generation, false );
            delete_option( 'eapc_cache_generation' );
        }

        /*
         * Cached responses are keyed by the old prefix, so they can never be read again.
         * Drop them instead of leaving them in wp_options.
         */
        $legacy_index = get_option( 'eapc_transient_index', array() );

        if ( is_array( $legacy_index ) ) {
            foreach ( array_keys( $legacy_index ) as $legacy_cache_key ) {
                delete_transient( $legacy_cache_key );
            }
        }

        delete_option( 'eapc_transient_index' );
    }

    private function migrate_legacy_settings( $legacy ) {
        $mappings = array();
        $ttl      = isset( $legacy['cache_ttl'] ) ? min( absint( $legacy['cache_ttl'] ), WEEK_IN_SECONDS ) : self::DEFAULT_CACHE_TTL;

        // Version 1.0 stored fixed slots as <slot>_page_id, <slot>_api_url and <slot>_format.
        // Discover such slots generically so no page purpose is special-cased here.
        foreach ( $legacy as $key => $value ) {
            if ( ! is_string( $key ) || ! preg_match( '/^(.+)_page_id$/', $key, $matches ) ) {
                continue;
            }

            $slot       = sanitize_key( $matches[1] );
            $page_key   = $slot . '_page_id';
            $url_key    = $slot . '_api_url';
            $format_key = $slot . '_format';
            $page_id    = isset( $legacy[ $page_key ] ) ? absint( $legacy[ $page_key ] ) : 0;
            $url        = isset( $legacy[ $url_key ] ) ? trim( (string) $legacy[ $url_key ] ) : '';

            if ( 0 === $page_id && '' === $url ) {
                continue;
            }

            $format = isset( $legacy[ $format_key ] ) ? strtolower( (string) $legacy[ $format_key ] ) : 'auto';
            $format = in_array( $format, array( 'auto', 'html', 'markdown' ), true ) ? $format : 'auto';

            $legacy_constant = 'HIVEKIT_EAPC_' . strtoupper( str_replace( '-', '_', $slot ) ) . '_API_BEARER_TOKEN';

            $mappings[] = array(
                'id'              => sanitize_key( wp_generate_uuid4() ),
                'label'           => ucwords( str_replace( array( '-', '_' ), ' ', $slot ) ),
                'page_id'         => $page_id,
                'api_url'         => $url,
                'format'          => $format,
                'cache_ttl'       => $ttl,
                'headers'         => '',
                'bearer_constant' => defined( $legacy_constant ) ? $legacy_constant : '',
                'enabled'         => 1,
            );
        }

        return array( 'mappings' => $mappings );
    }

    public function replace_page_content( $content ) {
        if ( is_admin() || ! is_singular( 'page' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        global $post;
        if ( ! $post instanceof WP_Post ) {
            return $content;
        }

        $settings = $this->get_settings();
        $mapping  = $this->mapping_for_page( (int) $post->ID, $settings );

        $should_replace = (bool) $mapping;
        /**
         * Filters whether the current page body should be replaced.
         *
         * @param bool        $should_replace Default decision.
         * @param int         $page_id        Current page ID.
         * @param array|false $mapping        Mapping configuration, or false.
         */
        $should_replace = apply_filters( 'hivekit_eapc_should_replace_content', $should_replace, (int) $post->ID, $mapping );

        if ( ! $should_replace || ! $mapping ) {
            return $content;
        }

        $external = $this->get_external_content( $mapping, (int) $post->ID );
        if ( is_wp_error( $external ) ) {
            $this->log_error( $external, $mapping, (int) $post->ID );
            return $content;
        }

        return $external;
    }

    private function mapping_for_page( $page_id, $settings ) {
        foreach ( $settings['mappings'] as $mapping ) {
            if (
                is_array( $mapping ) &&
                ! empty( $mapping['enabled'] ) &&
                $page_id === (int) $mapping['page_id'] &&
                ! empty( $mapping['api_url'] )
            ) {
                return $mapping;
            }
        }

        return false;
    }

    private function get_external_content( $mapping, $page_id ) {
        $mapping_id = isset( $mapping['id'] ) ? (string) $mapping['id'] : '';
        $format     = isset( $mapping['format'] ) ? (string) $mapping['format'] : 'auto';
        $ttl        = isset( $mapping['cache_ttl'] ) ? (int) $mapping['cache_ttl'] : self::DEFAULT_CACHE_TTL;
        $headers    = isset( $mapping['headers'] ) ? (string) $mapping['headers'] : '';
        $bearer     = $this->bearer_token_for_mapping( $mapping );
        $signature  = hash(
            'sha256',
            wp_json_encode(
                array(
                    'page_id' => $page_id,
                    'url'     => $mapping['api_url'],
                    'format'  => $format,
                    'headers' => $headers,
                    'bearer'  => $bearer,
                )
            )
        );
        $generation = max( 1, (int) get_option( self::OPTION_GENERATION, 1 ) );
        $cache_key  = 'hivekit_eapc_' . $generation . '_' . substr( $signature, 0, 24 );

        if ( $ttl > 0 ) {
            $cached = get_transient( $cache_key );
            if ( is_string( $cached ) ) {
                return $cached;
            }
        }

        $request_args = $this->build_request_args( $mapping, $page_id );

        $response = wp_safe_remote_get( $mapping['api_url'], $request_args );

        if ( is_wp_error( $response ) ) {
            return $this->fallback_or_error( $mapping_id, $signature, $response );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            return $this->fallback_or_error(
                $mapping_id,
                $signature,
                new WP_Error( 'hivekit_eapc_http_status', sprintf( 'External API returned HTTP %d.', $status ) )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( ! is_string( $body ) || '' === trim( $body ) ) {
            return $this->fallback_or_error(
                $mapping_id,
                $signature,
                new WP_Error( 'hivekit_eapc_empty_body', 'External API returned an empty response body.' )
            );
        }

        $content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
        $resolved     = $this->resolve_format( $format, $content_type, $body );
        $rendered     = $this->render_payload( $body, $resolved );

        /**
         * Filters final sanitized HTML before it replaces the WordPress page content.
         *
         * @param string $rendered   Final HTML.
         * @param string $body       Raw API response body.
         * @param string $format     html or markdown.
         * @param int    $page_id    WordPress page ID.
         * @param string $mapping_id Stable mapping ID.
         * @param array  $mapping    Full mapping configuration.
         */
        $rendered = apply_filters( 'hivekit_eapc_rendered_content', $rendered, $body, $resolved, $page_id, $mapping_id, $mapping );

        if ( $ttl > 0 ) {
            set_transient( $cache_key, $rendered, $ttl );
            $this->remember_transient_key( $cache_key );
        }

        update_option(
            $this->last_good_option_name( $mapping_id ),
            array(
                'signature' => $signature,
                'html'      => $rendered,
                'fetched'   => time(),
            ),
            false
        );

        return $rendered;
    }

    private function build_request_args( $mapping, $page_id ) {
        $mapping_id = isset( $mapping['id'] ) ? (string) $mapping['id'] : '';
        $request_args = array(
            'timeout'             => 8,
            'redirection'         => 3,
            'reject_unsafe_urls'  => true,
            'limit_response_size' => 2 * MB_IN_BYTES,
            'headers'             => array(
                'Accept'     => 'text/html, text/markdown, text/plain;q=0.9, */*;q=0.1',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
            ),
        );

        $bearer = $this->bearer_token_for_mapping( $mapping );
        if ( '' !== $bearer ) {
            $this->set_request_header( $request_args['headers'], 'Authorization', 'Bearer ' . $bearer );
        }

        foreach ( $this->request_headers_for_mapping( $mapping ) as $name => $value ) {
            // Explicit per-mapping headers take precedence over plugin defaults and Bearer auth.
            $this->set_request_header( $request_args['headers'], $name, $value );
        }

        /**
         * Filters HTTP request arguments before fetching the remote page body.
         *
         * This filter is also applied to requests made by the Test API button.
         *
         * @param array  $request_args HTTP API arguments.
         * @param string $url          Endpoint URL.
         * @param int    $page_id      WordPress page ID.
         * @param string $mapping_id   Stable mapping ID.
         * @param array  $mapping      Full mapping configuration.
         */
        return apply_filters( 'hivekit_eapc_request_args', $request_args, $mapping['api_url'], $page_id, $mapping_id, $mapping );
    }

    private function sanitize_headers_text( $raw, $row_index = null ) {
        $raw       = is_string( $raw ) ? $raw : '';
        $lines     = preg_split( '/\r\n|\r|\n/', $raw );
        $sanitized = array();
        $invalid   = 0;

        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line ) {
                continue;
            }

            $colon = strpos( $line, ':' );
            if ( false === $colon ) {
                ++$invalid;
                continue;
            }

            $name  = trim( substr( $line, 0, $colon ) );
            $value = trim( substr( $line, $colon + 1 ) );

            if ( '' === $name || ! preg_match( "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name ) ) {
                ++$invalid;
                continue;
            }

            // Prevent CR/LF/control-character header injection while preserving normal punctuation.
            $value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
            $sanitized[] = $name . ': ' . $value;
        }

        if ( $invalid > 0 && null !== $row_index ) {
            add_settings_error(
                self::OPTION_SETTINGS,
                'hivekit_eapc_invalid_headers_' . absint( $row_index ),
                sprintf(
                    /* translators: 1: mapping row number, 2: number of ignored malformed header lines */
                    _n(
                        'Mapping %1$d contained %2$d malformed request-header line; it was ignored.',
                        'Mapping %1$d contained %2$d malformed request-header lines; they were ignored.',
                        $invalid,
                        'hivekit-external-api-page-content'
                    ),
                    absint( $row_index ) + 1,
                    $invalid
                )
            );
        }

        return implode( "\n", $sanitized );
    }

    private function request_headers_for_mapping( $mapping ) {
        $raw     = isset( $mapping['headers'] ) ? (string) $mapping['headers'] : '';
        $raw     = $this->sanitize_headers_text( $raw );
        $headers = array();

        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
            if ( '' === trim( $line ) ) {
                continue;
            }

            $colon = strpos( $line, ':' );
            if ( false === $colon ) {
                continue;
            }

            $name  = trim( substr( $line, 0, $colon ) );
            $value = trim( substr( $line, $colon + 1 ) );
            $headers[ $name ] = $value;
        }

        return $headers;
    }

    private function set_request_header( &$headers, $name, $value ) {
        foreach ( array_keys( $headers ) as $existing_name ) {
            if ( 0 === strcasecmp( (string) $existing_name, (string) $name ) ) {
                unset( $headers[ $existing_name ] );
            }
        }

        $headers[ $name ] = $value;
    }

    private function bearer_token_for_mapping( $mapping ) {
        $constant_name = isset( $mapping['bearer_constant'] ) ? trim( (string) $mapping['bearer_constant'] ) : '';

        if ( '' !== $constant_name ) {
            if ( defined( $constant_name ) && constant( $constant_name ) ) {
                return trim( (string) constant( $constant_name ) );
            }
            return '';
        }

        if ( defined( 'HIVEKIT_EAPC_API_BEARER_TOKEN' ) && HIVEKIT_EAPC_API_BEARER_TOKEN ) {
            return trim( (string) HIVEKIT_EAPC_API_BEARER_TOKEN );
        }

        return '';
    }

    private function fallback_or_error( $mapping_id, $signature, $error ) {
        $last_good = get_option( $this->last_good_option_name( $mapping_id ), array() );

        if (
            is_array( $last_good ) &&
            isset( $last_good['signature'], $last_good['html'] ) &&
            hash_equals( (string) $last_good['signature'], (string) $signature ) &&
            is_string( $last_good['html'] )
        ) {
            return $last_good['html'];
        }

        return $error;
    }

    private function last_good_option_name( $mapping_id ) {
        return 'hivekit_eapc_last_good_' . md5( (string) $mapping_id );
    }

    private function delete_removed_mapping_fallbacks( $old_settings, $new_settings ) {
        $old_ids = $this->mapping_ids( $old_settings );
        $new_ids = $this->mapping_ids( $new_settings );

        foreach ( array_diff( $old_ids, $new_ids ) as $removed_id ) {
            delete_option( $this->last_good_option_name( $removed_id ) );
        }
    }

    private function mapping_ids( $settings ) {
        $ids = array();

        if ( ! is_array( $settings ) || empty( $settings['mappings'] ) || ! is_array( $settings['mappings'] ) ) {
            return $ids;
        }

        foreach ( $settings['mappings'] as $mapping ) {
            if ( is_array( $mapping ) && ! empty( $mapping['id'] ) ) {
                $ids[] = (string) $mapping['id'];
            }
        }

        return $ids;
    }

    private function delete_all_last_good_options() {
        global $wpdb;

        $like = $wpdb->esc_like( 'hivekit_eapc_last_good_' ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup of plugin-owned rows; there is no cache to invalidate.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
    }

    private function resolve_format( $configured, $content_type, $body ) {
        if ( in_array( $configured, array( 'html', 'markdown' ), true ) ) {
            return $configured;
        }

        $content_type = strtolower( $content_type );
        if ( false !== strpos( $content_type, 'markdown' ) ) {
            return 'markdown';
        }
        if ( false !== strpos( $content_type, 'html' ) ) {
            return 'html';
        }

        if ( preg_match( '/<!doctype\s+html|<\s*(html|body|article|section|div|p|h[1-6]|ul|ol|table)\b/i', $body ) ) {
            return 'html';
        }

        return 'markdown';
    }

    private function render_payload( $body, $format ) {
        if ( 'html' === $format ) {
            $allowed = wp_kses_allowed_html( 'post' );
            /**
             * Filters allowed HTML tags/attributes for HTML API responses.
             *
             * @param array $allowed WordPress post-context KSES rules.
             */
            $allowed = apply_filters( 'hivekit_eapc_allowed_html', $allowed );
            return wp_kses( $body, $allowed );
        }

        $html = HiveKit_EAPC_Markdown::render( $body );
        $html = wp_kses_post( $html );

        /**
         * Filters sanitized HTML produced from Markdown.
         *
         * @param string $html Sanitized rendered Markdown.
         * @param string $body Raw Markdown.
         */
        return apply_filters( 'hivekit_eapc_markdown_html', $html, $body );
    }

    private function log_error( $error, $mapping, $page_id ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostics, gated by WP_DEBUG.
            error_log(
                sprintf(
                    '[External API Page Content] page=%d mapping=%s url=%s error=%s',
                    $page_id,
                    isset( $mapping['id'] ) ? $mapping['id'] : '',
                    isset( $mapping['api_url'] ) ? $mapping['api_url'] : '',
                    $error->get_error_message()
                )
            );
        }
    }

    private function bump_cache_generation() {
        // Drop the previous generation's transients so they cannot leak in wp_options.
        $this->flush_indexed_transients();

        $generation = max( 1, (int) get_option( self::OPTION_GENERATION, 1 ) );
        update_option( self::OPTION_GENERATION, $generation + 1, false );
    }

    /**
     * Records a cache transient so it can be removed when settings change.
     *
     * @param string $cache_key Transient key.
     */
    private function remember_transient_key( $cache_key ) {
        $index = $this->get_transient_index();

        if ( ! isset( $index[ $cache_key ] ) ) {
            $index[ $cache_key ] = time();
            update_option( self::OPTION_TRANSIENT_INDEX, $index, false );
        }
    }

    private function get_transient_index() {
        $index = get_option( self::OPTION_TRANSIENT_INDEX, array() );

        return is_array( $index ) ? $index : array();
    }

    private function flush_indexed_transients() {
        $index = $this->get_transient_index();

        if ( empty( $index ) ) {
            return;
        }

        foreach ( array_keys( $index ) as $cache_key ) {
            delete_transient( $cache_key );
        }

        delete_option( self::OPTION_TRANSIENT_INDEX );
    }

    public function handle_test_mapping() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to test API mappings.', 'hivekit-external-api-page-content' ) ), 403 );
        }

        check_ajax_referer( 'hivekit_eapc_test_mapping', 'nonce' );

        $api_url = isset( $_POST['api_url'] ) ? sanitize_text_field( wp_unslash( $_POST['api_url'] ) ) : '';
        $api_url = esc_url_raw( trim( $api_url ), array( 'http', 'https' ) );

        if ( ! $api_url || ! wp_http_validate_url( $api_url ) ) {
            wp_send_json_error( array( 'message' => __( 'Enter a valid public HTTP/HTTPS API URL before testing.', 'hivekit-external-api-page-content' ) ), 400 );
        }

        $format = isset( $_POST['format'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['format'] ) ) ) : 'auto';
        $format = in_array( $format, array( 'auto', 'html', 'markdown' ), true ) ? $format : 'auto';

        $headers = isset( $_POST['headers'] ) ? sanitize_textarea_field( wp_unslash( $_POST['headers'] ) ) : '';
        $headers = $this->sanitize_headers_text( $headers );

        $bearer_constant = isset( $_POST['bearer_constant'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['bearer_constant'] ) ) ) : '';
        if ( '' !== $bearer_constant && ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $bearer_constant ) ) {
            wp_send_json_error( array( 'message' => __( 'The Bearer token constant name is invalid.', 'hivekit-external-api-page-content' ) ), 400 );
        }

        $mapping = array(
            'id'              => isset( $_POST['mapping_id'] ) ? sanitize_key( wp_unslash( $_POST['mapping_id'] ) ) : '',
            'label'           => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
            'page_id'         => isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0,
            'api_url'         => $api_url,
            'format'          => $format,
            'cache_ttl'       => isset( $_POST['cache_ttl'] ) ? min( absint( $_POST['cache_ttl'] ), WEEK_IN_SECONDS ) : self::DEFAULT_CACHE_TTL,
            'headers'         => $headers,
            'bearer_constant' => $bearer_constant,
            'enabled'         => 1,
        );

        // Make tests of unsaved mappings identifiable to request-argument filters.
        if ( '' === $mapping['id'] ) {
            $mapping['id'] = 'test';
        }

        $request_args = $this->build_request_args( $mapping, (int) $mapping['page_id'] );
        $response     = wp_safe_remote_get( $api_url, $request_args );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error(
                array(
                    'message' => $response->get_error_message(),
                    'code'    => $response->get_error_code(),
                ),
                502
            );
        }

        $status       = (int) wp_remote_retrieve_response_code( $response );
        $content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
        $body         = wp_remote_retrieve_body( $response );
        $body         = is_string( $body ) ? wp_check_invalid_utf8( $body, true ) : '';
        $resolved     = '' !== trim( $body ) ? $this->resolve_format( $format, $content_type, $body ) : '';

        wp_send_json_success(
            array(
                'status'          => $status,
                'http_ok'         => $status >= 200 && $status < 300,
                'content_type'    => $content_type,
                'bytes'           => strlen( $body ),
                'resolved_format' => $resolved,
                'payload'         => $body,
            )
        );
    }

    public function handle_clear_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'hivekit-external-api-page-content' ) );
        }

        check_admin_referer( 'hivekit_eapc_clear_cache' );
        $this->bump_cache_generation();

        // Removes all current and legacy last-known-good fallback options.
        $this->delete_all_last_good_options();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'               => self::SETTINGS_PAGE,
                    'hivekit_eapc_cache_cleared' => '1',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->get_settings();
        $mappings = $settings['mappings'];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'External API Page Content', 'hivekit-external-api-page-content' ); ?></h1>

            <?php
            /*
             * Notices are normally printed by wp-admin/options-head.php, which WordPress only
             * loads when the parent file is options-general.php. This screen sits in its own
             * top-level menu, so the messages registered by sanitize_settings() have to be
             * printed here instead. Reading the errors first also tells us whether the save
             * that just happened succeeded.
             */
            $hivekit_eapc_notices = get_settings_errors();

            if ( isset( $_GET['settings-updated'] ) && empty( $hivekit_eapc_notices ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                add_settings_error(
                    self::OPTION_SETTINGS,
                    'settings_updated',
                    __( 'Settings saved.', 'hivekit-external-api-page-content' ),
                    'success'
                );
            }

            settings_errors();
            ?>

            <?php if ( isset( $_GET['hivekit_eapc_cache_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'External content cache cleared.', 'hivekit-external-api-page-content' ); ?></p></div>
            <?php endif; ?>

            <p><?php esc_html_e( 'Add any number of page-to-API mappings. Each enabled mapping replaces the selected WordPress page body with the raw HTML or Markdown returned by its endpoint.', 'hivekit-external-api-page-content' ); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields( self::SETTINGS_GROUP ); ?>

                <div id="hivekit-eapc-mappings">
                    <?php foreach ( $mappings as $index => $mapping ) : ?>
                        <?php $this->render_mapping_row( $mapping, $index ); ?>
                    <?php endforeach; ?>
                </div>

                <p>
                    <button type="button" class="button" id="hivekit-eapc-add-mapping"><?php esc_html_e( 'Add mapping', 'hivekit-external-api-page-content' ); ?></button>
                </p>

                <?php submit_button(); ?>
            </form>

            <script type="text/html" id="hivekit-eapc-mapping-template">
                <?php
                $this->render_mapping_row(
                    array(
                        'id'              => '',
                        'label'           => '',
                        'page_id'         => 0,
                        'api_url'         => '',
                        'format'          => 'auto',
                        'cache_ttl'       => self::DEFAULT_CACHE_TTL,
                        'headers'         => '',
                        'bearer_constant' => '',
                        'enabled'         => 1,
                    ),
                    '__INDEX__'
                );
                ?>
            </script>

            <hr>
            <h2><?php esc_html_e( 'Cache', 'hivekit-external-api-page-content' ); ?></h2>
            <p><?php esc_html_e( 'Clear all current mapping caches and last-known-good API content. The normal WordPress editor content remains untouched and is used when no API content has ever been retrieved successfully.', 'hivekit-external-api-page-content' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="hivekit_eapc_clear_cache">
                <?php wp_nonce_field( 'hivekit_eapc_clear_cache' ); ?>
                <?php submit_button( __( 'Clear external content cache', 'hivekit-external-api-page-content' ), 'secondary', 'submit', false ); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Optional Bearer authentication', 'hivekit-external-api-page-content' ); ?></h2>
            <p><?php esc_html_e( 'Secrets are not stored in the plugin settings. Define a token as a PHP constant in wp-config.php, then enter that constant name in the relevant mapping. If a mapping has no constant name, HIVEKIT_EAPC_API_BEARER_TOKEN is used when defined.', 'hivekit-external-api-page-content' ); ?></p>
            <pre><code>define( 'HIVEKIT_EAPC_API_BEARER_TOKEN', 'global-token' );
define( 'MY_LEGAL_API_TOKEN', 'mapping-specific-token' );</code></pre>
        </div>
        <?php
    }

    /**
     * Renders the page selector for a mapping.
     *
     * Kept out of the template so escaping review stays simple: wp_dropdown_pages()
     * escapes the name/id attributes it generates itself.
     *
     * @param string $base      Field name prefix for the settings array.
     * @param string $id_prefix HTML id prefix.
     * @param int    $page_id   Currently selected page ID.
     */
    private function render_page_dropdown( $base, $id_prefix, $page_id ) {
        //
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        // wp_dropdown_pages() builds and escapes the select element itself; nothing here is echoed
        // directly. $base and $id_prefix are internally constructed, $page_id is an integer.
        wp_dropdown_pages(
            array(
                'name'              => $base . '[page_id]',
                'id'                => $id_prefix . 'page_id',
                'selected'          => $page_id,
                'show_option_none'  => __( '— Select a page —', 'hivekit-external-api-page-content' ),
                'option_none_value' => '0',
            )
        );
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function render_mapping_row( $mapping, $index ) {
        $base        = self::OPTION_SETTINGS . '[mappings][' . $index . ']';
        $id_prefix   = 'hivekit_eapc_mapping_' . $index . '_';
        $label       = isset( $mapping['label'] ) ? (string) $mapping['label'] : '';
        $mapping_id  = isset( $mapping['id'] ) ? (string) $mapping['id'] : '';
        $page_id     = isset( $mapping['page_id'] ) ? (int) $mapping['page_id'] : 0;
        $api_url     = isset( $mapping['api_url'] ) ? (string) $mapping['api_url'] : '';
        $format      = isset( $mapping['format'] ) ? (string) $mapping['format'] : 'auto';
        $cache_ttl   = isset( $mapping['cache_ttl'] ) ? (int) $mapping['cache_ttl'] : self::DEFAULT_CACHE_TTL;
        $headers     = isset( $mapping['headers'] ) ? (string) $mapping['headers'] : '';
        $bearer_name = isset( $mapping['bearer_constant'] ) ? (string) $mapping['bearer_constant'] : '';
        $enabled     = ! empty( $mapping['enabled'] );
        ?>
        <section class="hivekit-eapc-mapping">
            <div class="hivekit-eapc-mapping__header">
                <h2><?php esc_html_e( 'Mapping', 'hivekit-external-api-page-content' ); ?> <span class="hivekit-eapc-mapping-number"></span></h2>
                <button type="button" class="button-link-delete hivekit-eapc-remove-mapping"><?php esc_html_e( 'Remove', 'hivekit-external-api-page-content' ); ?></button>
            </div>

            <input type="hidden" name="<?php echo esc_attr( $base ); ?>[id]" value="<?php echo esc_attr( $mapping_id ); ?>">

            <div class="hivekit-eapc-mapping__grid">
                <label for="<?php echo esc_attr( $id_prefix ); ?>label"><?php esc_html_e( 'Label', 'hivekit-external-api-page-content' ); ?></label>
                <div>
                    <input id="<?php echo esc_attr( $id_prefix ); ?>label" name="<?php echo esc_attr( $base ); ?>[label]" type="text" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php echo esc_attr__( 'e.g. Policy page', 'hivekit-external-api-page-content' ); ?>">
                    <p class="hivekit-eapc-mapping__description"><?php esc_html_e( 'Administrative label only; it is not shown on the page.', 'hivekit-external-api-page-content' ); ?></p>
                </div>

                <label for="<?php echo esc_attr( $id_prefix ); ?>page_id"><?php esc_html_e( 'WordPress page', 'hivekit-external-api-page-content' ); ?></label>
                <div>
                    <?php $this->render_page_dropdown( $base, $id_prefix, $page_id ); ?>
                </div>

                <label for="<?php echo esc_attr( $id_prefix ); ?>api_url"><?php esc_html_e( 'API URL', 'hivekit-external-api-page-content' ); ?></label>
                <div>
                    <input id="<?php echo esc_attr( $id_prefix ); ?>api_url" name="<?php echo esc_attr( $base ); ?>[api_url]" type="url" class="code" placeholder="https://api.example.com/content/page" value="<?php echo esc_attr( $api_url ); ?>">
                </div>

                <label for="<?php echo esc_attr( $id_prefix ); ?>headers"><?php esc_html_e( 'Request headers', 'hivekit-external-api-page-content' ); ?></label>
                <div>
                    <textarea id="<?php echo esc_attr( $id_prefix ); ?>headers" name="<?php echo esc_attr( $base ); ?>[headers]" class="code hivekit-eapc-request-headers" rows="5" placeholder="X-API-Key: abc123&#10;X-Tenant: example"><?php echo esc_textarea( $headers ); ?></textarea>
                    <p class="hivekit-eapc-mapping__description"><?php esc_html_e( 'Optional. Enter one HTTP header per line as Header-Name: value. These values are stored in the WordPress database. Explicit headers override plugin defaults and the Bearer-token field when names conflict.', 'hivekit-external-api-page-content' ); ?></p>
                </div>

                <label for="<?php echo esc_attr( $id_prefix ); ?>format"><?php esc_html_e( 'Payload format', 'hivekit-external-api-page-content' ); ?></label>
                <div>
                    <select id="<?php echo esc_attr( $id_prefix ); ?>format" name="<?php echo esc_attr( $base ); ?>[format]">
                        <option value="auto" <?php selected( $format, 'auto' ); ?>><?php esc_html_e( 'Auto-detect', 'hivekit-external-api-page-content' ); ?></option>
                        <option value="html" <?php selected( $format, 'html' ); ?>><?php esc_html_e( 'HTML', 'hivekit-external-api-page-content' ); ?></option>
                        <option value="markdown" <?php selected( $format, 'markdown' ); ?>><?php esc_html_e( 'Markdown', 'hivekit-external-api-page-content' ); ?></option>
                    </select>
                </div>

                <label for="<?php echo esc_attr( $id_prefix ); ?>cache_ttl"><?php esc_html_e( 'Cache TTL (seconds)', 'hivekit-external-api-page-content' ); ?></label>
                <div>
                    <input id="<?php echo esc_attr( $id_prefix ); ?>cache_ttl" name="<?php echo esc_attr( $base ); ?>[cache_ttl]" type="number" min="0" max="<?php echo esc_attr( WEEK_IN_SECONDS ); ?>" step="1" value="<?php echo esc_attr( $cache_ttl ); ?>" class="small-text">
                    <p class="hivekit-eapc-mapping__description"><?php esc_html_e( 'Default: 3600. Use 0 to disable normal caching; last-known-good fallback is still retained.', 'hivekit-external-api-page-content' ); ?></p>
                </div>

                <label for="<?php echo esc_attr( $id_prefix ); ?>bearer_constant"><?php esc_html_e( 'Bearer token constant', 'hivekit-external-api-page-content' ); ?></label>
                <div>
                    <input id="<?php echo esc_attr( $id_prefix ); ?>bearer_constant" name="<?php echo esc_attr( $base ); ?>[bearer_constant]" type="text" class="code" value="<?php echo esc_attr( $bearer_name ); ?>" placeholder="MY_API_BEARER_TOKEN">
                    <p class="hivekit-eapc-mapping__description"><?php esc_html_e( 'Optional wp-config.php constant name. Leave blank for HIVEKIT_EAPC_API_BEARER_TOKEN or no authentication.', 'hivekit-external-api-page-content' ); ?></p>
                </div>

                <span><?php esc_html_e( 'Status', 'hivekit-external-api-page-content' ); ?></span>
                <div>
                    <input type="hidden" name="<?php echo esc_attr( $base ); ?>[enabled]" value="0">
                    <label>
                        <input name="<?php echo esc_attr( $base ); ?>[enabled]" type="checkbox" value="1" <?php checked( $enabled ); ?>>
                        <?php esc_html_e( 'Enabled', 'hivekit-external-api-page-content' ); ?>
                    </label>
                </div>

                <span><?php esc_html_e( 'API test', 'hivekit-external-api-page-content' ); ?></span>
                <div>
                    <div class="hivekit-eapc-test-controls">
                        <button type="button" class="button hivekit-eapc-test-mapping"><?php esc_html_e( 'Test API', 'hivekit-external-api-page-content' ); ?></button>
                        <span class="spinner hivekit-eapc-test-spinner" aria-hidden="true"></span>
                    </div>
                    <p class="hivekit-eapc-mapping__description"><?php esc_html_e( 'Fetches the endpoint using the values currently shown above. It does not save settings or update the content cache.', 'hivekit-external-api-page-content' ); ?></p>
                </div>

                <div class="hivekit-eapc-test-result" hidden>
                    <p class="hivekit-eapc-test-result__meta"></p>
                    <label class="screen-reader-text" for="<?php echo esc_attr( $id_prefix ); ?>test_payload"><?php esc_html_e( 'Raw API response payload', 'hivekit-external-api-page-content' ); ?></label>
                    <textarea id="<?php echo esc_attr( $id_prefix ); ?>test_payload" class="large-text code hivekit-eapc-test-payload" rows="14" readonly></textarea>
                </div>
            </div>
        </section>
        <?php
    }
}

register_activation_hook( __FILE__, array( 'HiveKit_EAPC_Plugin', 'activate' ) );
HiveKit_EAPC_Plugin::instance();
