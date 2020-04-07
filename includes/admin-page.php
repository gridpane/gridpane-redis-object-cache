
<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="wrap">

    <h1><?php _e( 'GridPane Redis Object Cache', 'gridpane-redis-object-cache' ); ?></h1>

    <div class="section-overview">

        <h2 class="title"><?php _e( 'Overview', 'gridpane-redis-object-cache' ); ?></h2>

        <table class="form-table">

            <tr>
                <th><?php _e( 'Status:', 'gridpane-redis-object-cache' ); ?></th>
                <td><code><?php echo $this->get_status(); ?></code></td>
            </tr>

			<?php if ( ! is_null( $this->get_redis_client_name() ) ) : ?>
                <tr>
                    <th><?php _e( 'Client:', 'gridpane-redis-object-cache' ); ?></th>
                    <td><code><?php echo esc_html( $this->get_redis_client_name() ); ?></code></td>
                </tr>
			<?php endif; ?>

			<?php if ( ! is_null( $this->get_redis_cachekey_prefix() ) && trim( $this->get_redis_cachekey_prefix() ) !== '' ) : ?>
                <tr>
                    <th><?php _e( 'Key Prefix:', 'gridpane-redis-object-cache' ); ?></th>
                    <td><code><?php echo esc_html( $this->get_redis_cachekey_prefix() ); ?></code></td>
                </tr>
			<?php endif; ?>

			<?php if ( ! is_null( $this->get_redis_maxttl() ) ) : ?>
                <tr>
                    <th><?php _e( 'Max. TTL:', 'gridpane-redis-object-cache' ); ?></th>
                    <td><code><?php echo esc_html( $this->get_redis_maxttl() ); ?></code></td>
                </tr>
			<?php endif; ?>

        </table>

        <p class="submit">

			<?php if ( $this->get_redis_status() ) : ?>
                <a href="<?php echo wp_nonce_url( network_admin_url( add_query_arg( 'action', 'flush-cache', $this->page ) ), 'flush-cache' ); ?>" class="button button-primary button-large flush"><?php _e( 'Flush Cache', 'gridpane-redis-object-cache' ); ?></a> &nbsp;
			<?php endif; ?>

			<?php if ( ! $this->object_cache_dropin_exists() ) : ?>
                <a href="<?php echo wp_nonce_url( network_admin_url( add_query_arg( 'action', 'enable-cache', $this->page ) ), 'enable-cache' ); ?>" class="button button-primary button-large enable"><?php _e( 'Enable Object Cache', 'gridpane-redis-object-cache' ); ?></a>
			<?php elseif ( $this->validate_object_cache_dropin() ) : ?>
                <a href="<?php echo wp_nonce_url( network_admin_url( add_query_arg( 'action', 'disable-cache', $this->page ) ), 'disable-cache' ); ?>" class="button button-secondary button-large disable"><?php _e( 'Disable Object Cache', 'gridpane-redis-object-cache' ); ?></a>
			<?php endif; ?>

        </p>

    </div>

    <br class="clearfix">

    <h2 class="title">
		<?php _e( 'Redis Servers\' Connection Details', 'gridpane-redis-object-cache' ); ?>
    </h2>

	<?php $this->show_servers_list(); ?>

    <?php if ( ! is_multisite() ) { ?>
        <form class="tools" method="post" action="options.php">
            <?php
            settings_fields( 'gridpane-redis-object-cache-settings' );
            do_settings_sections( 'gridpane-redis-object-cache-settings' );
            submit_button();
            ?>
        </form>
    <?php } ?>
	<?php if ( isset( $_GET[ 'diagnostics' ] ) ) : ?>

        <h2 class="title"><?php _e( 'Diagnostics', 'gridpane-redis-object-cache' ); ?></h2>

        <textarea class="large-text readonly" rows="20" readonly><?php include dirname( __FILE__ ) . '/diagnostics.php'; ?></textarea>

	<?php else : ?>

        <p><a href="<?php echo network_admin_url( add_query_arg( 'diagnostics', '1', $this->page ) ); ?>"><?php _e( 'Show Diagnostics', 'gridpane-redis-object-cache' ); ?></a></p>

	<?php endif; ?>

    <br>

    <p>We have forked the plugin from the <a href="https://wordpress.org/plugins/redis-cache/" target="_blank">original</a> to ensure update stability, the origin plugin is maintained by Till Krüss and you can <a href="https://www.paypal.me/tillkruss" target="_blank">donate to him here.</a></p>

</div>


