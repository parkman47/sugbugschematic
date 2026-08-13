# Live Report Relay

A small, self-hosted bug/suggestion inbox for live web applications. A drop-in browser widget captures the current inert DOM, live form values, canvas images, same-origin images, useful browser context, recent runtime errors, and an optional pasted or uploaded screenshot. Reports are stored outside the application repository and exposed to Codex or Claude Code through an authenticated MCP endpoint.

The server uses only Node.js built-ins. There is no database and there are no runtime package dependencies.

## What it does

- One report can be a bug, a suggestion, or both.
- Captures current input, textarea, select, checkbox, details, scroll, active-element, canvas, shadow-root, resource-timing, and custom application context.
- Redacts passwords and likely secret/token/card fields. Add `data-report-private` to any element or ancestor that must never be captured.
- Removes scripts, event attributes, refresh tags, embeds, and live iframe sources so saved DOM is inert.
- Stores each report in a private server directory as `report.json`, optional `dom.html`, and optional screenshot.
- Provides MCP tools to list, inspect, read DOM chunks, and resolve reports.
- Can retain everything, retain metadata only, or delete a resolved report.
- Includes `reportctl` to archive a report locally before purging its heavy server artifacts.

## Security model

There are two different gates:

1. **Submission gate:** an allowed Origin, a project-specific browser key, an hourly IP rate limit, and a payload size limit. The browser key identifies a project but is not a secret because users can inspect frontend code. For a hostile/public audience, put the route behind your application's authenticated session or add a server-minted short-lived submission credential at the reverse proxy.
2. **Review gate:** a secret admin bearer token protects MCP, export, and resolve operations. Only agents/people with this token can retrieve reports.

Captured production pages can contain personal or sensitive information. Keep `data/` outside the web root, use HTTPS, restrict server backups, and choose a short retention policy. Report notes and DOM are also untrusted input and may contain prompt-injection text; the MCP server labels them as untrusted and instructs agents never to follow embedded commands.

## Quick start

Requires Node.js 20 or newer.

1. Copy `config/projects.example.json` to `config/projects.json` and configure each application.
2. Copy `.env.example` values into your service environment. Do not commit the admin token.
3. Start the service:

```powershell
$env:ADMIN_TOKEN = "replace-with-a-random-token-at-least-32-characters"
node src/server.mjs
```

For production, bind to `127.0.0.1` behind an HTTPS reverse proxy. The default port is `8787`.

## Add the widget to a project

Load the script near the end of the page and mount it after `document.body` exists:

```html
<script src="https://reports.example.com/widget.js"></script>
<script>
  LiveReportRelay.mount({
    endpoint: "https://reports.example.com",
    project: "weblod",
    submitKey: "the-project-submit-key",
    context: () => ({
      userId: window.currentUser?.id,
      release: window.APP_RELEASE,
      routeState: window.app?.debugState?.()
    })
  });
</script>
```

Mark anything that must not leave the browser:

```html
<section data-report-private>Private account and payment UI</section>
```

Useful widget options are `privateSelector`, `captureShadowDom`, `inlineImages`, `maxDomChars`, and `captureUrlQuery`. URL query values are omitted by default (the keys remain available); set `captureUrlQuery: true` only if query values are known to be safe and useful. Passwords, file contents, and fields whose names resemble secrets/tokens/payment credentials are redacted regardless of ordinary form capture.

## Connect Codex

Set the token in the environment that starts Codex:

```powershell
$env:LIVE_REPORT_ADMIN_TOKEN = "the-same-admin-token"
```

Then add this to `~/.codex/config.toml` (all local Codex tasks on that host can use it) or to a trusted project's `.codex/config.toml`:

```toml
[mcp_servers.live_reports]
url = "https://reports.example.com/mcp"
bearer_token_env_var = "LIVE_REPORT_ADMIN_TOKEN"
default_tools_approval_mode = "writes"
```

Restart Codex. A request such as “list open live reports, fetch the newest one, and show me the evidence” can then call the relay directly. The MCP tools are `list_reports`, `get_report`, `get_report_dom`, and `resolve_report`.

## Connect Claude Code

Create a user- or project-scoped HTTP MCP configuration. A project `.mcp.json` can reference an environment variable:

```json
{
  "mcpServers": {
    "live-reports": {
      "type": "http",
      "url": "https://reports.example.com/mcp",
      "headers": {
        "Authorization": "Bearer ${LIVE_REPORT_ADMIN_TOKEN}"
      }
    }
  }
}
```

Approve the project server when Claude Code asks. A user-scoped configuration makes it available across projects.

## Resolve and retention

Each project has `retentionOnResolve`:

- `keep`: keep report metadata, DOM, and screenshot on the live server.
- `metadata`: keep the note, capture context, resolution, and timestamps; delete DOM and screenshot. This is the recommended default.
- `delete`: delete the entire remote report directory. The MCP tool requires the report ID twice to prevent accidental deletion.

To keep a local copy and then reduce the live copy:

```powershell
$env:LIVE_REPORT_ADMIN_TOKEN = "the-admin-token"
node bin/reportctl.mjs pull weblod-REPORT-ID --url https://reports.example.com `
  --out C:\private-report-archive --resolve "Fixed in commit abc123" --retention metadata
```

The default local archive directory is `.bug-reports/`, which this repository ignores. Do not commit production captures unless you have deliberately reviewed them for credentials and personal data.

## Storage layout

```text
data/
  projects/
    weblod/
      reports/
        weblod-20260812T...-abcd1234/
          report.json
          dom.html
          screenshot.png
```

Reports do not belong in the application Git repository. A `git pull` should deploy code only; resolving a report performs explicit remote retention. If a local evidence archive is wanted, `reportctl pull` creates it before remote cleanup.

## Docker

```powershell
docker build -t live-report-relay .
docker run --read-only --tmpfs /tmp -p 127.0.0.1:8787:8787 `
  -e ADMIN_TOKEN="the-admin-token" `
  -v ${PWD}/config/projects.json:/app/config/projects.json:ro `
  -v live-report-data:/data live-report-relay
```

Terminate TLS and apply any application-session gate in a reverse proxy. Never expose the data volume as static files.

## Limits

This captures a high-fidelity debugging snapshot, not the JavaScript heap or a complete browser recording. Cross-origin image/style bytes may be unavailable because of browser CORS rules. Closed cross-origin iframes cannot be inspected. Native screenshot attachment remains the most reliable evidence for purely visual compositor, font, video, or cross-origin-frame issues.
