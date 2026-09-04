# Security

## Authorization

- All admin pages/actions require `manage_woocommerce`; the product preview
  AJAX requires `edit_products`; nothing is exposed to unauthenticated
  users. There is **no public endpoint** — the frontend never triggers a
  sync.
- Every admin-post action (`gpu_sync_now`, `gpu_force_sync`,
  `gpu_test_api`) and every AJAX call (`gpu_progress`,
  `gpu_preview_price`) verifies a nonce (`check_admin_referer` /
  `check_ajax_referer`) before doing anything.
- Product meta saving checks `current_user_can('edit_post', $id)`, a nonce,
  and skips autosave.

## Credential handling

- API key/secret are stored in the (autoloaded) settings option, writable
  only through the settings form by shop managers.
- The dashboard shows at most a **masked** key (`abcd***xy`).
- `GPU_Logger` redacts the configured key/secret plus generic
  `Bearer …` / `api_key=…` patterns from messages, and masks
  credential-named keys in JSON context before writing.
- API responses are never stored raw; only a sanitized ≤300-char summary is
  kept, and only the shape (digits replaced by `#`) unless debug mode is on.

## Input handling

- All stored settings pass explicit per-field sanitization/validation
  (`sanitize_key`, whitelist checks for enums, `esc_url_raw`, numeric
  bounds) in `GPU_Admin::save_settings()`.
- All SQL goes through `$wpdb->prepare` or `$wpdb->insert/update` formats;
  dynamic table names come from `$wpdb->prefix` + fixed constants only.
- Output escaping: `esc_html`, `esc_attr`, `esc_url` throughout the views;
  JSON endpoints use `wp_send_json_*`.

## Transport & misc

- HTTP requests use `wp_remote_request` with `sslverify => true`.
- Direct file access is blocked in every file (`ABSPATH` guard; pure classes
  additionally allow the `GPU_TESTING` constant used only by the test
  bootstrap).
- The GitHub updater talks only to `api.github.com` over HTTPS and treats
  the repo string as an allow-listed `owner/name` pattern.
