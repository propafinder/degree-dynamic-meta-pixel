<?php

defined( 'ABSPATH' ) || exit;

class DMUF_Admin {
	private $settings;
	private $telegram;
	private $conflicts = array();

	public function __construct( DMUF_Settings $settings, DMUF_Telegram $telegram ) {
		$this->settings = $settings;
		$this->telegram = $telegram;
	}

	public function register() {
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_dmuf_export_csv', array( $this, 'export_csv' ) );
		add_action( 'admin_post_dmuf_telegram_test', array( $this, 'telegram_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DMUF_FILE ), array( $this, 'action_links' ) );
	}

	public function set_conflicts( $conflicts ) {
		$this->conflicts = is_array( $conflicts ) ? $conflicts : array();
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			'Dynamic Meta Pixel',
			'Dynamic Meta Pixel',
			'manage_woocommerce',
			'dmuf-report',
			array( $this, 'render_report' )
		);

		add_submenu_page(
			'woocommerce',
			'Dynamic Meta Pixel - Настройки',
			'Dynamic Pixel: настройки',
			'manage_woocommerce',
			'dmuf-settings',
			array( $this, 'render_settings' )
		);
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'dmuf-' ) ) {
			return;
		}

		wp_enqueue_style( 'dmuf-admin', DMUF_URL . 'assets/css/admin.css', array(), DMUF_VERSION );
		if ( false !== strpos( (string) $hook, 'dmuf-settings' ) ) {
			wp_enqueue_script( 'dmuf-admin', DMUF_URL . 'assets/js/admin.js', array(), DMUF_VERSION, true );
		}
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=dmuf-report' ) ) . '">Отчёт</a>' );
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=dmuf-settings' ) ) . '">Настройки</a>' );
		return $links;
	}

	public function woocommerce_missing_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p><strong>Degree Dynamic Meta Pixel:</strong> требуется активный WooCommerce.</p></div>';
		}
	}

	public function parallel_tracking_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || empty( $this->conflicts ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>Degree Dynamic Meta Pixel:</strong> параллельно активны ' . esc_html( implode( ', ', $this->conflicts ) ) . '. Плагин не отключает их и продолжает работать по своим настройкам, но одинаковый Purchase в двух системах может дать двойной счётчик.</p></div>';
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = $this->settings->all();
		$telegram = $this->settings->telegram_config();
		$rules    = ! empty( $settings['rules'] ) ? $settings['rules'] : array(
			array( 'enabled' => 'yes', 'source' => '', 'pixel_id' => '', 'access_token' => '', 'test_event_code' => '' ),
		);
		?>
		<div class="wrap dmuf-wrap">
			<h1>Degree Dynamic Meta Pixel</h1>
			<p class="description">Источник определяется только точным значением <code>utm_source</code>. Referrer, direct и догадки не используются.</p>
			<?php settings_errors(); ?>
			<?php $this->telegram_test_notice(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'dmuf_settings_group' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Трекинг</th>
						<td><label><input type="checkbox" name="dmuf_settings[enabled]" value="1" <?php checked( 'yes', $settings['enabled'] ); ?>> Включить отправку событий и сбор UTM-воронки</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="dmuf-attribution-days">Окно атрибуции</label></th>
						<td><input id="dmuf-attribution-days" type="number" min="1" max="30" name="dmuf_settings[attribution_days]" value="<?php echo esc_attr( $settings['attribution_days'] ); ?>"> дней <p class="description">Direct-заход не перезаписывает UTM. Новый распознанный UTM начинает новое окно.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="dmuf-abandon-minutes">Checkout без заказа</label></th>
						<td><input id="dmuf-abandon-minutes" type="number" min="5" max="1440" name="dmuf_settings[abandon_minutes]" value="<?php echo esc_attr( $settings['abandon_minutes'] ); ?>"> минут <p class="description">После этого времени checkout без созданного заказа показывается как незавершённый.</p></td>
					</tr>
				</table>

				<h2>Динамические правила</h2>
				<p>Каждый источник направляется только в указанный Pixel. Сравнение <code>utm_source</code> точное, без учёта регистра.</p>
				<table class="widefat striped dmuf-rules" id="dmuf-rules">
					<thead><tr><th>Вкл.</th><th>utm_source</th><th>Pixel ID</th><th>CAPI token</th><th>Test code</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $rules as $index => $rule ) : ?>
						<?php $this->render_rule_row( $index, $rule ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="dmuf-add-rule">Добавить источник</button></p>

				<h2>Telegram: оплачено / не оплачено</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Telegram</th>
						<td><label><input type="checkbox" name="dmuf_settings[telegram_enabled]" value="1" <?php checked( $telegram['enabled'] ); ?>> Включить уведомления только для заказов с UTM</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="dmuf-telegram-token">Bot Token</label></th>
						<td><input id="dmuf-telegram-token" class="regular-text" type="password" autocomplete="new-password" maxlength="255" name="dmuf_settings[telegram_bot_token]" value="" placeholder="<?php echo $telegram['bot_token'] ? 'Сохранён; оставьте пустым' : '123456:ABC...'; ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="dmuf-telegram-chat">Chat ID</label></th>
						<td><input id="dmuf-telegram-chat" class="regular-text" type="text" maxlength="100" name="dmuf_settings[telegram_chat_id]" value="<?php echo esc_attr( $telegram['chat_id'] ); ?>" placeholder="-1001234567890"></td>
					</tr>
					<tr>
						<th scope="row"><label for="dmuf-telegram-store">Название магазина</label></th>
						<td><input id="dmuf-telegram-store" class="regular-text" type="text" maxlength="80" name="dmuf_settings[telegram_store_label]" value="<?php echo esc_attr( $telegram['store_label'] ); ?>" placeholder="GreenRoute"> <p class="description">Показывается в начале каждого уведомления.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="dmuf-telegram-unpaid">Когда считать не оплаченным</label></th>
						<td><input id="dmuf-telegram-unpaid" type="number" min="5" max="1440" name="dmuf_settings[telegram_unpaid_minutes]" value="<?php echo esc_attr( $telegram['unpaid_minutes'] ); ?>"> минут после создания заказа</td>
					</tr>
				</table>

				<?php submit_button( 'Сохранить настройки' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dmuf_telegram_test">
				<?php wp_nonce_field( 'dmuf_telegram_test' ); ?>
				<?php submit_button( 'Отправить тест в Telegram', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function telegram_test() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Недостаточно прав.' );
		}
		check_admin_referer( 'dmuf_telegram_test' );

		$result = $this->telegram->send_test_message();
		set_transient(
			'dmuf_tg_test_' . get_current_user_id(),
			array(
				'ok'      => ! empty( $result['ok'] ),
				'message' => substr( sanitize_text_field( isset( $result['message'] ) ? $result['message'] : '' ), 0, 300 ),
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=dmuf-settings' ) );
		exit;
	}

	private function telegram_test_notice() {
		$key    = 'dmuf_tg_test_' . get_current_user_id();
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );

		if ( ! empty( $result['ok'] ) ) {
			echo '<div class="notice notice-success inline"><p>Тестовое сообщение отправлено в Telegram.</p></div>';
			return;
		}

		echo '<div class="notice notice-error inline"><p>Telegram: ' . esc_html( $result['message'] ?: 'не удалось отправить сообщение.' ) . '</p></div>';
	}

	private function render_rule_row( $index, $rule ) {
		$has_token = ! empty( $rule['access_token'] );
		?>
		<tr>
			<td><input type="checkbox" name="dmuf_settings[rules][<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( 'yes', isset( $rule['enabled'] ) ? $rule['enabled'] : 'no' ); ?>></td>
			<td><input type="text" maxlength="100" name="dmuf_settings[rules][<?php echo esc_attr( $index ); ?>][source]" value="<?php echo esc_attr( isset( $rule['source'] ) ? $rule['source'] : '' ); ?>" placeholder="CapPrice"></td>
			<td><input type="text" inputmode="numeric" maxlength="32" name="dmuf_settings[rules][<?php echo esc_attr( $index ); ?>][pixel_id]" value="<?php echo esc_attr( isset( $rule['pixel_id'] ) ? $rule['pixel_id'] : '' ); ?>"></td>
			<td><input type="password" autocomplete="new-password" maxlength="4096" name="dmuf_settings[rules][<?php echo esc_attr( $index ); ?>][access_token]" value="" placeholder="<?php echo $has_token ? 'Сохранён; оставьте пустым' : 'Не задан'; ?>"></td>
			<td><input type="text" maxlength="100" name="dmuf_settings[rules][<?php echo esc_attr( $index ); ?>][test_event_code]" value="<?php echo esc_attr( isset( $rule['test_event_code'] ) ? $rule['test_event_code'] : '' ); ?>"></td>
			<td><button type="button" class="button-link-delete dmuf-remove-rule" aria-label="Удалить правило">Удалить</button></td>
		</tr>
		<?php
	}

	public function render_report() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$filters  = $this->filters();
		$sessions = $this->session_summary( $filters );
		$orders   = $this->orders( $filters );
		$summary  = $this->order_summary( $orders );
		$abandoned = $this->abandoned_sessions( $filters, 100 );
		?>
		<div class="wrap dmuf-wrap">
			<h1>Dynamic Meta Pixel: UTM-воронка</h1>
			<p class="description">В отчёте нет PageView, ViewContent и AddToCart. Учитываются только распознанные UTM-переходы, checkout и реальные статусы WooCommerce.</p>

			<form method="get" class="dmuf-filters">
				<input type="hidden" name="page" value="dmuf-report">
				<label>С <input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>"></label>
				<label>По <input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>"></label>
				<label>Источник <select name="source"><option value="">Все UTM</option><?php foreach ( $this->settings->rules() as $rule ) : ?><option value="<?php echo esc_attr( $rule['source'] ); ?>" <?php selected( $filters['source'], $rule['source'] ); ?>><?php echo esc_html( $rule['source'] ); ?></option><?php endforeach; ?></select></label>
				<label>Результат <select name="outcome"><option value="">Все</option><option value="paid" <?php selected( $filters['outcome'], 'paid' ); ?>>Купил</option><option value="unpaid" <?php selected( $filters['outcome'], 'unpaid' ); ?>>Не оплатил</option><option value="cancelled" <?php selected( $filters['outcome'], 'cancelled' ); ?>>Отмена/ошибка/refund</option></select></label>
				<button class="button button-primary">Показать</button>
				<a class="button" href="<?php echo esc_url( $this->export_url( $filters ) ); ?>">CSV</a>
			</form>

			<div class="dmuf-metrics">
				<?php $this->metric( 'UTM-переходы', $sessions['utm_visits'], 'Только распознанные utm_source' ); ?>
				<?php $this->metric( 'Начали checkout', $sessions['checkout_started'], 'Один раз на UTM-сессию' ); ?>
				<?php $this->metric( 'Без заказа', $sessions['abandoned'], wc_price( $sessions['abandoned_value'] ) . ' в корзинах' ); ?>
				<?php $this->metric( 'Создано заказов', $summary['orders'], 'Есть UTM-снимок в заказе' ); ?>
				<?php $this->metric( 'Купили', $summary['paid'], wc_price( $summary['revenue'] ) ); ?>
				<?php $this->metric( 'Не оплатили', $summary['unpaid'], wc_price( $summary['unpaid_value'] ) ); ?>
				<?php $this->metric( 'Отмена / ошибка', $summary['cancelled'], wc_price( $summary['cancelled_value'] ) ); ?>
			</div>

			<h2>Заказы</h2>
			<table class="widefat striped dmuf-orders">
				<thead><tr><th>Заказ</th><th>Дата</th><th>UTM source</th><th>Кампания</th><th>Результат</th><th>Статус</th><th>Оплата</th><th>Сумма</th><th>CAPI</th></tr></thead>
				<tbody>
				<?php if ( empty( $orders ) ) : ?><tr><td colspan="9">Заказов с выбранной UTM нет.</td></tr><?php endif; ?>
				<?php foreach ( array_slice( $orders, 0, 500 ) as $order ) : $outcome = $this->outcome( $order ); ?>
					<tr>
						<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
						<td><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '' ); ?></td>
						<td><?php echo esc_html( $order->get_meta( '_dmuf_utm_source', true ) ); ?></td>
						<td><?php echo esc_html( $order->get_meta( '_dmuf_utm_campaign', true ) ); ?></td>
						<td><span class="dmuf-outcome dmuf-<?php echo esc_attr( $outcome ); ?>"><?php echo esc_html( $this->outcome_label( $outcome ) ); ?></span></td>
						<td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
						<td><?php echo esc_html( $order->get_payment_method_title() ); ?></td>
						<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
						<td><?php echo esc_html( $order->get_meta( '_dmuf_purchase_capi_status', true ) ?: '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( count( $orders ) > 500 ) : ?><p class="description">На экране показаны первые 500 заказов; полный набор доступен в CSV.</p><?php endif; ?>

			<h2>Checkout без созданного заказа</h2>
			<p class="description">Это не доказанное «закрытие вкладки»: пользователь начал checkout, но заказ не появился за <?php echo esc_html( $this->settings->abandon_minutes() ); ?> минут.</p>
			<table class="widefat striped">
				<thead><tr><th>Checkout</th><th>UTM source</th><th>Кампания</th><th>Сумма корзины</th><th>Первый переход</th></tr></thead>
				<tbody>
				<?php if ( empty( $abandoned ) ) : ?><tr><td colspan="5">Нет незавершённых checkout.</td></tr><?php endif; ?>
				<?php foreach ( $abandoned as $row ) : ?>
					<tr><td><?php echo esc_html( $this->gmt_to_local( $row->checkout_started_at ) ); ?></td><td><?php echo esc_html( $row->utm_source ); ?></td><td><?php echo esc_html( $row->utm_campaign ); ?></td><td><?php echo wp_kses_post( wc_price( (float) $row->checkout_value, array( 'currency' => $row->checkout_currency ?: get_woocommerce_currency() ) ) ); ?></td><td><?php echo esc_html( $this->gmt_to_local( $row->first_seen ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function export_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Недостаточно прав.' );
		}
		check_admin_referer( 'dmuf_export_csv' );

		$filters = $this->filters();
		$orders  = $this->orders( $filters );
		$filename = 'dynamic-meta-utm-orders-' . $filters['from'] . '-' . $filters['to'] . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		$output = fopen( 'php://output', 'w' );
		fputs( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, array( 'order', 'date', 'utm_source', 'utm_medium', 'utm_campaign', 'outcome', 'status', 'payment', 'currency', 'amount', 'capi_status' ) );

		foreach ( $orders as $order ) {
			fputcsv(
				$output,
				array(
					$order->get_order_number(),
					$order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i:s' ) : '',
					$order->get_meta( '_dmuf_utm_source', true ),
					$order->get_meta( '_dmuf_utm_medium', true ),
					$order->get_meta( '_dmuf_utm_campaign', true ),
					$this->outcome_label( $this->outcome( $order ) ),
					$order->get_status(),
					$order->get_payment_method_title(),
					$order->get_currency(),
					$order->get_total(),
					$order->get_meta( '_dmuf_purchase_capi_status', true ),
				)
			);
		}

		fclose( $output );
		exit;
	}

	private function filters() {
		$default_from = wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
		$default_to   = wp_date( 'Y-m-d' );
		$from          = isset( $_GET['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( $_GET['from'] ) ) ? wp_unslash( $_GET['from'] ) : $default_from;
		$to            = isset( $_GET['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( $_GET['to'] ) ) ? wp_unslash( $_GET['to'] ) : $default_to;
		$source        = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
		$outcome       = isset( $_GET['outcome'] ) ? sanitize_key( wp_unslash( $_GET['outcome'] ) ) : '';

		if ( strtotime( $from ) > strtotime( $to ) ) {
			$from = $to;
		}

		if ( ( strtotime( $to ) - strtotime( $from ) ) > 93 * DAY_IN_SECONDS ) {
			$from = gmdate( 'Y-m-d', strtotime( $to ) - 93 * DAY_IN_SECONDS );
		}

		return array(
			'from'    => $from,
			'to'      => $to,
			'source'  => $source,
			'outcome' => in_array( $outcome, array( 'paid', 'unpaid', 'cancelled' ), true ) ? $outcome : '',
		);
	}

	private function date_range( $filters ) {
		$timezone = wp_timezone();
		$start    = new DateTimeImmutable( $filters['from'] . ' 00:00:00', $timezone );
		$end      = new DateTimeImmutable( $filters['to'] . ' 23:59:59', $timezone );
		return array( $start, $end );
	}

	private function session_summary( $filters ) {
		global $wpdb;
		list( $start, $end ) = $this->date_range( $filters );
		$start_gmt = gmdate( 'Y-m-d H:i:s', $start->getTimestamp() );
		$end_gmt   = gmdate( 'Y-m-d H:i:s', $end->getTimestamp() );
		$abandoned_before = gmdate( 'Y-m-d H:i:s', time() - $this->settings->abandon_minutes() * MINUTE_IN_SECONDS );
		$where = 'first_seen BETWEEN %s AND %s';
		$args  = array( $start_gmt, $end_gmt );

		if ( '' !== $filters['source'] ) {
			$where .= ' AND utm_source = %s';
			$args[] = $filters['source'];
		}

		$sql = 'SELECT COUNT(*) AS utm_visits, SUM(CASE WHEN checkout_started_at IS NOT NULL THEN 1 ELSE 0 END) AS checkout_started, SUM(CASE WHEN checkout_started_at IS NOT NULL AND checkout_started_at <= %s AND order_id IS NULL THEN 1 ELSE 0 END) AS abandoned, SUM(CASE WHEN checkout_started_at IS NOT NULL AND checkout_started_at <= %s AND order_id IS NULL THEN checkout_value ELSE 0 END) AS abandoned_value FROM ' . DMUF_Attribution::table_name() . ' WHERE ' . $where;
		array_unshift( $args, $abandoned_before );
		array_unshift( $args, $abandoned_before );
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return array(
			'utm_visits'       => isset( $row['utm_visits'] ) ? absint( $row['utm_visits'] ) : 0,
			'checkout_started' => isset( $row['checkout_started'] ) ? absint( $row['checkout_started'] ) : 0,
			'abandoned'        => isset( $row['abandoned'] ) ? absint( $row['abandoned'] ) : 0,
			'abandoned_value'  => isset( $row['abandoned_value'] ) ? (float) $row['abandoned_value'] : 0.0,
		);
	}

	private function abandoned_sessions( $filters, $limit ) {
		global $wpdb;
		list( $start, $end ) = $this->date_range( $filters );
		$where = 'first_seen BETWEEN %s AND %s AND checkout_started_at IS NOT NULL AND checkout_started_at <= %s AND order_id IS NULL';
		$args  = array(
			gmdate( 'Y-m-d H:i:s', $start->getTimestamp() ),
			gmdate( 'Y-m-d H:i:s', $end->getTimestamp() ),
			gmdate( 'Y-m-d H:i:s', time() - $this->settings->abandon_minutes() * MINUTE_IN_SECONDS ),
		);

		if ( '' !== $filters['source'] ) {
			$where .= ' AND utm_source = %s';
			$args[] = $filters['source'];
		}

		$args[] = absint( $limit );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT checkout_started_at, utm_source, utm_campaign, checkout_value, checkout_currency, first_seen FROM ' . DMUF_Attribution::table_name() . ' WHERE ' . $where . ' ORDER BY checkout_started_at DESC LIMIT %d', $args ) );
	}

	private function orders( $filters ) {
		list( $start, $end ) = $this->date_range( $filters );
		$meta_query = array(
			array( 'key' => '_dmuf_utm_source', 'compare' => 'EXISTS' ),
		);
		if ( '' !== $filters['source'] ) {
			$meta_query[] = array( 'key' => '_dmuf_utm_source', 'value' => $filters['source'], 'compare' => '=' );
		}

		$orders = wc_get_orders(
			array(
				'limit'        => 5000,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => $start->getTimestamp() . '...' . $end->getTimestamp(),
				'meta_query'   => $meta_query,
			)
		);

		if ( '' !== $filters['outcome'] ) {
			$orders = array_values(
				array_filter(
					$orders,
					function ( $order ) use ( $filters ) {
						return $this->outcome( $order ) === $filters['outcome'];
					}
				)
			);
		}

		return $orders;
	}

	private function order_summary( $orders ) {
		$summary = array( 'orders' => count( $orders ), 'paid' => 0, 'unpaid' => 0, 'cancelled' => 0, 'revenue' => 0.0, 'unpaid_value' => 0.0, 'cancelled_value' => 0.0 );
		foreach ( $orders as $order ) {
			$outcome = $this->outcome( $order );
			$summary[ $outcome ]++;
			if ( 'paid' === $outcome ) {
				$summary['revenue'] += (float) $order->get_total();
			} elseif ( 'unpaid' === $outcome ) {
				$summary['unpaid_value'] += (float) $order->get_total();
			} else {
				$summary['cancelled_value'] += (float) $order->get_total();
			}
		}
		return $summary;
	}

	private function outcome( WC_Order $order ) {
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			return 'cancelled';
		}
		return $order->is_paid() ? 'paid' : 'unpaid';
	}

	private function outcome_label( $outcome ) {
		$labels = array( 'paid' => 'Купил', 'unpaid' => 'Не оплатил', 'cancelled' => 'Отмена/ошибка' );
		return isset( $labels[ $outcome ] ) ? $labels[ $outcome ] : $outcome;
	}

	private function metric( $label, $value, $detail ) {
		echo '<div class="dmuf-metric"><span>' . esc_html( $label ) . '</span><strong>' . wp_kses_post( (string) $value ) . '</strong><small>' . wp_kses_post( (string) $detail ) . '</small></div>';
	}

	private function export_url( $filters ) {
		$url = add_query_arg(
			array_merge( array( 'action' => 'dmuf_export_csv' ), $filters ),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'dmuf_export_csv' );
	}

	private function gmt_to_local( $value ) {
		return $value ? get_date_from_gmt( $value, 'Y-m-d H:i' ) : '';
	}
}
