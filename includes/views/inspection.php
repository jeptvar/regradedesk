<?php
namespace RegradeDesk;
/** Single inspection. Included in Admin scope after capability checks. */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside Admin::detail(); these variables are method-local.
defined( 'ABSPATH' ) || exit;
$id = (int) $row['id'];
$frozen = in_array( $row['state'], array( 'building', 'listed' ), true );
$order = wc_get_order( (int) $row['order_id'] );
$photos = json_decode( $row['photo_ids'], true ) ?: array();
?>
<div class="ss-detail">
    <div class="ss-main-column">
        <?php self::form( 'save', $id, (int) $row['version'] ); ?>
        <fieldset <?php disabled( $frozen ); ?>>
            <legend class="screen-reader-text"><?php esc_html_e( 'Inspection details', 'regradedesk' ); ?></legend>
            <section class="postbox">
                <div class="postbox-header"><h2><?php esc_html_e( 'Resale listing', 'regradedesk' ); ?></h2><span class="ss-panel-note"><?php esc_html_e( 'Visible to buyers', 'regradedesk' ); ?></span></div>
                <div class="inside">
                    <?php $this->input( 'listing_title', __( 'Resale listing title', 'regradedesk' ), $row['listing_title'] ); ?>
                    <div class="ss-field-row">
                        <div class="ss-field">
                            <label for="ss-grade"><?php esc_html_e( 'Condition', 'regradedesk' ); ?></label>
                            <select id="ss-grade" name="grade"><option value=""><?php esc_html_e( 'Choose after inspection', 'regradedesk' ); ?></option>
                            <?php foreach ( Workbench::grades() as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['grade'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <?php /* translators: %s: store currency code. */ ?>
                        <?php $this->input( 'price', sprintf( __( 'Resale price (%s)', 'regradedesk' ), get_woocommerce_currency() ), $row['price'] ); ?>
                    </div>
                    <?php $this->textarea( 'disclosure', __( 'What the buyer should know', 'regradedesk' ), $row['disclosure'], __( 'Describe wear, testing, included accessories and missing parts. This becomes the product description.', 'regradedesk' ) ); ?>
                </div>
            </section>
            <section class="postbox">
                <div class="postbox-header"><h2 id="ss-photos-title"><?php esc_html_e( 'Resale photos', 'regradedesk' ); ?></h2><span class="ss-panel-note"><?php esc_html_e( 'First image is the cover', 'regradedesk' ); ?></span></div>
                <div class="inside">
                    <input type="hidden" id="ss-photos" name="photo_ids" value="<?php echo esc_attr( implode( ',', $photos ) ); ?>">
                    <div id="ss-photo-preview" class="ss-photos" role="group" aria-labelledby="ss-photos-title">
                        <?php foreach ( $photos as $photo ) { echo wp_get_attachment_image( $photo, 'thumbnail' ); } ?>
                    </div>
                    <p class="ss-photo-empty"><?php esc_html_e( 'No photos selected. Add clear images of this unit and any visible wear.', 'regradedesk' ); ?></p>
                    <div class="ss-photo-actions"><button type="button" id="ss-select-photos" class="button"><?php esc_html_e( 'Choose photos', 'regradedesk' ); ?></button> <button type="button" id="ss-clear-photos" class="button-link"><?php esc_html_e( 'Clear selection', 'regradedesk' ); ?></button><span id="ss-photo-status" class="description" role="status"></span></div>
                    <p class="description"><?php esc_html_e( 'Up to 12 images. Media Library uploads have public URLs. Exclude address labels, faces and customer information.', 'regradedesk' ); ?></p>
                    <label class="ss-check"><input type="checkbox" name="photos_confirmed" value="1" <?php checked( $row['photos_confirmed'], 1 ); ?>> <span><?php esc_html_e( 'I reviewed these photos and approve them for public display.', 'regradedesk' ); ?></span></label>
                </div>
            </section>
            <section class="postbox">
                <div class="postbox-header"><h2><?php esc_html_e( 'Inspection notes', 'regradedesk' ); ?></h2><span class="ss-panel-note"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Staff only', 'regradedesk' ); ?></span></div>
                <div class="inside">
                    <?php $this->textarea( 'private_notes', __( 'Internal inspection notes', 'regradedesk' ), $row['private_notes'], __( 'Record checks performed and other internal details. These notes are never copied into the resale product.', 'regradedesk' ) ); ?>
                    <label class="ss-check"><input type="checkbox" name="inspected" value="1" <?php checked( $row['inspected'], 1 ); ?>> <span><?php esc_html_e( 'I physically inspected this unit and consider it suitable for resale as described.', 'regradedesk' ); ?></span></label>
                </div>
            </section>
        </fieldset>
        </form>
    </div>
    <aside class="ss-sidebar" aria-label="<?php esc_attr_e( 'Inspection actions and source', 'regradedesk' ); ?>">
        <section class="postbox">
            <div class="postbox-header"><h2><?php esc_html_e( 'Inspection status', 'regradedesk' ); ?></h2></div>
            <div class="inside">
                <p class="ss-current-status"><span class="ss-badge ss-<?php echo esc_attr( $row['state'] ); ?>"><?php echo esc_html( self::states()[ $row['state'] ] ); ?></span></p>
                <?php if ( ! $frozen ) : ?>
                    <div class="ss-field">
                        <label for="ss-state"><?php esc_html_e( 'Inspection decision', 'regradedesk' ); ?></label>
                        <select id="ss-state" name="state" form="ss-inspection-form">
                            <?php foreach ( self::states() as $key => $label ) : ?>
                                <?php if ( in_array( $key, array( 'building', 'listed' ), true ) ) { continue; } ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['state'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Choose “Ready for draft” once the inspection, price and photos are complete.', 'regradedesk' ); ?></p>
                    </div>
                <?php else : ?>
                    <p class="description"><?php esc_html_e( 'This inspection is saved as a record. Make any listing changes in the resale product.', 'regradedesk' ); ?></p>
                <?php endif; ?>
            </div>
            <?php if ( ! $frozen ) : ?>
                <div class="ss-actions"><span class="description"><?php esc_html_e( 'Saves inspection only', 'regradedesk' ); ?></span><button type="submit" form="ss-inspection-form" class="button button-primary"><?php esc_html_e( 'Save inspection', 'regradedesk' ); ?></button></div>
            <?php endif; ?>
        </section>
        <section class="postbox">
            <div class="postbox-header"><h2><?php esc_html_e( 'Resale product', 'regradedesk' ); ?></h2></div>
            <div class="inside">
            <?php if ( $row['state'] === 'listed' ) : ?>
                <?php $product = wc_get_product( (int) $row['product_id'] ); ?>
                <?php if ( $product && $product->get_status() !== 'trash' ) : ?>
                    <p><?php esc_html_e( 'Review shipping, tax, description and photos before publishing.', 'regradedesk' ); ?></p>
                    <p><?php esc_html_e( 'Product status:', 'regradedesk' ); ?> <strong><?php echo esc_html( $product->get_status() ); ?></strong></p>
                    <a class="button button-primary" href="<?php echo esc_url( get_edit_post_link( $product->get_id(), 'raw' ) ); ?>"><?php esc_html_e( 'Review resale product', 'regradedesk' ); ?></a>
                <?php else : ?>
                    <p><?php esc_html_e( 'The linked product is missing or trashed. Restore the original linked product; this inspection will not create a duplicate.', 'regradedesk' ); ?></p>
                <?php endif; ?>
            <?php elseif ( in_array( $row['state'], array( 'ready', 'building' ), true ) ) : ?>
                <p><?php esc_html_e( 'Create a separate draft with stock of one, your public disclosure and selected photos.', 'regradedesk' ); ?></p>
                <?php if ( $row['state'] === 'building' ) : ?><p><?php esc_html_e( 'Preparation was interrupted. Retry to finish the same reserved draft.', 'regradedesk' ); ?></p><?php endif; ?>
                <?php self::form( 'create', $id, (int) $row['version'] ); ?>
                    <button id="ss-create-draft" class="button"><?php echo esc_html( $row['state'] === 'building' ? __( 'Retry draft preparation', 'regradedesk' ) : __( 'Create resale draft', 'regradedesk' ) ); ?></button>
                    <p id="ss-unsaved" role="status"></p>
                </form>
            <?php else : ?>
                <p class="description"><?php esc_html_e( 'A resale draft becomes available after you save the inspection as “Ready for draft”.', 'regradedesk' ); ?></p>
                <button class="button" disabled><?php esc_html_e( 'Create resale draft', 'regradedesk' ); ?></button>
            <?php endif; ?>
            </div>
        </section>
        <section class="postbox">
            <div class="postbox-header"><h2><?php esc_html_e( 'Original order', 'regradedesk' ); ?></h2></div>
            <div class="inside">
                <dl class="ss-source">
                    <dt><?php esc_html_e( 'Order', 'regradedesk' ); ?></dt><dd><?php echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : esc_html__( 'Unavailable', 'regradedesk' ); ?></dd>
                    <dt><?php esc_html_e( 'Order item', 'regradedesk' ); ?></dt><dd>#<?php echo esc_html( $row['item_id'] ); ?></dd>
                    <dt><?php esc_html_e( 'Source product', 'regradedesk' ); ?></dt><dd>#<?php echo esc_html( $row['source_product_id'] ); ?></dd>
                    <dt><?php esc_html_e( 'Quantity', 'regradedesk' ); ?></dt><dd><?php esc_html_e( '1 unit', 'regradedesk' ); ?></dd>
                </dl>
                <div class="ss-timestamps"><p><span><?php esc_html_e( 'Received (UTC)', 'regradedesk' ); ?></span><br><?php echo esc_html( $row['received_at'] ); ?></p><p><span><?php esc_html_e( 'Updated (UTC)', 'regradedesk' ); ?></span><br><?php echo esc_html( $row['updated_at'] ); ?></p></div>
                <p class="description"><?php esc_html_e( 'Original payments, refunds and stock are handled separately.', 'regradedesk' ); ?></p>
            </div>
        </section>
    </aside>
</div>
