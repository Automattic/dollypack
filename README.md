# Dollypack

WordPress abilities pack that extends [Dolly](https://wordpress.com), the WordPress.com AI agent, with new capabilities. Abilities run remotely on self-hosted WordPress sites through the Jetpack connection.


## Requirements

- WordPress 6.9+
- Jetpack connected to WordPress.com

## Plugin packages

Releases publish four installable plugin ZIPs:

| Plugin | Contents | Dependency |
|---|---|---|
| `dollypack-core` | Shared runtime, settings UI, and `wp-remote-request`. | None |
| `dollypack-github` | All GitHub abilities plus the GitHub service parent class. | `dollypack-core` |
| `dollypack-google` | Google Calendar read plus the Google service parent class. | `dollypack-core` |
| `dollypack-full` | Core + GitHub + Google in one standalone plugin. | None |

Release assets are built from `config/packages.php` by `scripts/build-packages.php`. The repository root plugin mirrors the full bundle for development only and should not be installed alongside any packaged Dollypack plugin.

## Installation

1. Download the plugin ZIP you want from the [GitHub Releases page](https://github.com/Automattic/dollypack/releases).
2. Choose the package that matches your setup:
   - `dollypack-full` for a single plugin with all Dollypack abilities.
   - `dollypack-core` for the shared runtime and `wp-remote-request`.
   - `dollypack-github` for GitHub abilities, which requires `dollypack-core`.
   - `dollypack-google` for Google Calendar abilities, which requires `dollypack-core`.
3. In WordPress admin, go to **Plugins > Add New Plugin > Upload Plugin** and upload the ZIP file.
4. Activate the plugin after upload. If you are installing `dollypack-github` or `dollypack-google`, install and activate `dollypack-core` first.
5. Confirm Jetpack is connected to WordPress.com, then configure Dollypack under **Settings > Dollypack**.

## Current abilities

| Ability ID | Package | Class | Description | Annotations |
|---|---|---|---|---|
| `wp-remote-request` | `dollypack-core` | `Dollypack_WP_Remote_Request` | Perform an HTTP request using `wp_remote_request()`. | `idempotent` |
| `github-read` | `dollypack-github` | `Dollypack_GitHub_Read` | Read files, directory listings, and repository metadata from the GitHub API. | `readonly`, `idempotent` |
| `github-notifications` | `dollypack-github` | `Dollypack_GitHub_Notifications` | List and manage GitHub notifications (list, mark-read). | `idempotent` |
| `github-search` | `dollypack-github` | `Dollypack_GitHub_Search` | Search GitHub for code, issues, repositories, or commits. | `readonly`, `idempotent` |
| `github-write` | `dollypack-github` | `Dollypack_GitHub_Write` | Create or update resources on GitHub — issues, comments, pull requests, etc. | `destructive` |
| `google-calendar-read` | `dollypack-google` | `Dollypack_Google_Calendar_Read` | Read calendars and events from Google Calendar (list_calendars, list_events, get_event). | `readonly`, `idempotent` |

## Adding a new ability

### 1. Create the ability class

Create a file in `abilities/` with a class extending `Dollypack_Ability` (or a service-specific parent like `Dollypack_GitHub_Ability`).

```
Dollypack_Ability (abstract)
├── Dollypack_WP_Remote_Request
├── Dollypack_GitHub_Ability (abstract, shared $settings + github_request())
│   ├── Dollypack_GitHub_Read
│   ├── Dollypack_GitHub_Notifications
│   ├── Dollypack_GitHub_Search
│   └── Dollypack_GitHub_Write
└── Dollypack_Google_Ability (abstract, OAuth 2.0 + google_request())
    └── Dollypack_Google_Calendar_Read
```

### 2. Implement required methods

- **`execute( $input )`** — performs the action and returns a result array or `WP_Error`.
- **`get_input_schema()`** — returns a JSON Schema array describing accepted input.
- **`get_output_schema()`** — returns a JSON Schema array describing the output.
- **`get_meta()`** — returns metadata including `annotations` (`readonly`, `destructive`, `idempotent`) and `show_in_rest`.

### 3. Register the ability

Register the ability with `Dollypack_Runtime` in the relevant package bootstrap:

- `packages/core/bootstrap.php` for core abilities
- `packages/github/bootstrap.php` for GitHub abilities
- `packages/google/bootstrap.php` for Google abilities
- `packages/full/bootstrap.php` for the standalone full bundle

Use the plugin directory provided by the entrypoint, which is resolved with `plugin_dir_path( __FILE__ )`.

```php
Dollypack_Runtime::register_ability(
    'my-ability',
    array(
        'file'  => $dollypack_plugin_dir . 'abilities/my-ability.php',
        'class' => 'Dollypack_My_Ability',
    )
);
```

### 4. Add the ability to package manifests

Update `config/packages.php` so the ability is bundled into the correct plugin ZIPs.

### 5. Update this README

Add a row to the abilities table above and note which package now contains it.

## Settings pattern

Abilities declare settings as a `$settings` array on the class:

```php
protected $settings = array(
    'github_token' => array(
        'type'      => 'password',
        'name'      => 'GitHub Token',
        'label'     => 'Personal access token for the GitHub API.',
        'storage'   => 'user',
        'encrypted' => true,
    ),
);
```

- **Inheritance**: Settings declared on a parent class (e.g. `Dollypack_GitHub_Ability`) are shared by all children. The storage key is prefixed with the declaring class's `$id`, so all GitHub abilities share a single `_dollypack_github_github_token` user-meta key.
- **`$group_label`**: Set on a parent class to group its children under one heading in the admin UI (e.g. `'GitHub'`).
- **`storage`**: Optional. Use `'site'` for WordPress options or `'user'` for user meta. Defaults to `'site'`.
- **`encrypted`**: Optional. When `true`, Dollypack encrypts the stored value before writing it to the database and decrypts it on read. Use this for tokens, client secrets, and similar credentials.
- **Storage key naming**: Site options use `dollypack_{declaring_class_id}_{setting_id}`. User meta uses `_{same_key}` so it is treated as protected meta by WordPress conventions.

### Adding a service-level parent class

When adding a new service (e.g. Slack), create an abstract parent in `includes/`:

1. Extend `Dollypack_Ability`.
2. Set a shared `$id` (e.g. `'slack'`), `$group_label`, and `$settings` for credentials.
3. Add a helper method for authenticated API requests (like `github_request()`).
4. Load the file from the relevant bootstrap(s).
5. Add the parent class file to the appropriate module in `config/packages.php`.
6. Have each concrete ability extend this parent.

### OAuth settings pattern

For services requiring OAuth 2.0 (e.g. Google), the parent class handles the full authorization code flow:

1. **`$settings`** declares site-scoped app credentials like `client_id` and `client_secret` — rendered as standard inputs by the settings page.
2. **`render_settings_html()`** — static method that outputs extra `<tr>` rows in the settings form (Connect/Disconnect buttons, connection status). The settings page calls this automatically if the method exists on the parent class.
3. **`handle_oauth_callback()`** and **`handle_disconnect()`** — registered as `admin_post_` hooks in the constructor (with a static flag to avoid duplicate registration).
4. **`has_required_settings()`** is overridden to also check that a refresh token exists, so abilities stay disabled until the OAuth flow completes.
5. **Token storage** — access token and refresh token are stored per user in encrypted `user_meta`; expiry remains plain because it is not secret. The `get_access_token()` method auto-refreshes when the token is expired or expiring within 60 seconds.
6. **Key material** — encryption keys are derived from WordPress salts via `wp_salt()`. If those salts are rotated, stored secrets can no longer be decrypted and must be re-entered or re-authorized.

## Design principles

These rules apply when creating or modifying abilities:

- **Each ability = a permission level.** Abilities are individually toggleable in the admin UI (Settings > Dollypack). Think of each one as a trust boundary.
- **Prefer fewer, broader abilities over many narrow ones.** Combine endpoints that share the same trust level into one ability (e.g. all read-only GitHub API calls go into `github-read`).
- **Split when risk differs.** Separate read from write, or when a user would reasonably want one without the other (e.g. `github-read` vs `github-write`).
- **Use enums to constrain actions.** When a single ability supports multiple operations, use an `enum` in the input schema (e.g. `github-notifications` has `action: ['list', 'mark-read']`).
- **Mark annotations accurately.** Set `readonly`, `destructive`, and `idempotent` to reflect what the ability actually does. These inform the agent's decision-making.
- **Keep this file up to date.** This README is also `CLAUDE.md` and `AGENTS.md`. When you add or change an ability, update the abilities table and any relevant sections.

## Packaging

```bash
# Build all release ZIPs into dist/
php scripts/build-packages.php --all

# Build with a specific version injected into plugin headers
php scripts/build-packages.php --all --version 1.2.0

# Build a single package
php scripts/build-packages.php dollypack-core
```

- Package composition lives in `config/packages.php`, which defines modules (file groups) and packages (collections of modules). Module entries can be simple strings (source = destination) or arrays with explicit `source`/`destination` mappings for files that relocate into the package root (like bootstraps).
- The build script resolves modules into file lists, copies them into `dist/{package}/`, injects the version into the plugin header, runs `php -l` on every PHP file, and creates the ZIP.
- GitHub Actions publishes `dollypack-core.zip`, `dollypack-github.zip`, `dollypack-google.zip`, and `dollypack-full.zip` as release assets for `v*` tags, and injects the tag version into each plugin header.
- Add-on plugins include a fallback admin notice with the releases URL if `dollypack-core` is missing.
- There is no test suite, linter config, or dependency manager. The only automated check is `php -l` syntax linting during the build.

## Architecture

The root `dollypack.php` is the development entrypoint — it acts as a full bundle and must not be installed alongside any packaged plugin. It loads `Dollypack_Package_Helper` to detect conflicts with packaged plugins, then delegates to `packages/full/bootstrap.php`.

Each package has its own entrypoint (`packages/{name}/dollypack-{name}.php`) and bootstrap (`packages/{name}/bootstrap.php`). The core and full bootstraps load the runtime and register abilities directly. Add-on bootstraps (github, google) defer registration to `plugins_loaded` at priority 20 and call `Dollypack_Package_Helper::ensure_core_runtime()` to verify core is active first.

`Dollypack_Runtime` is a static singleton that manages the ability lifecycle:

1. Abilities are registered via `register_ability($id, ['file' => ..., 'class' => ...])` — class files are lazy-loaded on first instantiation.
2. On `wp_abilities_api_categories_init`, it registers the `dollypack` category.
3. On `wp_abilities_api_init`, it instantiates all registered abilities, checks which are enabled via the `dollypack_enabled_abilities` option, verifies required settings are populated, and calls `register()` on each qualifying ability.
