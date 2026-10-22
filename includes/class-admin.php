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
        wp_enqueue_style( 'second-shelf', plugins_url( 'assets/admin.css', SECOND_SHELF_FILE ), array(), '0.1.0' );
        wp_enqueue_media();
        wp_enqueue_script( 'second-shelf', plugins_url( 'assets/admin.js', SECOND_SHELF_FILE ), array( 'jquery', 'media-views' ), '0.1.0', true );
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
            if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
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
        $state = $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM ' . Workbench::table() . ' WHERE product_id = %d', $postarr['ID'] ) );
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
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, state FROM ' . Workbench::table() . ' WHERE product_id = %d', $post->ID ), ARRAY_A );
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
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
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
            echo '<div class="wrap ss-wrap"><header class="ss-header"><div><p class="ss-eyebrow">' . esc_html__( 'WOOCOMMERCE / RETURN OPERATIONS', 'second-shelf' ) . '</p><h1>' . esc_html__( 'Second Shelf', 'second-shelf' ) . '</h1><p>' . esc_html__( 'A considered second life for every returned unit.', 'second-shelf' ) . '</p></div><a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'All inspections', 'second-shelf' ) . '</a></header>';
            if ( isset( $_GET['saved'] ) ) {
                echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Saved. Review the inspection and its next step below.', 'second-shelf' ) . '</p></div>';
            }
            $id = absint( self::scalar( $_GET, 'unit' ) );
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
        global $wpdb;
        $table = Workbench::table();
        $counts = $wpdb->get_results( "SELECT state, COUNT(*) AS total FROM $table GROUP BY state", OBJECT_K );
        echo '<div class="ss-stats">';
        foreach ( self::states() as $key => $label ) {
            echo '<a class="ss-stat" href="' . esc_url( self::url( array( 'state' => $key ) ) ) . '"><strong>' . esc_html( $counts[ $key ]->total ?? 0 ) . '</strong><span>' . esc_html( $label ) . '</span></a>';
        }
        echo '</div><section class="ss-panel"><h2>' . esc_html__( 'Receive a returned unit', 'second-shelf' ) . '</h2><p>' . esc_html__( 'Find the original order, then choose the item that arrived. Each inspection represents one physical unit.', 'second-shelf' ) . '</p>';
        $order_id = absint( self::scalar( $_GET, 'order_id' ) );
        echo '<form method="get" class="ss-lookup"><input type="hidden" name="page" value="second-shelf"><label for="ss-order">' . esc_html__( 'Order ID', 'second-shelf' ) . '</label> <input id="ss-order" name="order_id" type="number" min="1" required value="' . esc_attr( $order_id ?: '' ) . '"> <button class="button">' . esc_html__( 'Find order', 'second-shelf' ) . '</button></form>';
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order || $order->get_type() !== 'shop_order' ) {
                echo '<p role="alert">' . esc_html__( 'Order not found. Use its numeric database ID.', 'second-shelf' ) . '</p>';
            } else {
                echo '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( sprintf( __( 'Open order #%s', 'second-shelf' ), $order->get_order_number() ) ) . '</a> · ' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</p>';
                self::form( 'receive' );
                echo '<input type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '"><input type="hidden" name="receipt_key" value="' . esc_attr( wp_generate_uuid4() ) . '"><label for="ss-item">' . esc_html__( 'Returned item', 'second-shelf' ) . '</label> <select id="ss-item" name="item_id" required><option value="">' . esc_html__( 'Choose an order line', 'second-shelf' ) . '</option>';
                foreach ( $order->get_items() as $item_id => $item ) {
                    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE item_id = %d AND order_id = %d", $item_id, $order_id ) );
                    $remaining = max( 0, (int) $item->get_quantity() - $count );
                    echo '<option value="' . esc_attr( $item_id ) . '" ' . disabled( $remaining, 0, false ) . '>' . esc_html( $item->get_name() . ' — ' . sprintf( __( '%d units unrecorded', 'second-shelf' ), $remaining ) ) . '</option>';
                }
                echo '</select><p><label><input type="checkbox" name="received_confirmed" value="1" required> ' . esc_html__( 'This unit has physically arrived and is separated from sellable stock.', 'second-shelf' ) . '</label></p><p class="description">' . esc_html__( 'Second Shelf does not refund or restock the original order. If a refund already restocked this unit, reconcile that stock before selling it separately.', 'second-shelf' ) . '</p><p><button class="button button-primary">' . esc_html__( 'Start inspection', 'second-shelf' ) . '</button></p></form>';
            }
        }
        echo '</section><section class="ss-panel"><h2>' . esc_html__( 'Inspection workbench', 'second-shelf' ) . '</h2>';
        $state = sanitize_key( self::scalar( $_GET, 'state' ) );
        $where = isset( self::states()[ $state ] ) ? $wpdb->prepare( ' WHERE state = %s', $state ) : '';
        $page = max( 1, absint( self::scalar( $_GET, 'paged', '1' ) ) );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table$where" );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table$where ORDER BY id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ), ARRAY_A );
        if ( $where ) {
            echo '<p>' . esc_html( self::states()[ $state ] ) . ' · <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Clear filter', 'second-shelf' ) . '</a></p>';
        }
        if ( ! $rows ) {
            echo '<div class="ss-empty"><h3>' . esc_html__( 'Your next resale starts here.', 'second-shelf' ) . '</h3><p>' . esc_html__( 'Receive a returned unit above to document its condition and prepare a separate listing.', 'second-shelf' ) . '</p></div>';
        } else {
            echo '<div class="ss-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Inspections', 'second-shelf' ) . '"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Unit / product', 'second-shelf' ) . '</th><th>' . esc_html__( 'Source order ID', 'second-shelf' ) . '</th><th>' . esc_html__( 'Status', 'second-shelf' ) . '</th><th>' . esc_html__( 'Received (UTC)', 'second-shelf' ) . '</th></tr></thead><tbody>';
            foreach ( $rows as $row ) {
                echo '<tr><td><a class="ss-unit-link" href="' . esc_url( self::url( array( 'unit' => $row['id'] ) ) ) . '">#' . esc_html( $row['id'] ) . ' · ' . esc_html( $row['listing_title'] ) . '</a></td><td>#' . esc_html( $row['order_id'] ) . '</td><td><span class="ss-badge ss-' . esc_attr( $row['state'] ) . '">' . esc_html( self::states()[ $row['state'] ] ) . '</span></td><td>' . esc_html( $row['received_at'] ) . '</td></tr>';
            }
            echo '</tbody></table></div><nav class="ss-pagination" aria-label="' . esc_attr__( 'Inspection pages', 'second-shelf' ) . '">';
            echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', self::url( array( 'state' => $state ) ) ), 'format' => '', 'current' => $page, 'total' => (int) ceil( $total / 20 ) ) ) );
            echo '</nav>';
        }
        echo '</section>';
    }

    private function detail( array $row ): void {
        $id = (int) $row['id'];
        $frozen = in_array( $row['state'], array( 'building', 'listed' ), true );
        $order = wc_get_order( (int) $row['order_id'] );
        echo '<div class="ss-detail"><section class="ss-panel"><div class="ss-title-row"><h2>' . esc_html( sprintf( __( 'Unit #%d · inspection', 'second-shelf' ), $id ) ) . '</h2><span class="ss-badge ss-' . esc_attr( $row['state'] ) . '">' . esc_html( self::states()[ $row['state'] ] ) . '</span></div>';
        self::form( 'save', $id, (int) $row['version'] );
        echo '<fieldset ' . disabled( $frozen, true, false ) . '><legend class="screen-reader-text">' . esc_html__( 'Inspection details', 'second-shelf' ) . '</legend>';
        $this->input( 'listing_title', __( 'Resale listing title', 'second-shelf' ), $row['listing_title'] );
        echo '<div class="ss-field"><label for="ss-grade">' . esc_html__( 'Condition', 'second-shelf' ) . '</label><select id="ss-grade" name="grade"><option value="">' . esc_html__( 'Choose after inspection', 'second-shelf' ) . '</option>';
        foreach ( Workbench::grades() as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $row['grade'], $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></div>';
        $this->input( 'price', sprintf( __( 'Resale price (%s)', 'second-shelf' ), get_woocommerce_currency() ), $row['price'] );
        $this->textarea( 'disclosure', __( 'What the buyer should know', 'second-shelf' ), $row['disclosure'], __( 'Public: describe wear, damage, testing, included accessories and anything missing. This text becomes the product description.', 'second-shelf' ) );
        $this->textarea( 'private_notes', __( 'Internal inspection notes', 'second-shelf' ), $row['private_notes'], __( 'Staff only. These notes are never copied into the resale product.', 'second-shelf' ) );
        $photos = json_decode( $row['photo_ids'], true ) ?: array();
        echo '<div class="ss-field"><label for="ss-photos">' . esc_html__( 'Resale photos', 'second-shelf' ) . '</label><input type="hidden" id="ss-photos" name="photo_ids" value="' . esc_attr( implode( ',', $photos ) ) . '"><div id="ss-photo-preview" class="ss-photos">';
        foreach ( $photos as $photo ) {
            echo wp_get_attachment_image( $photo, 'thumbnail' );
        }
        echo '</div><p><button type="button" id="ss-select-photos" class="button">' . esc_html__( 'Choose photos', 'second-shelf' ) . '</button> <button type="button" id="ss-clear-photos" class="button">' . esc_html__( 'Clear selection', 'second-shelf' ) . '</button></p><p id="ss-photo-status" role="status"></p><p class="description">' . esc_html__( 'Up to 12 images. The first is the cover. Media Library uploads have public URLs: exclude faces, address labels and customer information.', 'second-shelf' ) . '</p></div>';
        echo '<p><label><input type="checkbox" name="photos_confirmed" value="1" ' . checked( $row['photos_confirmed'], 1, false ) . '> ' . esc_html__( 'I reviewed these photos and approve them for public display.', 'second-shelf' ) . '</label></p><p><label><input type="checkbox" name="inspected" value="1" ' . checked( $row['inspected'], 1, false ) . '> ' . esc_html__( 'I physically inspected this unit and consider it suitable for resale as described.', 'second-shelf' ) . '</label></p><div class="ss-field"><label for="ss-state">' . esc_html__( 'Inspection decision', 'second-shelf' ) . '</label><select id="ss-state" name="state">';
        foreach ( self::states() as $key => $label ) {
            if ( ! $frozen && in_array( $key, array( 'building', 'listed' ), true ) ) {
                continue;
            }
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $row['state'], $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></div>';
        if ( ! $frozen ) {
            echo '<button class="button button-primary button-large">' . esc_html__( 'Save inspection', 'second-shelf' ) . '</button>';
        }
        echo '</fieldset></form></section><aside><section class="ss-panel"><h2>' . esc_html__( 'Unit history', 'second-shelf' ) . '</h2><dl><dt>' . esc_html__( 'Original order', 'second-shelf' ) . '</dt><dd>';
        echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : esc_html__( 'Order no longer available', 'second-shelf' );
        echo '</dd><dt>' . esc_html__( 'Order item / source product', 'second-shelf' ) . '</dt><dd>#' . esc_html( $row['item_id'] ) . ' / #' . esc_html( $row['source_product_id'] ) . '</dd><dt>' . esc_html__( 'Received (UTC)', 'second-shelf' ) . '</dt><dd>' . esc_html( $row['received_at'] ) . '</dd><dt>' . esc_html__( 'Last updated (UTC)', 'second-shelf' ) . '</dt><dd>' . esc_html( $row['updated_at'] ) . '</dd></dl><p>' . esc_html__( 'One physical unit. Original payments, refunds and stock are handled separately.', 'second-shelf' ) . '</p></section><section class="ss-panel ss-next"><h2>' . esc_html__( 'Next step', 'second-shelf' ) . '</h2>';
        if ( $row['state'] === 'listed' ) {
            $product = wc_get_product( (int) $row['product_id'] );
            if ( $product && $product->get_status() !== 'trash' ) {
                echo '<p>' . esc_html__( 'Your resale draft was created. Review its shipping, tax, description and photos in WooCommerce before publishing.', 'second-shelf' ) . '</p><p>' . esc_html__( 'Current product status:', 'second-shelf' ) . ' <strong>' . esc_html( $product->get_status() ) . '</strong></p><a class="button button-primary" href="' . esc_url( get_edit_post_link( $product->get_id(), 'raw' ) ) . '">' . esc_html__( 'Review resale product', 'second-shelf' ) . '</a>';
            } else {
                echo '<p>' . esc_html__( 'The linked product is missing or trashed. Restore the original linked product; this inspection will not create a duplicate.', 'second-shelf' ) . '</p>';
            }
        } elseif ( in_array( $row['state'], array( 'ready', 'building' ), true ) ) {
            echo '<p>' . esc_html__( 'Prepare a separate draft with stock of one. Only the public disclosure and selected photos are included.', 'second-shelf' ) . '</p>';
            if ( $row['state'] === 'building' ) {
                echo '<p>' . esc_html__( 'Preparation was interrupted. Retry to finish the same reserved draft.', 'second-shelf' ) . '</p>';
            }
            self::form( 'create', $id, (int) $row['version'] );
            echo '<button id="ss-create-draft" class="button button-primary">' . esc_html( $row['state'] === 'building' ? __( 'Retry draft preparation', 'second-shelf' ) : __( 'Create resale draft', 'second-shelf' ) ) . '</button><p id="ss-unsaved" role="status"></p></form>';
        } else {
            echo '<p>' . esc_html__( 'Complete the inspection and choose “Ready for draft” to prepare a listing. Unsuitable units can be marked “Not for resale”.', 'second-shelf' ) . '</p>';
        }
        echo '</section></aside></div>';
    }

    private function input( string $name, string $label, string $value ): void {
        echo '<div class="ss-field"><label for="ss-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><input class="regular-text" id="ss-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="text" value="' . esc_attr( $value ) . '"' . ( $name === 'price' ? ' inputmode="decimal"' : '' ) . '></div>';
    }

    private function textarea( string $name, string $label, string $value, string $help ): void {
        echo '<div class="ss-field"><label for="ss-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><textarea id="ss-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="4" aria-describedby="ss-help-' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea><p id="ss-help-' . esc_attr( $name ) . '" class="description">' . esc_html( $help ) . '</p></div>';
    }
}
