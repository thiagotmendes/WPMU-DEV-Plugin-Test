# WPMUDEV Test Plugin #

This is a plugin that can be used for testing coding skills for WordPress and PHP.

# Development

## Composer
Install composer packages
`composer install`

## Build Tasks (npm)
Everything should be handled by npm.

Install npm packages
`npm install`

| Command              | Action                                                |
|----------------------|-------------------------------------------------------|
| `npm run watch`      | Compiles and watch for changes.                       |
| `npm run compile`    | Compile production ready assets.                      |
| `npm run build`  | Build production ready bundle inside `/build/` folder |

## Composer & PHP dependencies
- Execute `composer install` during development to pull both runtime and dev dependencies.  
- Release builds should run `composer install --no-dev --optimize-autoloader` before `npm run build` so the generated zip only contains production code.  
- Composer is configured with `classmap-authoritative` and the plugin's autoloader is re-registered without prepending, ensuring our vendor classes never override another plugin's copy of the same dependency.

## Running PHPUnit tests
1. Install the WordPress test suite (only needs to happen once per environment):
   ```
   bin/install-wp-tests.sh wordpress_test root root db latest
   ```
   Adjust the DB credentials/host if you are running outside the Docker env.
2. Install dev dependencies and run the suite:
   ```
   composer install
   composer test
   ```
   The `composer test` script proxies to `vendor/bin/phpunit` and exercises the Posts Maintenance worker end-to-end.

## Posts Maintenance tools
- The new **Posts Maintenance** top-level page allows selecting public post types, choosing batch size, and triggering a background “Scan Posts” job with progress feedback and schedule info.
- A daily cron job reuses the same service to keep the `wpmudev_test_last_scan` meta fresh without user interaction.
- The WP-CLI command `wp wpmudev scan-posts --post_types=post,page --batch-size=75` executes the same workflow with a progress bar and configurable limits.

### WP-CLI (`wp wpmudev scan_posts`)
1. **Basic syntax**  
   ```
   wp wpmudev scan_posts --post_types=post,page --batch-size=75
   ```
   - `--post_types` (optional): comma-separated list; defaults to the site’s public types (`post,page` or equivalent) when omitted.
   - `--batch-size` (optional): number of posts per batch, between 10 and 200 (default 50).

2. **Running as root**  
   - WP-CLI requires `--allow-root` if you run it as root:  
     `wp wpmudev scan_posts --allow-root`.
   - Prefer running as the WordPress/PHP-FPM user (`sudo -u www-data -i -- wp ...`) for safety.

3. **Execution conditions**  
   - The service blocks concurrent scans. If a job is already running/corrupted you’ll get “A scan is already running”; wait for completion or clear the `wpmudev_posts_scan_job` option.
   - The daily cron hook (`wpmudev_posts_scan_cron`) uses the same queue, so you don’t need to pause cron for CLI runs.

4. **Output**  
   - Displays a `Scanning posts` progress bar with the current queue total.
   - Ends with `success` summarizing the processed count and included post types.
