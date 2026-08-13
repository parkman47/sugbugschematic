import assert from "node:assert/strict";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { createServer } from "../src/server.mjs";

const ADMIN_TOKEN = "test-admin-token-with-more-than-32-characters";

async function fixture(t) {
  const dataDir = await mkdtemp(path.join(os.tmpdir(), "live-report-relay-"));
  const projects = { projects: { weblod: {
    submitKeys: ["browser-key"],
    allowedOrigins: ["https://game.example"],
    retentionOnResolve: "metadata",
    maxReportsPerHourPerIp: 10,
  } } };
  const { server } = await createServer({ dataDir, projects, adminToken: ADMIN_TOKEN, host: "127.0.0.1", port: 0 });
  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  const address = server.address();
  const base = `http://127.0.0.1:${address.port}`;
  t.after(async () => {
    await new Promise((resolve) => server.close(resolve));
    await rm(dataDir, { recursive: true, force: true });
  });
  return { base, dataDir };
}

async function submit(base, overrides = {}) {
  return fetch(`${base}/api/v1/projects/weblod/reports`, {
    method: "POST",
    headers: {
      origin: "https://game.example",
      "x-report-key": "browser-key",
      "content-type": "application/json",
      ...(overrides.headers || {}),
    },
    body: JSON.stringify({ categories: ["bug", "suggestion"], note: "Door does not open", domHtml: "<!doctype html><p>state</p>", capture: { url: "https://game.example/door", formState: [{ value: "draft" }] }, ...overrides.body }),
  });
}

test("ingests, exposes, exports, and reduces a report", async (t) => {
  const { base, dataDir } = await fixture(t);
  const submitted = await submit(base);
  assert.equal(submitted.status, 201);
  const created = await submitted.json();
  assert.match(created.id, /^weblod-/);

  const mcpHeaders = { authorization: `Bearer ${ADMIN_TOKEN}`, "content-type": "application/json" };
  const initialized = await fetch(`${base}/mcp`, { method: "POST", headers: mcpHeaders, body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "initialize", params: { protocolVersion: "2025-06-18" } }) });
  assert.equal(initialized.status, 200);
  assert.equal((await initialized.json()).result.serverInfo.name, "live-report-relay");

  const listed = await fetch(`${base}/mcp`, { method: "POST", headers: mcpHeaders, body: JSON.stringify({ jsonrpc: "2.0", id: 2, method: "tools/call", params: { name: "list_reports", arguments: {} } }) });
  const listBody = await listed.json();
  assert.equal(listBody.result.structuredContent.reports[0].id, created.id);

  const exported = await fetch(`${base}/api/v1/admin/reports/${created.id}/export`, { headers: { authorization: `Bearer ${ADMIN_TOKEN}` } });
  const bundle = await exported.json();
  assert.match(bundle.domHtml, /state/);
  assert.equal(bundle.report.capture.formState[0].value, "draft");

  const resolved = await fetch(`${base}/api/v1/admin/reports/${created.id}/resolve`, {
    method: "POST", headers: mcpHeaders, body: JSON.stringify({ resolution: "Fixed", retention: "metadata" }),
  });
  assert.deepEqual(await resolved.json(), { id: created.id, status: "resolved", retention: "metadata", assets: {} });
  const domPath = path.join(dataDir, "projects", "weblod", "reports", created.id, "dom.html");
  await assert.rejects(readFile(domPath), /ENOENT/);
});

test("rejects an unapproved origin and unauthenticated MCP", async (t) => {
  const { base } = await fixture(t);
  const submission = await submit(base, { headers: { origin: "https://evil.example" } });
  assert.equal(submission.status, 403);
  const mcp = await fetch(`${base}/mcp`, { method: "POST", headers: { "content-type": "application/json" }, body: "{}" });
  assert.equal(mcp.status, 401);
});
