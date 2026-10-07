# TRIUNITECH お問い合わせ管理（デモ）

WordPress 用のお問い合わせフォーム＋管理プラグインです。

> **自社制作のデモです（受託案件ではありません）。**
> TRIUNITECH が、WordPress 開発の進め方・コードの書き方をご確認いただくために作成しました。

## できること

- ショートコード `[tt_inquiry_form]` を固定ページに貼るだけで、お問い合わせフォームを表示
- 送信内容を専用テーブル（`{prefix}tt_inquiries`）に保存
- 管理画面「お問い合わせ」で一覧・絞り込み（未対応／対応中／完了）・検索・ページ送り
- 対応状況の変更、社内用の対応メモ、削除
- 絞り込み結果の CSV 出力（Excel で文字化けしない UTF-8 BOM 付き）
- 受付時のメール通知（宛先は設定画面で変更可。未設定ならサイト管理者）
- プラグイン削除時にデータと設定を消去（`uninstall.php`）

## セキュリティ面の配慮

| 項目 | 対応 |
|---|---|
| CSRF | フォーム・管理操作とも nonce を検証 |
| 権限 | 管理操作は `manage_options` 権限を必ず確認 |
| SQL | `$wpdb->prepare()` / `insert` / `update` / `delete` のプレースホルダを使用 |
| XSS | 出力時に `esc_html` / `esc_attr` / `esc_textarea` / `esc_url` |
| スパム | ハニーポット欄 ＋ 同一アクセス元から 10 分間に 5 件までの制限 |
| CSV | 先頭が `= + - @` のセルを無効化（数式インジェクション対策） |
| メールヘッダ | 名前に含まれる記号・改行を除去してから Reply-To に設定 |

## 動作環境

WordPress 6.0 以上 / PHP 7.4 以上

## 導入方法

1. `tt-inquiry-log` フォルダを `wp-content/plugins/` に配置
2. 管理画面「プラグイン」で有効化（テーブルが自動作成されます）
3. 固定ページに `[tt_inquiry_form]` を記載して公開
4. 「お問い合わせ → 通知設定」で通知先メールを設定（任意）

## ファイル構成

```
tt-inquiry-log/
├── tt-inquiry-log.php   本体（1 クラスに集約。入力チェックは validate() に分離）
└── uninstall.php        削除時の後始末
blueprint.json           WordPress Playground 用の起動設定（ブラウザ上でお試し可能）
```

## ブラウザで試す

[WordPress Playground](https://playground.wordpress.net/) に `blueprint.json` を読み込むと、インストール不要で動作を確認できます（サンプルデータ 3 件入り）。

## 制限事項（デモのため）

- フォームの項目追加は管理画面ではできません（コードで変更）
- 添付ファイル・reCAPTCHA・多言語化（翻訳ファイル）は未対応
- 実案件では、ご要望に合わせて上記を追加いたします

## ライセンス

GPL-2.0-or-later
