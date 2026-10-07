<?php
/**
 * Plugin Name:       TRIUNITECH お問い合わせ管理（デモ）
 * Plugin URI:        https://www.triunitech.com/ja/
 * Description:       お問い合わせフォーム（ショートコード）、データベース保存、管理画面での対応状況管理、CSV出力、メール通知を行うデモプラグインです。TRIUNITECH 自社制作のデモであり、受託案件ではありません。
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            TRIUNITECH
 * Author URI:        https://www.triunitech.com/ja/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tt-inquiry-log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TT_INQ_VERSION', '1.0.0' );
define( 'TT_INQ_DB_VERSION', '1' );

final class TT_Inquiry_Log {

	const CAPABILITY      = 'manage_options';
	const MENU_SLUG       = 'tt-inquiries';
	const SETTINGS_SLUG   = 'tt-inquiries-settings';
	const PER_PAGE        = 20;
	const RATE_LIMIT      = 5;   // 同一アクセス元から、期間内に受け付ける件数
	const RATE_WINDOW     = 600; // 秒（10 分）
	const MESSAGE_MIN_LEN = 10;
	const MESSAGE_MAX_LEN = 3000;

	/** フォーム送信の結果（同一リクエスト内で shortcode に渡す） */
	private static $errors = array();
	private static $old    = array();

	/* ------------------------------------------------------------------
	 * 定義値
	 * ---------------------------------------------------------------- */

	public static function statuses() {
		return array(
			'new'     => '未対応',
			'working' => '対応中',
			'done'    => '完了',
		);
	}

	public static function categories() {
		return array(
			'estimate' => 'お見積り・ご相談',
			'support'  => 'ご利用方法・不具合',
			'other'    => 'その他',
		);
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tt_inquiries';
	}

	/* ------------------------------------------------------------------
	 * 初期化
	 * ---------------------------------------------------------------- */

	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );

		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_shortcode( 'tt_inquiry_form', array( __CLASS__, 'render_form' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_submit' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'admin_post_tt_inq_status', array( __CLASS__, 'action_status' ) );
			add_action( 'admin_post_tt_inq_note', array( __CLASS__, 'action_note' ) );
			add_action( 'admin_post_tt_inq_delete', array( __CLASS__, 'action_delete' ) );
			add_action( 'admin_post_tt_inq_export', array( __CLASS__, 'action_export' ) );
		}
	}

	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta の書式に合わせる（PRIMARY KEY の後ろは半角スペース 2 つ）
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  name varchar(100) NOT NULL,
  email varchar(190) NOT NULL,
  phone varchar(30) NOT NULL DEFAULT '',
  category varchar(20) NOT NULL DEFAULT 'other',
  message text NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'new',
  admin_note text NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY created_at (created_at)
) {$charset};";

		dbDelta( $sql );
		update_option( 'tt_inq_db_version', TT_INQ_DB_VERSION );
	}

	/** 有効化フックが走らない導入方法（手動コピー等）でもテーブルを用意する */
	public static function maybe_upgrade() {
		if ( get_option( 'tt_inq_db_version' ) !== TT_INQ_DB_VERSION ) {
			self::activate();
		}
	}

	/* ------------------------------------------------------------------
	 * 入力チェック（画面から独立させ、単体で検証しやすくしている）
	 * ---------------------------------------------------------------- */

	private static function strlen( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	/** 全角数字・全角ハイフンを半角へ */
	public static function normalize_phone( $phone ) {
		$phone = (string) $phone;
		if ( function_exists( 'mb_convert_kana' ) ) {
			$phone = mb_convert_kana( $phone, 'n', 'UTF-8' );
		}
		return trim( str_replace( array( '－', 'ー', '‐', '―' ), '-', $phone ) );
	}

	/**
	 * @param array $in 未加工の入力（unslash 済み）
	 * @return array{0: array, 1: string[]} [整形後データ, エラー一覧]
	 */
	public static function validate( array $in ) {
		$errors = array();
		$data   = array(
			'name'     => sanitize_text_field( isset( $in['name'] ) ? $in['name'] : '' ),
			'email'    => sanitize_email( isset( $in['email'] ) ? $in['email'] : '' ),
			'phone'    => sanitize_text_field( self::normalize_phone( isset( $in['phone'] ) ? $in['phone'] : '' ) ),
			'category' => sanitize_key( isset( $in['category'] ) ? $in['category'] : '' ),
			'message'  => sanitize_textarea_field( isset( $in['message'] ) ? $in['message'] : '' ),
		);

		if ( '' === $data['name'] ) {
			$errors[] = 'お名前を入力してください。';
		} elseif ( self::strlen( $data['name'] ) > 100 ) {
			$errors[] = 'お名前は100文字以内で入力してください。';
		}

		if ( '' === $data['email'] || ! is_email( $data['email'] ) ) {
			$errors[] = 'メールアドレスの形式が正しくありません。';
		}

		if ( '' !== $data['phone'] && ! preg_match( '/^[0-9+\-() ]{6,30}$/', $data['phone'] ) ) {
			$errors[] = '電話番号の形式が正しくありません（例：090-1234-5678）。';
		}

		if ( ! array_key_exists( $data['category'], self::categories() ) ) {
			$errors[] = 'ご相談の種類を選択してください。';
		}

		$len = self::strlen( $data['message'] );
		if ( $len < self::MESSAGE_MIN_LEN ) {
			$errors[] = sprintf( 'お問い合わせ内容は%d文字以上でご入力ください。', self::MESSAGE_MIN_LEN );
		} elseif ( $len > self::MESSAGE_MAX_LEN ) {
			$errors[] = sprintf( 'お問い合わせ内容は%d文字以内でご入力ください。', self::MESSAGE_MAX_LEN );
		}

		if ( empty( $in['consent'] ) ) {
			$errors[] = '個人情報の取り扱いへの同意にチェックをお願いします。';
		}

		return array( $data, $errors );
	}

	/* ------------------------------------------------------------------
	 * フロント：フォーム表示と送信処理
	 * ---------------------------------------------------------------- */

	private static function client_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'tt_inq_rl_' . md5( $ip );
	}

	public static function handle_submit() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		if ( empty( $_POST['tt_inq_action'] ) || 'submit' !== $_POST['tt_inq_action'] ) {
			return;
		}

		$nonce = isset( $_POST['tt_inq_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tt_inq_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'tt_inq_submit' ) ) {
			self::$errors[] = 'ページの有効期限が切れました。お手数ですが、ページを再読み込みしてもう一度お試しください。';
			self::$old      = self::collect_old();
			return;
		}

		// ハニーポット：人間には見えない欄に入力があれば、成功したように見せて破棄する
		if ( ! empty( $_POST['tt_inq_website'] ) ) {
			self::redirect_done();
		}

		$raw = wp_unslash( $_POST );
		list( $data, $errors ) = self::validate( $raw );

		$key   = self::client_key();
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			$errors[] = '短時間に送信が集中しています。しばらく時間をおいてからお試しください。';
		}

		if ( $errors ) {
			self::$errors = $errors;
			self::$old    = self::collect_old();
			return;
		}

		$id = self::insert( $data );
		if ( ! $id ) {
			self::$errors[] = '送信を受け付けられませんでした。時間をおいて再度お試しください。';
			self::$old      = self::collect_old();
			return;
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );
		self::notify( $id, $data );
		self::redirect_done();
	}

	private static function collect_old() {
		$old = array();
		foreach ( array( 'name', 'email', 'phone', 'category', 'message' ) as $k ) {
			$old[ $k ] = isset( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : '';
		}
		$old['consent'] = ! empty( $_POST['consent'] );
		return $old;
	}

	private static function redirect_done() {
		$url = remove_query_arg( 'tt_status' );
		$url = add_query_arg( 'tt_status', 'ok', $url ) . '#tt-inquiry-form';
		wp_safe_redirect( $url );
		exit;
	}

	/** 保存。成功時は ID、失敗時は 0 */
	public static function insert( array $data ) {
		global $wpdb;
		$ok = $wpdb->insert(
			self::table(),
			array(
				'created_at' => current_time( 'mysql' ),
				'name'       => $data['name'],
				'email'      => $data['email'],
				'phone'      => $data['phone'],
				'category'   => $data['category'],
				'message'    => $data['message'],
				'status'     => 'new',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	private static function notify( $id, array $data ) {
		$to = get_option( 'tt_inq_notify_email' );
		if ( ! $to || ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}
		$cats = self::categories();
		$body = implode(
			"\n",
			array(
				'お問い合わせを受け付けました。',
				'',
				'受付番号：' . $id,
				'お名前：' . $data['name'],
				'メール：' . $data['email'],
				'電話番号：' . ( '' !== $data['phone'] ? $data['phone'] : '（未入力）' ),
				'種類：' . $cats[ $data['category'] ],
				'',
				$data['message'],
				'',
				'管理画面：' . admin_url( 'admin.php?page=' . self::MENU_SLUG ),
			)
		);
		// 名前に含まれる記号でヘッダが壊れないよう除去する
		$reply_name = trim( str_replace( array( '"', '<', '>', ',', ';', "\r", "\n" ), '', $data['name'] ) );
		$headers    = array( 'Reply-To: ' . $reply_name . ' <' . $data['email'] . '>' );
		// 通知メールの失敗は、受付そのものには影響させない
		wp_mail( $to, '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] お問い合わせ #' . $id, $body, $headers );
	}

	public static function render_form() {
		wp_register_style( 'tt-inq', false, array(), TT_INQ_VERSION );
		wp_enqueue_style( 'tt-inq' );
		wp_add_inline_style(
			'tt-inq',
			'.tt-inq{max-width:640px;margin:1.5em 0;font-size:16px}.tt-inq label{display:block;margin:0 0 1em;font-weight:600}'
			. '.tt-inq input[type=text],.tt-inq input[type=email],.tt-inq input[type=tel],.tt-inq select,.tt-inq textarea{display:block;width:100%;margin-top:.3em;padding:.6em;border:1px solid #c3ccc7;border-radius:6px;font:inherit;font-weight:400;box-sizing:border-box}'
			. '.tt-inq .tt-req{color:#c0392b;font-size:.8em;margin-left:.4em}.tt-inq .tt-consent{font-weight:400}'
			. '.tt-inq button{background:#1a7f4b;color:#fff;border:0;border-radius:6px;padding:.7em 1.8em;font:inherit;font-weight:600;cursor:pointer}'
			. '.tt-inq .tt-notice{padding:.8em 1em;border-radius:6px;margin-bottom:1em}.tt-inq .tt-ok{background:#e6f6ec;color:#0a2b1d}'
			. '.tt-inq .tt-err{background:#fdecea;color:#a93226}.tt-inq .tt-err ul{margin:0;padding-left:1.2em}'
			. '.tt-inq .tt-hp{position:absolute;left:-9999px;height:0;overflow:hidden}'
		);

		$old = wp_parse_args(
			self::$old,
			array(
				'name'     => '',
				'email'    => '',
				'phone'    => '',
				'category' => 'estimate',
				'message'  => '',
				'consent'  => false,
			)
		);

		ob_start();
		?>
		<div class="tt-inq" id="tt-inquiry-form">
			<?php if ( isset( $_GET['tt_status'] ) && 'ok' === $_GET['tt_status'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="tt-notice tt-ok" role="status">お問い合わせを受け付けました。内容を確認のうえ、1営業日以内にご連絡いたします。</div>
			<?php endif; ?>

			<?php if ( self::$errors ) : ?>
				<div class="tt-notice tt-err" role="alert">
					<ul>
						<?php foreach ( self::$errors as $e ) : ?>
							<li><?php echo esc_html( $e ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( get_permalink() ? get_permalink() . '#tt-inquiry-form' : '' ); ?>" novalidate>
				<input type="hidden" name="tt_inq_action" value="submit">
				<input type="hidden" name="tt_inq_nonce" value="<?php echo esc_attr( wp_create_nonce( 'tt_inq_submit' ) ); ?>">
				<div class="tt-hp" aria-hidden="true">
					<label>Website <input type="text" name="tt_inq_website" tabindex="-1" autocomplete="off"></label>
				</div>

				<label>お名前<span class="tt-req">必須</span>
					<input type="text" name="name" maxlength="100" value="<?php echo esc_attr( $old['name'] ); ?>" autocomplete="name">
				</label>
				<label>メールアドレス<span class="tt-req">必須</span>
					<input type="email" name="email" value="<?php echo esc_attr( $old['email'] ); ?>" autocomplete="email">
				</label>
				<label>電話番号（任意）
					<input type="tel" name="phone" value="<?php echo esc_attr( $old['phone'] ); ?>" autocomplete="tel" placeholder="090-1234-5678">
				</label>
				<label>ご相談の種類<span class="tt-req">必須</span>
					<select name="category">
						<?php foreach ( self::categories() as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $old['category'], $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>お問い合わせ内容<span class="tt-req">必須</span>
					<textarea name="message" rows="6" maxlength="<?php echo esc_attr( self::MESSAGE_MAX_LEN ); ?>"><?php echo esc_textarea( $old['message'] ); ?></textarea>
				</label>
				<label class="tt-consent">
					<input type="checkbox" name="consent" value="1" <?php checked( $old['consent'] ); ?>>
					ご入力いただいた情報は、お問い合わせへの対応の目的にのみ利用することに同意します。
				</label>
				<button type="submit">送信する</button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * 管理画面
	 * ---------------------------------------------------------------- */

	public static function count_new() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'new' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function register_menu() {
		$new   = self::count_new();
		$badge = $new > 0 ? ' <span class="awaiting-mod">' . (int) $new . '</span>' : '';

		add_menu_page(
			'お問い合わせ管理',
			'お問い合わせ' . $badge,
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_list' ),
			'dashicons-email-alt',
			26
		);
		add_submenu_page( self::MENU_SLUG, 'お問い合わせ一覧', '一覧', self::CAPABILITY, self::MENU_SLUG, array( __CLASS__, 'render_list' ) );
		add_submenu_page( self::MENU_SLUG, '通知設定', '通知設定', self::CAPABILITY, self::SETTINGS_SLUG, array( __CLASS__, 'render_settings' ) );
	}

	public static function register_settings() {
		register_setting(
			'tt_inq_settings',
			'tt_inq_notify_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => '',
			)
		);
	}

	public static function render_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( '権限がありません。' );
		}
		?>
		<div class="wrap">
			<h1>通知設定</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'tt_inq_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tt_inq_notify_email">通知先メールアドレス</label></th>
						<td>
							<input type="email" id="tt_inq_notify_email" name="tt_inq_notify_email" class="regular-text"
								value="<?php echo esc_attr( get_option( 'tt_inq_notify_email', '' ) ); ?>">
							<p class="description">未入力の場合は、サイト管理者のメールアドレス（<?php echo esc_html( get_option( 'admin_email' ) ); ?>）に通知します。</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/** 一覧の絞り込み条件（一覧表示と CSV 出力で共通） */
	private static function build_where( $status, $search ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		if ( $status && array_key_exists( $status, self::statuses() ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(name LIKE %s OR email LIKE %s OR message LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		return array( implode( ' AND ', $where ), $params );
	}

	private static function list_url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
	}

	public static function render_list() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( '権限がありません。' );
		}
		global $wpdb;
		$table = self::table();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 閲覧用の絞り込み条件のみ
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		list( $where, $params ) = self::build_where( $status, $search );

		$sql_total = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql_total, $params ) ) : $wpdb->get_var( $sql_total ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$offset    = ( $paged - 1 ) * self::PER_PAGE;
		$sql_rows  = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $sql_rows, array_merge( $params, array( self::PER_PAGE, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$counts = array();
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$counts[ $r->status ] = (int) $r->c;
		}
		$all = array_sum( $counts );

		$cats     = self::categories();
		$statuses = self::statuses();
		$notice   = isset( $_GET['tt_msg'] ) ? sanitize_key( wp_unslash( $_GET['tt_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'status'  => '対応状況を更新しました。',
			'note'    => '対応メモを保存しました。',
			'deleted' => '削除しました。',
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">お問い合わせ管理</h1>
			<?php
			$export_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'tt_inq_export',
						'status' => $status,
						's'      => $search,
					),
					admin_url( 'admin-post.php' )
				),
				'tt_inq_export'
			);
			?>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">CSV出力（絞り込み結果）</a>
			<hr class="wp-header-end">

			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
			<?php endif; ?>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( self::list_url() ); ?>" <?php echo '' === $status ? 'class="current"' : ''; ?>>すべて <span class="count">（<?php echo (int) $all; ?>）</span></a> |</li>
				<?php
				$i = 0;
				foreach ( $statuses as $k => $label ) :
					++$i;
					?>
					<li><a href="<?php echo esc_url( self::list_url( array( 'status' => $k ) ) ); ?>" <?php echo $status === $k ? 'class="current"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="count">（<?php echo isset( $counts[ $k ] ) ? (int) $counts[ $k ] : 0; ?>）</span></a><?php echo $i < count( $statuses ) ? ' |' : ''; ?></li>
				<?php endforeach; ?>
			</ul>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<?php if ( $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php endif; ?>
				<p class="search-box">
					<label class="screen-reader-text" for="tt-search">検索</label>
					<input type="search" id="tt-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="お名前・メール・内容">
					<input type="submit" class="button" value="検索">
				</p>
			</form>

			<table class="widefat striped" style="margin-top:8px">
				<thead>
					<tr>
						<th style="width:56px">No.</th>
						<th style="width:140px">受付日時</th>
						<th>お名前 / 連絡先</th>
						<th style="width:150px">種類</th>
						<th>内容・対応メモ</th>
						<th style="width:150px">対応状況</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6">該当するお問い合わせはありません。</td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo (int) $r->id; ?></td>
						<td><?php echo esc_html( $r->created_at ); ?></td>
						<td>
							<strong><?php echo esc_html( $r->name ); ?></strong><br>
							<a href="mailto:<?php echo esc_attr( $r->email ); ?>"><?php echo esc_html( $r->email ); ?></a>
							<?php if ( '' !== $r->phone ) : ?>
								<br><?php echo esc_html( $r->phone ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $cats[ $r->category ] ) ? $cats[ $r->category ] : $r->category ); ?></td>
						<td>
							<details>
								<summary><?php echo esc_html( wp_trim_words( $r->message, 20, '…' ) ); ?></summary>
								<p style="white-space:pre-wrap"><?php echo esc_html( $r->message ); ?></p>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="tt_inq_note">
									<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
									<?php wp_nonce_field( 'tt_inq_note_' . (int) $r->id ); ?>
									<textarea name="admin_note" rows="3" class="large-text" placeholder="対応メモ（社内用。お客様には表示されません）"><?php echo esc_textarea( (string) $r->admin_note ); ?></textarea>
									<p><button type="submit" class="button">メモを保存</button></p>
								</form>
							</details>
						</td>
						<td>
							<?php foreach ( $statuses as $k => $label ) : ?>
								<?php if ( $k === $r->status ) : ?>
									<strong><?php echo esc_html( $label ); ?></strong>
								<?php else : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tt_inq_status&id=' . (int) $r->id . '&status=' . $k ), 'tt_inq_status_' . (int) $r->id ) ); ?>"><?php echo esc_html( $label ); ?></a>
								<?php endif; ?>
								<br>
							<?php endforeach; ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tt_inq_delete&id=' . (int) $r->id ), 'tt_inq_delete_' . (int) $r->id ) ); ?>"
								style="color:#b32d2e" onclick="return confirm('このお問い合わせを削除します。よろしいですか？');">削除</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) ceil( $total / self::PER_PAGE );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * 管理画面のアクション（権限 + nonce を必ず確認）
	 * ---------------------------------------------------------------- */

	private static function guard( $nonce_action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( '権限がありません。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function back( $msg ) {
		$ref = wp_get_referer();
		$url = $ref ? remove_query_arg( 'tt_msg', $ref ) : admin_url( 'admin.php?page=' . self::MENU_SLUG );
		wp_safe_redirect( add_query_arg( 'tt_msg', $msg, $url ) );
		exit;
	}

	public static function action_status() {
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		self::guard( 'tt_inq_status_' . $id );

		if ( $id && array_key_exists( $status, self::statuses() ) ) {
			global $wpdb;
			$wpdb->update( self::table(), array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		}
		self::back( 'status' );
	}

	public static function action_note() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::guard( 'tt_inq_note_' . $id );

		if ( $id ) {
			global $wpdb;
			$note = isset( $_POST['admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : '';
			$wpdb->update( self::table(), array( 'admin_note' => $note ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		}
		self::back( 'note' );
	}

	public static function action_delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::guard( 'tt_inq_delete_' . $id );

		if ( $id ) {
			global $wpdb;
			$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		}
		self::back( 'deleted' );
	}

	/** CSV のセルが数式として解釈されないようにする（CSV インジェクション対策） */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^\+?[0-9][0-9\-() ]*$/', $value ) ) {
			return $value; // 電話番号はそのまま
		}
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}

	public static function action_export() {
		self::guard( 'tt_inq_export' );
		global $wpdb;
		$table = self::table();

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		list( $where, $params ) = self::build_where( $status, $search );

		$sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$cats     = self::categories();
		$statuses = self::statuses();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="inquiries-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel で文字化けしないよう BOM を付与
		fputcsv( $out, array( 'No', '受付日時', 'お名前', 'メール', '電話番号', '種類', '内容', '対応状況', '対応メモ' ) );
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array(
						$r->id,
						$r->created_at,
						$r->name,
						$r->email,
						$r->phone,
						isset( $cats[ $r->category ] ) ? $cats[ $r->category ] : $r->category,
						$r->message,
						isset( $statuses[ $r->status ] ) ? $statuses[ $r->status ] : $r->status,
						(string) $r->admin_note,
					)
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}

TT_Inquiry_Log::init();
