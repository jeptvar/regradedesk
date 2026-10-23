<?php
namespace SecondShelf;
/** Workbench list view. Included only after capability checks. */
defined( 'ABSPATH' ) || exit;
global $wpdb;
$table = Workbench::table();
$counts = $wpdb->get_results( "SELECT state, COUNT(*) AS total FROM $table GROUP BY state", OBJECT_K );
$all_count = array_sum( array_map( static fn( $count ) => (int) $count->total, $counts ) );
$state = sanitize_key( self::scalar( $_GET, 'state' ) );
$state = isset( self::states()[ $state ] ) ? $state : '';
$order_id = absint( self::scalar( $_GET, 'order_id' ) );
$where = $state ? $wpdb->prepare( ' WHERE state = %s', $state ) : '';
$page = max( 1, absint( self::scalar( $_GET, 'paged', '1' ) ) );
$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table$where" );
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table$where ORDER BY id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ), ARRAY_A );
?>
<section id="ss-receive" class="postbox ss-receive" aria-labelledby="ss-receive-title">
    <div class="ss-receive-intro">
        <h2 id="ss-receive-title"><?php esc_html_e( 'Receive a returned unit', 'second-shelf' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Find the original order to start an inspection. Record each returned unit separately.', 'second-shelf' ); ?></p>
    </div>
    <form method="get" class="ss-lookup">
        <input type="hidden" name="page" value="second-shelf">
        <label for="ss-order"><?php esc_html_e( 'Order ID', 'second-shelf' ); ?></label>
        <div class="ss-lookup-controls">
            <input id="ss-order" name="order_id" type="number" min="1" required placeholder="<?php esc_attr_e( 'e.g. 1042', 'second-shelf' ); ?>" value="<?php echo esc_attr( $order_id ?: '' ); ?>">
            <button class="button"><?php esc_html_e( 'Find order', 'second-shelf' ); ?></button>
        </div>
    </form>
    <?php if ( $order_id ) : ?>
        <div class="ss-order-result">
        <?php $order = wc_get_order( $order_id ); ?>
        <?php if ( ! $order || $order->get_type() !== 'shop_order' ) : ?>
            <p role="alert"><?php esc_html_e( 'Order not found. Use its numeric database ID.', 'second-shelf' ); ?></p>
        <?php else : ?>
            <p><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php echo esc_html( sprintf( __( 'Open order #%s', 'second-shelf' ), $order->get_order_number() ) ); ?></a> &middot; <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></p>
            <?php self::form( 'receive' ); ?>
                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
                <input type="hidden" name="receipt_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
                <div class="ss-field">
                    <label for="ss-item"><?php esc_html_e( 'Returned item', 'second-shelf' ); ?></label>
                    <select id="ss-item" name="item_id" required>
                        <option value=""><?php esc_html_e( 'Choose an order line', 'second-shelf' ); ?></option>
                        <?php foreach ( $order->get_items() as $item_id => $item ) : ?>
                            <?php
                            $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE item_id = %d AND order_id = %d", $item_id, $order_id ) );
                            $remaining = max( 0, (int) $item->get_quantity() - $count );
                            ?>
                            <option value="<?php echo esc_attr( $item_id ); ?>" <?php disabled( $remaining, 0 ); ?>><?php echo esc_html( $item->get_name() . ' — ' . sprintf( __( '%d units unrecorded', 'second-shelf' ), $remaining ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p><label><input type="checkbox" name="received_confirmed" value="1" required> <?php esc_html_e( 'This unit has physically arrived and is separated from sellable stock.', 'second-shelf' ); ?></label></p>
                <p class="description"><?php esc_html_e( 'Second Shelf does not refund or restock the original order. If a refund already restocked this unit, reconcile that stock before selling it separately.', 'second-shelf' ); ?></p>
                <p><button class="button button-primary"><?php esc_html_e( 'Start inspection', 'second-shelf' ); ?></button></p>
            </form>
        <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<section class="ss-list" aria-labelledby="ss-list-title">
    <h2 id="ss-list-title" class="screen-reader-text"><?php esc_html_e( 'Inspection workbench', 'second-shelf' ); ?></h2>
    <div class="ss-list-toolbar">
        <ul class="subsubsub ss-filters" aria-label="<?php esc_attr_e( 'Filter inspections by status', 'second-shelf' ); ?>">
            <li><a href="<?php echo esc_url( self::url() ); ?>" <?php echo $state === '' ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'All', 'second-shelf' ); ?> <span class="count">(<?php echo esc_html( $all_count ); ?>)</span></a></li>
            <?php foreach ( self::states() as $key => $label ) : ?>
                <li><span class="ss-filter-divider" aria-hidden="true">|</span> <a href="<?php echo esc_url( self::url( array( 'state' => $key ) ) ); ?>" <?php echo $state === $key ? 'class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( $counts[ $key ]->total ?? 0 ); ?>)</span></a></li>
            <?php endforeach; ?>
        </ul>
        <span class="displaying-num"><?php echo esc_html( sprintf( _n( '%s item', '%s items', $total, 'second-shelf' ), number_format_i18n( $total ) ) ); ?></span>
    </div>
    <?php if ( ! $rows ) : ?>
        <div class="ss-empty">
            <span class="dashicons dashicons-archive" aria-hidden="true"></span>
            <h3><?php echo esc_html( $state ? __( 'No inspections with this status', 'second-shelf' ) : __( 'No inspections yet', 'second-shelf' ) ); ?></h3>
            <p><?php echo esc_html( $state ? __( 'Choose another status to see your recorded units.', 'second-shelf' ) : __( 'Find an order above to receive your first returned unit.', 'second-shelf' ) ); ?></p>
            <?php if ( $state ) : ?><a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'View all inspections', 'second-shelf' ); ?></a><?php endif; ?>
        </div>
    <?php else : ?>
        <div class="ss-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Inspections', 'second-shelf' ); ?>">
            <table class="wp-list-table widefat fixed striped ss-table">
                <thead><tr>
                    <th scope="col" class="ss-col-unit"><?php esc_html_e( 'Unit', 'second-shelf' ); ?></th>
                    <th scope="col" class="ss-col-order"><?php esc_html_e( 'Source order', 'second-shelf' ); ?></th>
                    <th scope="col" class="ss-col-status"><?php esc_html_e( 'Status', 'second-shelf' ); ?></th>
                    <th scope="col" class="ss-col-condition"><?php esc_html_e( 'Condition', 'second-shelf' ); ?></th>
                    <th scope="col" class="ss-col-price"><?php esc_html_e( 'Resale price', 'second-shelf' ); ?></th>
                    <th scope="col" class="ss-col-date"><?php esc_html_e( 'Received (UTC)', 'second-shelf' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $rows as $unit ) : ?>
                    <?php $photos = json_decode( $unit['photo_ids'], true ) ?: array(); ?>
                    <tr>
                        <td class="ss-col-unit">
                            <div class="ss-unit-cell">
                                <div class="ss-thumbnail" aria-hidden="true"><?php echo $photos ? wp_get_attachment_image( $photos[0], 'thumbnail', false, array( 'alt' => '' ) ) : '<span class="dashicons dashicons-format-image"></span>'; ?></div>
                                <div><a class="row-title" href="<?php echo esc_url( self::url( array( 'unit' => $unit['id'] ) ) ); ?>"><?php echo esc_html( $unit['listing_title'] ?: sprintf( __( 'Returned unit #%d', 'second-shelf' ), $unit['id'] ) ); ?></a>
                                    <div class="ss-row-meta"><?php echo esc_html( sprintf( __( 'Unit #%d', 'second-shelf' ), $unit['id'] ) ); ?> <span aria-hidden="true">&middot;</span> <a href="<?php echo esc_url( self::url( array( 'unit' => $unit['id'] ) ) ); ?>"><?php esc_html_e( 'View inspection', 'second-shelf' ); ?></a></div>
                                </div>
                            </div>
                        </td>
                        <td>#<?php echo esc_html( $unit['order_id'] ); ?></td>
                        <td><span class="ss-badge ss-<?php echo esc_attr( $unit['state'] ); ?>"><?php echo esc_html( self::states()[ $unit['state'] ] ); ?></span></td>
                        <td><?php echo esc_html( Workbench::grades()[ $unit['grade'] ] ?? '—' ); ?></td>
                        <td><?php echo $unit['price'] === '' ? '—' : wp_kses_post( wc_price( $unit['price'] ) ); ?></td>
                        <td><time datetime="<?php echo esc_attr( str_replace( ' ', 'T', $unit['received_at'] ) . 'Z' ); ?>"><?php echo esc_html( substr( $unit['received_at'], 0, 10 ) ); ?><span class="ss-row-meta"><?php echo esc_html( substr( $unit['received_at'], 11, 5 ) ); ?></span></time></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="tablenav bottom ss-table-footer">
            <span class="description"><?php esc_html_e( 'Each inspection represents one physical unit.', 'second-shelf' ); ?></span>
            <nav class="ss-pagination" aria-label="<?php esc_attr_e( 'Inspection pages', 'second-shelf' ); ?>"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', self::url( array( 'state' => $state ) ) ), 'format' => '', 'current' => $page, 'total' => (int) ceil( $total / 20 ) ) ) ); ?></nav>
        </div>
    <?php endif; ?>
</section>
