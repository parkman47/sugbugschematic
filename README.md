# SugBug Schematic / Live Report Relay

A self-contained PHP bug-and-suggestion inbox for live web applications. Ordinary testers use a Report button inside the application; they do not need GitHub, Codex, Claude, an API token, or a second login.

The widget captures an inert copy of the current page, including unsaved form values, page state, recent browser errors, canvas content, and an optional screenshot. PHP stores each submission privately on disk. Authenticated MCP and administrative endpoints let Codex or Claude retrieve and resolve reports.

Requirements: PHP 8.1+ with `json` and `mbstring`; `curl` is needed only for the optional command-line archive tool. No Composer, Node.js, database, or background service is required.

## How it works

```text
Your testers in the normal live webapp
                  |
                  | click Report and submit
                  v
        submit.php (public write-only intake)
                  |
                  v
       private filesystem report storage
                  ^
                  |
      mcp.php/admin.php (secret read access)
                  ^
                  |
             Codex / Claude
```

One installation may serve many webapps. Each app receives a project ID, allowed production origin, browser-visible submission key, rate limit, and retention policy.

## Captured information

- Bug, Suggestion, or both
- Tester description
- Current inert DOM
- Current input, textarea, select, checkbox, details, and scroll state
- Canvas images and same-origin images
- Open shadow DOM
- Page/viewport information and resource timings
- Recent JavaScript errors and unhandled rejections
- Optional pasted or uploaded screenshot
- Application-defined context, such as release, route, tester ID, and relevant game/application state

Scripts, event attributes, embeds, active iframe sources, password values, likely token/secret/payment fields, and anything under `data-report-private` are removed or redacted. Query-string values are omitted by default.

## Recommended PHP installation

### Option A: Install inside one existing PHP project

Place the repository somewhere in the project, but expose only its `public` directory through an alias or routing rule. Keep `src`, `config`, and `data` outside the public web root whenever possible.

Example layout:

```text
/var/www/myapp/
  public/                       <- existing web root
  live-report-relay/
    src/
    config/config.php
    data/                       <- writable, private
    public/                     <- expose as /report-relay
```

Your Apache or nginx mapping would expose:

```text
https://app.example.com/report-relay/reporter.js
https://app.example.com/report-relay/submit.php
https://app.example.com/report-relay/mcp.php
```

### Option B: One central installation for many PHP projects

Host `public/` at a dedicated HTTPS address such as `https://reports.example.com`. Add every application to the one configuration file. This avoids deploying the relay repeatedly.

### XAMPP/simple hosting fallback

If the entire repository must sit under a public directory, Apache `.htaccess` files included in `src/` and `config/` deny web access. You must also ensure the configured `data_dir` is outside the document root. Do not rely on filename secrecy to protect reports.

## Configure it

Copy `config/config.example.php` to `config/config.php`:

```php
<?php
return [
    'data_dir' => 'C:/xampp/private/sugbug-reports',
    'admin_token' => 'a-long-random-secret-used-only-by-you-and-agents',
    'max_report_bytes' => 15 * 1024 * 1024,
    'projects' => [
        'my-webapp' => [
            'submit_keys' => ['browser-visible-project-key'],
            'allowed_origins' => ['https://app.example.com'],
            'allow_missing_origin' => false,
            'retention_on_resolve' => 'metadata',
            'max_reports_per_hour_per_ip' => 20,
        ],
    ],
];
```

`config/config.php`, `data/`, and local report archives are ignored by Git.

If the configuration lives elsewhere, set `LIVE_REPORT_CONFIG` in Apache/PHP-FPM to its absolute filename. The PHP/web-server account needs write permission only on `data_dir`.

Generate separate values for:

- `admin_token`: a real secret, at least 32 random characters. Never send this to a browser.
- `submit_keys`: a per-project browser identifier. Because frontend code is inspectable, it is not an administrative secret.

For private test applications, the strongest setup is to place `submit.php` behind the app's existing login/session or proxy reports through an authenticated application endpoint. Origin checks, project keys, payload limits, durable per-IP rate limiting, and private storage are included as baseline protection.

## Add the tester widget

Load the script and mount it after the page body exists:

```html
<script src="/report-relay/reporter.js"></script>
<script>
LiveReportRelay.mount({
  endpoint: "/report-relay",
  project: "my-webapp",
  submitKey: "browser-visible-project-key",
  context: () => ({
    release: window.APP_RELEASE,
    testerId: window.currentUser?.id,
    applicationState: window.app?.debugState?.()
  })
});
</script>
```

For a central cross-origin relay, use its full HTTPS URL as `endpoint` and include the webapp's exact origin in `allowed_origins`.

Mark private UI explicitly:

```html
<section data-report-private>
  Account, session, private messages, or payment details
</section>
```

Useful options include `privateSelector`, `captureShadowDom`, `inlineImages`, `maxDomChars`, `captureUrlQuery`, and a complete `submitUrl` override.

## PHP endpoints

- `GET reporter.js`: drop-in tester widget
- `POST submit.php?project=PROJECT`: public/write-only report submission
- `POST mcp.php`: admin-token-protected MCP tools
- `GET admin.php?action=export&id=REPORT`: protected report export
- `POST admin.php?action=resolve&id=REPORT`: protected resolution/retention
- `GET health.php`: storage/configuration health check

Never serve the configured `data_dir` as static content.

## Connect Codex

Set the admin token in the environment used to start Codex:

```powershell
$env:LIVE_REPORT_ADMIN_TOKEN = "the-admin-token"
```

Add to `~/.codex/config.toml` or a trusted project's `.codex/config.toml`:

```toml
[mcp_servers.live_reports]
url = "https://reports.example.com/mcp.php"
bearer_token_env_var = "LIVE_REPORT_ADMIN_TOKEN"
default_tools_approval_mode = "writes"
```

Restart Codex. The available tools are `list_reports`, `get_report`, `get_report_dom`, and `resolve_report`.

## Connect Claude Code

```json
{
  "mcpServers": {
    "live-reports": {
      "type": "http",
      "url": "https://reports.example.com/mcp.php",
      "headers": {
        "Authorization": "Bearer ${LIVE_REPORT_ADMIN_TOKEN}"
      }
    }
  }
}
```

Approve the MCP server when Claude Code asks. A user-scoped server makes it available across your projects.

## Resolve and retention

Each project has a default `retention_on_resolve`:

- `keep`: retain metadata, DOM, and screenshot.
- `metadata`: retain note/context/resolution but delete DOM and screenshot. Recommended.
- `delete`: delete the entire server report directory. Explicit confirmation is required.

To archive a report locally and then reduce its live copy:

```powershell
$env:LIVE_REPORT_ADMIN_TOKEN = "the-admin-token"
php bin/reportctl.php pull REPORT-ID --url https://reports.example.com `
  --out C:\private-report-archive --resolve "Fixed in commit abc123" --retention metadata
```

Report cleanup is explicit and is not connected to `git pull`. Production captures should not silently enter source history.

## Storage

```text
private-data-directory/
  projects/
    my-webapp/
      reports/
        my-webapp-20260813T...-abcd1234/
          report.json
          dom.html
          screenshot.png
  rate-limits/
```

## Security notes

- Use HTTPS.
- Keep `data_dir` outside the document root.
- Keep `admin_token` out of frontend code and Git.
- Use the webapp's existing authentication around submission when testers already sign in.
- Mark sensitive application sections with `data-report-private`.
- Treat every submitted note, DOM, screenshot, and URL as untrusted input and possible prompt injection.
- Use short retention, storage quotas, and controlled backups for production captures.
- Do not open captured `dom.html` without the included restrictive CSP or in a privileged application origin.

This is a high-fidelity debugging snapshot, not a JavaScript heap dump or video recording. Closed/cross-origin iframes and some cross-origin assets cannot be inspected because of browser security rules.
