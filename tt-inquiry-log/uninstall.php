<?php
/**
 * プラグインを「削除」したときに、保存したデータと設定を消去します。
 * （「無効化」だけではデータは残ります。）
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$table = $wpdb->prefix . 'tt_inquiries';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

delete_option( 'tt_inq_db_version' );
delete_option( 'tt_inq_notify_email' );
