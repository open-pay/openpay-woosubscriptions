<h2 id="saved-cards" style="margin-top:40px;"><?php _e( 'Saved cards', 'openpay-woosubscriptions' ); ?></h2>
<table class="shop_table">
	<thead>
		<tr>
			<th><?php _e( 'Card', 'openpay-woosubscriptions' ); ?></th>
			<th><?php _e( 'Expires', 'openpay-woosubscriptions' ); ?></th>
			<th></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $cards as $card ) : ?>
		<tr>
            <td><?php printf( __( '%s card ending in %s', 'openpay-woosubscriptions' ), ( isset( $card->type ) ? $card->type : $card->brand ), $card->last4 ); ?></td>
            <td><?php printf( __( 'Expires %s/%s', 'openpay-woosubscriptions' ), $card->exp_month, $card->exp_year ); ?></td>
			<td>
                <form action="" method="POST">
                    <?php wp_nonce_field ( 'openpay_del_card' ); ?>
                    <input type="hidden" name="openpay_delete_card" value="<?php echo esc_attr( $card->id ); ?>">
                    <input type="submit" class="button" value="<?php _e( 'Delete card', 'openpay-woosubscriptions' ); ?>">
                </form>
			</td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>