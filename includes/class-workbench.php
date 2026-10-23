<?php
namespace SecondShelf;

defined( 'ABSPATH' ) || exit;

/** Domain operations are shared by the admin UI and integration tests. */
final class Workbench {
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'second_shelf_units';
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta( "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            receipt_key varchar(36) NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            item_id bigint(20) unsigned NOT NULL,
            source_product_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            state varchar(20) NOT NULL DEFAULT 'received',
            grade varchar(30) NOT NULL DEFAULT '',
            listing_title text NOT NULL,
            disclosure text NOT NULL,
            private_notes text NOT NULL,
            price varchar(40) NOT NULL DEFAULT '',
            photo_ids text NOT NULL,
            inspected tinyint(1) NOT NULL DEFAULT 0,
            photos_confirmed tinyint(1) NOT NULL DEFAULT 0,
            version bigint(20) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL,
            updated_by bigint(20) unsigned NOT NULL,
            received_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            listed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY receipt_key (receipt_key),
            KEY order_item (order_id,item_id),
            KEY product_id (product_id),
            KEY state (state)
        ) ENGINE=InnoDB {$wpdb->get_charset_collate()};" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema verification has no WordPress API and must query the database.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
            update_option( 'second_shelf_schema', '1', false );
        }
    }

    public static function authorize(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_products' ) ) {
            throw new \RuntimeException( esc_html__( 'You need permission to manage WooCommerce and edit products.', 'second-shelf' ) );
        }
    }

    /** Connection-scoped locks serialize requests and release automatically on disconnect. */
    private static function locked( string $key, callable $operation ) {
        global $wpdb;
        $name = 'ss_' . substr( hash( 'sha256', DB_NAME . self::table() . $key ), 0, 60 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Connection-scoped database lock must never be cached.
        $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) );
        if ( (string) $acquired !== '1' ) {
            throw new \RuntimeException( esc_html__( 'This unit is busy, or database locking is unavailable. Please try again.', 'second-shelf' ) );
        }
        try {
            return $operation();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Connection-scoped database lock must be released on this connection.
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
        }
    }

    public static function grades(): array {
        return array(
            'open-box' => __( 'Open box', 'second-shelf' ),
            'used-good' => __( 'Used — good', 'second-shelf' ),
            'used-fair' => __( 'Used — fair', 'second-shelf' ),
            'refurbished' => __( 'Refurbished', 'second-shelf' ),
        );
    }

    public static function get( int $id ): array {
        self::authorize();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom inspection table; read fresh state inside the unit lock.
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
        if ( ! $row ) {
            throw new \RuntimeException( esc_html__( 'Inspection not found.', 'second-shelf' ) );
        }
        return $row;
    }

    public static function source( int $order_id, int $item_id ): array {
        self::authorize();
        $order = wc_get_order( $order_id );
        $item = $order ? $order->get_item( $item_id ) : false;
        if ( ! $order || $order->get_type() !== 'shop_order' || ! $item instanceof \WC_Order_Item_Product || (int) $item->get_order_id() !== $order_id ) {
            throw new \RuntimeException( esc_html__( 'Choose a product line belonging to this order.', 'second-shelf' ) );
        }
        if ( ! $order->has_status( array( 'processing', 'completed', 'refunded' ) ) ) {
            throw new \RuntimeException( esc_html__( 'The source order must be processing, completed, or refunded.', 'second-shelf' ) );
        }
        $product = $item->get_product();
        if ( ! $product || ! $product->is_type( array( 'simple', 'variation' ) ) || $product->is_virtual() || $product->is_downloadable() || $product->get_status() === 'trash' ) {
            throw new \RuntimeException( esc_html__( 'Only existing physical simple products and variations are supported.', 'second-shelf' ) );
        }
        if ( (float) $item->get_quantity() < 1 || floor( (float) $item->get_quantity() ) !== (float) $item->get_quantity() ) {
            throw new \RuntimeException( esc_html__( 'Only whole-unit order quantities are supported.', 'second-shelf' ) );
        }
        return array( $order, $item, $product );
    }

    public static function receive( int $order_id, int $item_id, string $receipt_key ): int {
        self::authorize();
        if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $receipt_key ) ) {
            throw new \RuntimeException( esc_html__( 'Invalid receipt identifier. Reload the page.', 'second-shelf' ) );
        }
        return self::locked( 'receive:' . $order_id . ':' . $item_id, static function () use ( $order_id, $item_id, $receipt_key ) {
            global $wpdb;
            $table = self::table();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom inspection table; receipt idempotency must use current committed rows.
            $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE receipt_key = %s', $table, $receipt_key ), ARRAY_A );
            if ( $existing ) {
                if ( (int) $existing['order_id'] !== $order_id || (int) $existing['item_id'] !== $item_id ) {
                    throw new \RuntimeException( esc_html__( 'Receipt identifier already used for another item.', 'second-shelf' ) );
                }
                return (int) $existing['id'];
            }
            [ , $item, $product ] = self::source( $order_id, $item_id );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom inspection table; capacity is checked under a database lock.
            $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d AND item_id = %d', $table, $order_id, $item_id ) );
            if ( $count >= (int) $item->get_quantity() ) {
                throw new \RuntimeException( esc_html__( 'All units from this order line already have inspections, including rejected units.', 'second-shelf' ) );
            }
            $now = current_time( 'mysql', true );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom inspection table write through the escaping wpdb insert API.
            $result = $wpdb->insert( $table, array(
                'receipt_key' => $receipt_key, 'order_id' => $order_id, 'item_id' => $item_id,
                'source_product_id' => $product->get_id(), 'listing_title' => $product->get_name(),
                'disclosure' => '', 'private_notes' => '', 'photo_ids' => '[]',
                'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id(),
                'received_at' => $now, 'updated_at' => $now,
            ) );
            if ( false === $result ) {
                throw new \RuntimeException( esc_html__( 'Could not save the inspection.', 'second-shelf' ) );
            }
            return (int) $wpdb->insert_id;
        } );
    }

    private static function write( int $id, array $data ): void {
        global $wpdb;
        $data['updated_by'] = get_current_user_id();
        $data['updated_at'] = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom inspection table write through the escaping wpdb update API.
        if ( false === $wpdb->update( self::table(), $data, array( 'id' => $id ) ) ) {
            throw new \RuntimeException( esc_html__( 'Could not update the inspection.', 'second-shelf' ) );
        }
    }

    private static function validate_ready( array $row ): void {
        if ( ! isset( self::grades()[ $row['grade'] ] ) || ! trim( $row['listing_title'] ) || ! trim( $row['disclosure'] ) || ! $row['inspected'] ) {
            throw new \RuntimeException( esc_html__( 'Add a title, condition and buyer-facing disclosure, and confirm physical inspection.', 'second-shelf' ) );
        }
        if ( ! is_numeric( $row['price'] ) || (float) $row['price'] <= 0 || ! is_finite( (float) $row['price'] ) ) {
            throw new \RuntimeException( esc_html__( 'Set a resale price greater than zero.', 'second-shelf' ) );
        }
        $photos = json_decode( $row['photo_ids'], true );
        if ( ! $photos || ! $row['photos_confirmed'] ) {
            throw new \RuntimeException( esc_html__( 'Choose at least one photo and confirm it is suitable for public display.', 'second-shelf' ) );
        }
        foreach ( $photos as $photo ) {
            if ( get_post_status( $photo ) === 'trash' || get_post_type( $photo ) !== 'attachment' || ! in_array( get_post_mime_type( $photo ), array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) || ! current_user_can( 'edit_post', $photo ) ) {
                throw new \RuntimeException( esc_html__( 'A selected photo is unavailable or you cannot use it. Choose accessible JPEG, PNG, WebP or GIF images.', 'second-shelf' ) );
            }
        }
    }

    public static function save( int $id, int $version, array $input ): void {
        self::authorize();
        self::locked( 'unit:' . $id, static function () use ( $id, $version, $input ) {
            $row = self::get( $id );
            if ( (int) $row['version'] !== $version ) {
                throw new \RuntimeException( esc_html__( 'Another save changed this inspection. Reload before editing.', 'second-shelf' ) );
            }
            if ( in_array( $row['state'], array( 'building', 'listed' ), true ) ) {
                throw new \RuntimeException( esc_html__( 'This inspection is frozen because a resale product has been reserved. Edit the product separately.', 'second-shelf' ) );
            }
            $state = sanitize_key( $input['state'] ?? 'received' );
            $grade = sanitize_key( $input['grade'] ?? '' );
            if ( ! in_array( $state, array( 'received', 'ready', 'rejected' ), true ) || ( $grade !== '' && ! isset( self::grades()[ $grade ] ) ) ) {
                throw new \RuntimeException( esc_html__( 'Invalid inspection state or condition.', 'second-shelf' ) );
            }
            $photo_ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) ( $input['photo_ids'] ?? '' ) ) ) ) ) );
            if ( count( $photo_ids ) > 12 ) {
                throw new \RuntimeException( esc_html__( 'Choose up to 12 photos.', 'second-shelf' ) );
            }
            $raw_price = trim( (string) ( $input['price'] ?? '' ) );
            if ( $raw_price !== '' && ! preg_match( '/^\d+(?:[.,]\d+)?$/D', $raw_price ) ) {
                throw new \RuntimeException( esc_html__( 'Enter a plain price, without currency symbols or thousands separators.', 'second-shelf' ) );
            }
            $data = array(
                'state' => $state, 'grade' => $grade,
                'listing_title' => sanitize_text_field( $input['listing_title'] ?? '' ),
                'disclosure' => sanitize_textarea_field( $input['disclosure'] ?? '' ),
                'private_notes' => sanitize_textarea_field( $input['private_notes'] ?? '' ),
                'price' => $raw_price === '' ? '' : wc_format_decimal( str_replace( ',', '.', $raw_price ), wc_get_price_decimals() ),
                'photo_ids' => wp_json_encode( $photo_ids ),
                'inspected' => empty( $input['inspected'] ) ? 0 : 1,
                'photos_confirmed' => empty( $input['photos_confirmed'] ) ? 0 : 1,
                'version' => $version + 1,
            );
            if ( $state === 'ready' ) {
                self::validate_ready( $data );
            }
            self::write( $id, $data );
        } );
    }

    /** Ensure the draft reservation and its durable record link can commit together. */
    private static function require_transactions(): void {
        global $wpdb;
        foreach ( array( $wpdb->posts, $wpdb->postmeta, self::table() ) as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Engine verification must inspect actual tables before using transactions.
            $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( ! $status || strtolower( (string) $status->Engine ) !== 'innodb' ) {
                throw new \RuntimeException( esc_html__( 'Draft creation requires InnoDB tables for posts, metadata and Second Shelf.', 'second-shelf' ) );
            }
        }
    }

    public static function create_draft( int $id, int $version ): int {
        self::authorize();
        return self::locked( 'unit:' . $id, static function () use ( $id, $version ) {
            global $wpdb;
            $row = self::get( $id );
            if ( $row['product_id'] && ! current_user_can( 'edit_post', (int) $row['product_id'] ) ) {
                throw new \RuntimeException( esc_html__( 'You cannot edit the reserved resale product.', 'second-shelf' ) );
            }
            if ( $row['state'] === 'listed' ) {
                if ( get_post_type( (int) $row['product_id'] ) !== 'product' || get_post_status( (int) $row['product_id'] ) === 'trash' ) {
                    throw new \RuntimeException( esc_html__( 'The linked product was deleted or trashed. Restore it; a second listing will not be created.', 'second-shelf' ) );
                }
                return (int) $row['product_id'];
            }
            if ( (int) $row['version'] !== $version || ! in_array( $row['state'], array( 'ready', 'building' ), true ) ) {
                throw new \RuntimeException( esc_html__( 'Save a ready inspection, then reload before creating its draft.', 'second-shelf' ) );
            }
            self::validate_ready( $row );
            $source = wc_get_product( (int) $row['source_product_id'] );
            if ( ! $source || $source->get_status() === 'trash' || ! $source->is_type( array( 'simple', 'variation' ) ) || $source->is_virtual() || $source->is_downloadable() ) {
                throw new \RuntimeException( esc_html__( 'The original physical product is unavailable or no longer supported. Restore it before creating the resale draft.', 'second-shelf' ) );
            }
            if ( ! $row['product_id'] ) {
                self::require_transactions();
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic reservation requires a transaction on this connection.
                if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
                    throw new \RuntimeException( esc_html__( 'Could not start the draft reservation transaction.', 'second-shelf' ) );
                }
                try {
                    // Reserve the post and record link before WooCommerce hooks can interrupt population.
                    $product_id = wp_insert_post( array(
                        'post_type' => 'product', 'post_status' => 'draft',
                        /* translators: %d: inspection identifier. */
                        'post_title' => sprintf( __( 'Second Shelf unit #%d — preparation incomplete', 'second-shelf' ), $id ),
                        'post_author' => get_current_user_id(),
                        'meta_input' => array( '_second_shelf_unit' => $id ),
                    ), true );
                    if ( is_wp_error( $product_id ) ) {
                        throw new \RuntimeException( $product_id->get_error_message() );
                    }
                    self::write( $id, array( 'product_id' => $product_id, 'state' => 'building', 'version' => $version + 1 ) );
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Commit the atomic product and inspection reservation.
                    if ( false === $wpdb->query( 'COMMIT' ) ) {
                        throw new \RuntimeException( esc_html__( 'Could not commit the draft reservation.', 'second-shelf' ) );
                    }
                    $row = self::get( $id );
                } catch ( \Throwable $error ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Roll back a failed product and inspection reservation.
                    $wpdb->query( 'ROLLBACK' );
                    if ( isset( $product_id ) && is_int( $product_id ) ) {
                        clean_post_cache( $product_id );
                    }
                    throw $error;
                }
            }
            $product_id = (int) $row['product_id'];
            if ( get_post_type( $product_id ) !== 'product' || get_post_status( $product_id ) !== 'draft' || (int) get_post_meta( $product_id, '_second_shelf_unit', true ) !== $id ) {
                throw new \RuntimeException( esc_html__( 'The reserved draft was changed or removed. Restore that draft before retrying.', 'second-shelf' ) );
            }
            $product = new \WC_Product_Simple( $product_id );
            $product->set_name( $row['listing_title'] . ' — ' . self::grades()[ $row['grade'] ] );
            $product->set_status( 'draft' );
            $product->set_catalog_visibility( 'visible' );
            $product->set_sku( 'SS-' . get_current_blog_id() . '-' . $id );
            $product->set_regular_price( $row['price'] );
            $product->set_sale_price( '' );
            $product->set_price( $row['price'] );
            $product->set_manage_stock( true );
            $product->set_stock_quantity( 1 );
            $product->set_stock_status( 'instock' );
            $product->set_backorders( 'no' );
            $product->set_sold_individually( true );
            $product->set_virtual( false );
            $product->set_downloadable( false );
            $product->set_description( '<p><strong>' . esc_html( self::grades()[ $row['grade'] ] ) . '</strong></p>' . wpautop( esc_html( $row['disclosure'] ) ) );
            $product->set_short_description( '' );
            $product->set_weight( $source->get_weight() );
            $product->set_length( $source->get_length() );
            $product->set_width( $source->get_width() );
            $product->set_height( $source->get_height() );
            $product->set_tax_status( $source->get_tax_status() );
            $product->set_tax_class( $source->get_tax_class() );
            $product->set_shipping_class_id( $source->get_shipping_class_id() );
            $category_source = $source->is_type( 'variation' ) ? wc_get_product( $source->get_parent_id() ) : $source;
            $product->set_category_ids( $category_source ? $category_source->get_category_ids() : array() );
            $photos = json_decode( $row['photo_ids'], true );
            $product->set_image_id( array_shift( $photos ) );
            $product->set_gallery_image_ids( $photos );
            $product->update_meta_data( '_second_shelf_unit', $id );
            $product->update_meta_data( '_second_shelf_source_order', (int) $row['order_id'] );
            $product->update_meta_data( '_second_shelf_source_item', (int) $row['item_id'] );
            $product->update_meta_data( '_second_shelf_grade', $row['grade'] );
            $product->save();
            self::write( $id, array( 'state' => 'listed', 'listed_at' => current_time( 'mysql', true ), 'version' => (int) $row['version'] + 1 ) );
            return $product_id;
        } );
    }
}
