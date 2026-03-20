# Dollypack

WordPress abilities pack that extends [Dolly](https://wordpress.com), the WordPress.com AI agent, with new capabilities. Abilities run remotely on self-hosted WordPress sites through the Jetpack connection.


## Requirements

- WordPress 6.9+
- Jetpack connected to WordPress.com

## Current abilities

| Ability ID | Class | Description | Annotations |
|---|---|---|---|
| `wp-remote-request` | `Dollypack_WP_Remote_Request` | Perform an HTTP request using `wp_remote_request()`. | `idempotent` |
| `github-read` | `Dollypack_GitHub_Read` | Read files, directory listings, and repository metadata from the GitHub API. | `readonly`, `idempotent` |
| `github-notifications` | `Dollypack_GitHub_Notifications` | List and manage GitHub notifications (list, mark-read). | `idempotent` |
| `github-search` | `Dollypack_GitHub_Search` | Search GitHub for code, issues, repositories, or commits. | `readonly`, `idempotent` |
| `github-write` | `Dollypack_GitHub_Write` | Create or update resources on GitHub — issues, comments, pull requests, etc. | `destructive` |

## Adding a new ability

### 1. Create the ability class

Create a file in `abilities/` with a class extending `Dollypack_Ability` (or a service-specific parent like `Dollypack_GitHub_Ability`).

```
Dollypack_Ability (abstract)
├── Dollypack_WP_Remote_Request
└── Dollypack_GitHub_Ability (abstract, shared $settings + github_request())
    ├── Dollypack_GitHub_Read
    ├── Dollypack_GitHub_Notifications
    ├── Dollypack_GitHub_Search
    └── Dollypack_GitHub_Write
```

### 2. Implement required methods

- **`execute( $input )`** — performs the action and returns a result array or `WP_Error`.
- **`get_input_schema()`** — returns a JSON Schema array describing accepted input.
- **`get_output_schema()`** — returns a JSON Schema array describing the output.
- **`get_meta()`** — returns metadata including `annotations` (`readonly`, `destructive`, `idempotent`) and `show_in_rest`.

### 3. Register the ability

Add an entry to `dollypack_get_available_abilities()` in `dollypack.php`:

```php
'my-ability' => array(
    'file'  => 'abilities/my-ability.php',
    'class' => 'Dollypack_My_Ability',
),
```

### 4. Update this README

Add a row to the abilities table above.

## Settings pattern

Abilities declare settings as a `$settings` array on the class:

```php
protected $settings = array(
    'github_token' => array(
        'type'  => 'password',
        'name'  => 'GitHub Token',
        'label' => 'Personal access token for the GitHub API.',
    ),
);
```

- **Inheritance**: Settings declared on a parent class (e.g. `Dollypack_GitHub_Ability`) are shared by all children. The option name is prefixed with the declaring class's `$id`, so all GitHub abilities share a single `dollypack_github_github_token` option.
- **`$group_label`**: Set on a parent class to group its children under one heading in the admin UI (e.g. `'GitHub'`).
- **Option naming**: `dollypack_{declaring_class_id}_{setting_id}`.

### Adding a service-level parent class

When adding a new service (e.g. Slack), create an abstract parent in `includes/`:

1. Extend `Dollypack_Ability`.
2. Set a shared `$id` (e.g. `'slack'`), `$group_label`, and `$settings` for credentials.
3. Add a helper method for authenticated API requests (like `github_request()`).
4. Require the file in `dollypack.php`.
5. Have each concrete ability extend this parent.

## Design principles

These rules apply when creating or modifying abilities:

- **Each ability = a permission level.** Abilities are individually toggleable in the admin UI (Settings > Dollypack). Think of each one as a trust boundary.
- **Prefer fewer, broader abilities over many narrow ones.** Combine endpoints that share the same trust level into one ability (e.g. all read-only GitHub API calls go into `github-read`).
- **Split when risk differs.** Separate read from write, or when a user would reasonably want one without the other (e.g. `github-read` vs `github-write`).
- **Use enums to constrain actions.** When a single ability supports multiple operations, use an `enum` in the input schema (e.g. `github-notifications` has `action: ['list', 'mark-read']`).
- **Mark annotations accurately.** Set `readonly`, `destructive`, and `idempotent` to reflect what the ability actually does. These inform the agent's decision-making.
- **Keep this file up to date.** This README is also `CLAUDE.md` and `AGENTS.md`. When you add or change an ability, update the abilities table and any relevant sections.
