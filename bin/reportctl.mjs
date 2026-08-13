#!/usr/bin/env node
import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";

function usage(exitCode = 0) {
  console.log(`Usage:
  reportctl pull <report-id> --url <server-url> --token <admin-token> [--out <directory>]
                 [--resolve "resolution note"] [--retention keep|metadata|delete]

The admin token can also be supplied as LIVE_REPORT_ADMIN_TOKEN.
The default archive directory is ./.bug-reports.`);
  process.exit(exitCode);
}

function parse(argv) {
  if (argv[0] !== "pull" || !argv[1]) usage(1);
  const result = { command: argv[0], id: argv[1], out: ".bug-reports" };
  for (let index = 2; index < argv.length; index += 2) {
    const flag = argv[index];
    const value = argv[index + 1];
    if (!value || !flag.startsWith("--")) usage(1);
    result[flag.slice(2)] = value;
  }
  return result;
}

async function request(url, token, options = {}) {
  const response = await fetch(url, {
    ...options,
    headers: { authorization: `Bearer ${token}`, "content-type": "application/json", ...(options.headers || {}) },
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.error || `HTTP ${response.status}`);
  return body;
}

const args = parse(process.argv.slice(2));
const token = args.token || process.env.LIVE_REPORT_ADMIN_TOKEN;
if (!args.url || !token) usage(1);
const base = args.url.replace(/\/$/, "");
const encodedId = encodeURIComponent(args.id);
const bundle = await request(`${base}/api/v1/admin/reports/${encodedId}/export`, token);
const destination = path.resolve(args.out, args.id);
await mkdir(destination, { recursive: true, mode: 0o700 });
await writeFile(path.join(destination, "report.json"), `${JSON.stringify(bundle.report, null, 2)}\n`, { mode: 0o600 });
if (bundle.domHtml != null) await writeFile(path.join(destination, "dom.html"), bundle.domHtml, { mode: 0o600 });
if (bundle.screenshot) await writeFile(path.join(destination, bundle.screenshot.filename), Buffer.from(bundle.screenshot.dataBase64, "base64"), { mode: 0o600 });
console.log(`Archived ${args.id} to ${destination}`);

if (args.resolve) {
  const retention = args.retention || "metadata";
  const body = { resolution: args.resolve, retention };
  if (retention === "delete") body.confirmId = args.id;
  const result = await request(`${base}/api/v1/admin/reports/${encodedId}/resolve`, token, { method: "POST", body: JSON.stringify(body) });
  console.log(`Remote report is now ${result.status} (${result.retention})`);
}
