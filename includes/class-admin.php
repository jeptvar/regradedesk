<?php
namespace SecondShelf;

defined( 'ABSPATH' ) || exit;

final class Admin {
    public function register(): void {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'admin_post_second_shelf', array( $this, 'handle' ) );
        add_action( 'add_meta_boxes_product', array( $this, 'product_box' ) );
        add_filter( 'wp_insert_post_data', array( $this, 'guard_incomplete_draft' ), 10, 2 );
    }

    public function menu(): void {
        add_submenu_page( 'woocommerce', __( 'Second Shelf', 'second-shelf' ), __( 'Second Shelf', 'second-shelf' ), 'manage_woocommerce', 'second-shelf', array( $this, 'page' ) );
    }

    public function assets( string $hook ): void {
        if ( $hook !== 'woocommerce_page_second-shelf' ) {
            return;
        }
        wp_enqueue_style( 'second-shelf', plugins_url( 'assets/admin.css', SECOND_SHELF_FILE ), array(), '0.1.2' );
        wp_enqueue_media();
        wp_enqueue_script( 'second-shelf', plugins_url( 'assets/admin.js', SECOND_SHELF_FILE ), array( 'jquery', 'media-views' ), '0.1.2', true );
        wp_localize_script( 'second-shelf', 'secondShelf', array(
            'title' => __( 'Choose public resale photos', 'second-shelf' ),
            'button' => __( 'Use these photos', 'second-shelf' ),
            'selected' => __( 'photos selected', 'second-shelf' ),
            'unsaved' => __( 'Save your inspection changes before creating the draft.', 'second-shelf' ),
        ) );
    }

    private static function url( array $args = array() ): string {
        return add_query_arg( array_merge( array( 'page' => 'second-shelf' ), $args ), admin_url( 'admin.php' ) );
    }

    private static function scalar( array $data, string $key, string $default = '' ): string {
        return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : $default;
    }

    public function handle(): void {
        try {
            Workbench::authorize();
            if ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) !== 'POST' ) {
                throw new \RuntimeException( __( 'Use the inspection form to make changes.', 'second-shelf' ) );
            }
            check_admin_referer( 'second_shelf_action' );
            $data = wp_unslash( $_POST );
            foreach ( $data as $value ) {
                if ( ! is_scalar( $value ) ) {
                    throw new \RuntimeException( __( 'Invalid form data.', 'second-shelf' ) );
                }
            }
            $action = self::scalar( $data, 'operation' );
            $id = absint( self::scalar( $data, 'unit_id' ) );
            if ( $action === 'receive' ) {
                if ( self::scalar( $data, 'received_confirmed' ) !== '1' ) {
                    throw new \RuntimeException( __( 'Confirm this physical unit has arrived and is separate from sellable stock.', 'second-shelf' ) );
                }
                $id = Workbench::receive( absint( self::scalar( $data, 'order_id' ) ), absint( self::scalar( $data, 'item_id' ) ), self::scalar( $data, 'receipt_key' ) );
            } elseif ( $action === 'save' ) {
                Workbench::save( $id, absint( self::scalar( $data, 'version' ) ), $data );
            } elseif ( $action === 'create' ) {
                Workbench::create_draft( $id, absint( self::scalar( $data, 'version' ) ) );
            } else {
                throw new \RuntimeException( __( 'Unknown action.', 'second-shelf' ) );
            }
            wp_safe_redirect( self::url( array( 'unit' => $id, 'saved' => '1' ) ) );
            exit;
        } catch ( \Throwable $error ) {
            wp_die( esc_html( $error->getMessage() ), esc_html__( 'Second Shelf', 'second-shelf' ), array( 'response' => 400, 'back_link' => true ) );
        }
    }

    public function guard_incomplete_draft( array $data, array $postarr ): array {
        if ( ( $data['post_type'] ?? '' ) !== 'product' || empty( $postarr['ID'] ) || ! in_array( $data['post_status'], array( 'publish', 'future', 'private' ), true ) ) {
            return $data;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom inspection table; publication must use the current committed state.
        $state = $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM %i WHERE product_id = %d', Workbench::table(), $postarr['ID'] ) );
        if ( $state && $state !== 'listed' ) {
            $data['post_status'] = 'draft';
        }
        return $data;
    }

    public function product_box( \WP_Post $post ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom inspection table; show the current reservation and recovery state.
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, state FROM %i WHERE product_id = %d', Workbench::table(), $post->ID ), ARRAY_A );
        if ( ! $row ) {
            return;
        }
        add_meta_box( 'second-shelf-origin', __( 'Second Shelf inspection', 'second-shelf' ), static function () use ( $row ) {
            echo '<p><a href="' . esc_url( self::url( array( 'unit' => $row['id'] ) ) ) . '">' . esc_html__( 'View original inspection', 'second-shelf' ) . '</a></p>';
            echo '<p>' . esc_html__( 'This product represents one returned unit. Keep its stock at one or zero, and review photos, shipping and tax settings before publishing.', 'second-shelf' ) . '</p>';
            if ( $row['state'] !== 'listed' ) {
                echo '<p><strong>' . esc_html__( 'Preparation is incomplete. Return to the inspection and retry before publishing.', 'second-shelf' ) . '</strong></p>';
            }
        }, 'product', 'side', 'high' );
    }

    private static function form( string $operation, int $id = 0, int $version = 0 ): void {
        echo '<form' . ( $operation === 'save' ? ' id="ss-inspection-form"' : '' ) . ' method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'second_shelf_action' );
        echo '<input type="hidden" name="action" value="second_shelf"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
        echo '<input type="hidden" name="unit_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="version" value="' . esc_attr( $version ) . '">';
    }

    private static function states(): array {
        return array( 'received' => __( 'Needs inspection', 'second-shelf' ), 'ready' => __( 'Ready for draft', 'second-shelf' ), 'building' => __( 'Preparation incomplete', 'second-shelf' ), 'listed' => __( 'Draft created', 'second-shelf' ), 'rejected' => __( 'Not for resale', 'second-shelf' ) );
    }

    public function page(): void {
        try {
            Workbench::authorize();
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation after capability checks; mutations use handle() and a nonce.
            $id = absint( self::scalar( $_GET, 'unit' ) );
            /* translators: %d: inspection identifier. */
            echo '<div class="wrap ss-wrap"><div class="ss-page-heading"><h1 class="wp-heading-inline">' . esc_html( $id ? sprintf( __( 'Inspection #%d', 'second-shelf' ), $id ) : __( 'Second Shelf', 'second-shelf' ) ) . '</h1>';
            echo '<a class="page-title-action" href="' . esc_url( $id ? self::url() : '#ss-receive' ) . '">' . esc_html( $id ? __( 'All inspections', 'second-shelf' ) : __( 'Receive return', 'second-shelf' ) ) . '</a></div><hr class="wp-header-end">';
            echo '<p class="ss-page-description">' . esc_html__( 'Inspect returned units and prepare resale listings for review.', 'second-shelf' ) . '</p>';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Displays a static notice only.
            if ( isset( $_GET['saved'] ) ) {
                echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Inspection saved.', 'second-shelf' ) . '</p></div>';
            }
            if ( $id ) {
                $this->detail( Workbench::get( $id ) );
            } else {
                $this->dashboard();
            }
            echo '</div>';
        } catch ( \Throwable $error ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $error->getMessage() ) . '</p></div>';
        }
    }

    private function dashboard(): void {
        require __DIR__ . '/views/dashboard.php';
    }

    private function detail( array $row ): void {
        require __DIR__ . '/views/inspection.php';
    }

    private function input( string $name, string $label, string $value ): void {
        echo '<div class="ss-field"><label for="ss-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><input class="regular-text" id="ss-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="text" value="' . esc_attr( $value ) . '"' . ( $name === 'price' ? ' inputmode="decimal"' : '' ) . '></div>';
    }

    private function textarea( string $name, string $label, string $value, string $help ): void {
        echo '<div class="ss-field"><label for="ss-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><textarea id="ss-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="3" aria-describedby="ss-help-' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea><p id="ss-help-' . esc_attr( $name ) . '" class="description">' . esc_html( $help ) . '</p></div>';
    }
}
