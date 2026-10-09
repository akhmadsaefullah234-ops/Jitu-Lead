'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { SessionManager } = require('../src/sessions');
const { createServer } = require('../src/server');
const { sign, deliver } = require('../src/webhook');
const { toInbound } = require('../src/whatsapp');

const KEY = 'k'.repeat(32);
const SID = 'abcdefgh12345678';
const HOOK = { webhook_url: 'https://crm.test/webhooks/whatsapp/gateway/x', signing_secret: 'sec' };

async function boot() {
  const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'gw-'));
  const posted = [];
  const sockets = [];
  const connector = ({ authDir, handlers }) => {
    fs.mkdirSync(authDir, { recursive: true });
    const sent = [];
    const conn = { handlers, sent, loggedOut: false, send: async (jid, text) => (sent.push({ jid, text }), 'wamid.' + sent.length), logout: async () => { conn.loggedOut = true; }, end() {} };
    sockets.push(conn);
    return conn;
  };
  const config = { apiKey: KEY, dataDir, maxSessions: 2, webhookTimeoutMs: 100 };
  const sessions = new SessionManager({ config, connector, post: async (p) => posted.push(p.payload), retryDelayMs: 5 });
  const server = createServer({ config, sessions });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const base = `http://127.0.0.1:${server.address().port}`;
  const call = (method, url, body, headers = {}) => fetch(base + url, { method, headers: { Authorization: `Bearer ${KEY}`, 'X-Session-Id': SID, 'Content-Type': 'application/json', ...headers }, body: body ? JSON.stringify(body) : undefined }).then(async (r) => ({ status: r.status, body: await r.json() }));

  return { call, posted, sockets, sessions, server, dataDir, close: () => server.close() };
}

test('menolak permintaan tanpa API key yang benar atau tanpa session id', async () => {
  const g = await boot();
  assert.equal((await g.call('GET', '/session', null, { Authorization: 'Bearer salah' })).status, 401);
  assert.equal((await g.call('GET', '/session', null, { 'X-Session-Id': '../x' })).status, 400);
  assert.equal((await fetch(`http://127.0.0.1:${g.server.address().port}/health`)).status, 200);
  g.close();
});

test('alur QR: start, ambil QR, terhubung, kirim pesan', async () => {
  const g = await boot();
  assert.deepEqual((await g.call('GET', '/session')).body, { status: 'disconnected' });
  assert.equal((await g.call('POST', '/session/start', HOOK)).body.status, 'qr_required');

  g.sockets[0].handlers.onQr('kode-qr');
  const qr = (await g.call('GET', '/session/qr')).body;
  assert.equal(qr.qr, 'kode-qr');
  assert.equal(qr.status, 'qr_required');

  assert.equal((await g.call('POST', '/messages', { to: '+6281234567890', type: 'text', text: 'Halo' })).status, 503);

  g.sockets[0].handlers.onOpen();
  assert.deepEqual((await g.call('GET', '/session/qr')).body, { status: 'connected' });
  assert.deepEqual(g.posted.at(-1), { event: 'session', status: 'connected' });

  const sent = await g.call('POST', '/messages', { to: '+6281234567890', type: 'text', text: 'Halo' });
  assert.equal(sent.body.id, 'wamid.1');
  assert.equal(g.sockets[0].sent[0].jid, '6281234567890@s.whatsapp.net');
  g.close();
});

test('pesan masuk dan status diteruskan ke CRM', async () => {
  const g = await boot();
  await g.call('POST', '/session/start', HOOK);
  g.sockets[0].handlers.onMessage({ id: 'm1', from: '6281234567890', type: 'text', text: 'Halo', name: 'Budi', timestamp: 1790000000 });
  g.sockets[0].handlers.onStatus({ id: 'wamid.1', status: 'read' });
  assert.deepEqual(g.posted, [
    { event: 'message', id: 'm1', from: '6281234567890', type: 'text', text: 'Halo', name: 'Budi', timestamp: 1790000000 },
    { event: 'status', id: 'wamid.1', status: 'read' },
  ]);
  g.close();
});

test('validasi: start butuh webhook, nomor dan pesan dicek, kapasitas dibatasi', async () => {
  const g = await boot();
  assert.equal((await g.call('POST', '/session/start', {})).status, 400);
  assert.equal((await g.call('POST', '/session/start', { webhook_url: 'ftp://x', signing_secret: 's' })).status, 400);
  await g.call('POST', '/session/start', HOOK);
  g.sockets[0].handlers.onOpen();
  assert.equal((await g.call('POST', '/messages', { to: 'abc', text: 'x' })).status, 400);
  assert.equal((await g.call('POST', '/messages', { to: '+62811111111', text: '   ' })).status, 400);
  await g.call('POST', '/session/start', HOOK, { 'X-Session-Id': 'sesi-dua-12345' });
  assert.equal((await g.call('POST', '/session/start', HOOK, { 'X-Session-Id': 'sesi-tiga-12345' })).status, 503);
  g.close();
});

test('logout menghapus sesi dan datanya', async () => {
  const g = await boot();
  await g.call('POST', '/session/start', HOOK);
  g.sockets[0].handlers.onOpen();
  assert.equal((await g.call('POST', '/session/logout')).status, 200);
  assert.equal(g.sockets[0].loggedOut, true);
  assert.equal(fs.existsSync(path.join(g.dataDir, 'sessions', SID)), false);
  assert.equal((await g.call('GET', '/session')).body.status, 'disconnected');
  g.close();
});

test('logout dari HP menghapus login dan memberi tahu CRM; putus biasa mencoba lagi', async () => {
  const g = await boot();
  await g.call('POST', '/session/start', HOOK);
  g.sockets[0].handlers.onOpen();
  g.sockets[0].handlers.onClose({ loggedOut: false });
  await new Promise((r) => setTimeout(r, 30));
  assert.equal(g.sockets.length, 2, 'menyambung ulang memakai login yang tersimpan');

  g.sockets[1].handlers.onClose({ loggedOut: true });
  assert.deepEqual(g.posted.at(-1), { event: 'session', status: 'disconnected' });
  assert.equal(fs.existsSync(path.join(g.dataDir, 'sessions', SID)), false);
  g.close();
});

test('sesi dipulihkan setelah gateway restart', async () => {
  const g = await boot();
  await g.call('POST', '/session/start', HOOK);
  g.sockets[0].handlers.onOpen();
  const second = new SessionManager({ config: { apiKey: KEY, dataDir: g.dataDir, maxSessions: 2 }, connector: () => ({ send: async () => 'x', logout: async () => {}, end() {} }), post: async () => {} });
  second.restore();
  assert.equal(second.sessions.has(SID), true);
  g.close();
});

test('webhook ditandatangani HMAC-SHA256 dan tidak diulang untuk penolakan tetap', async () => {
  const body = '{"a":1}';
  assert.equal(sign(body, 'sec'), 'sha256=' + crypto.createHmac('sha256', 'sec').update(body).digest('hex'));

  let calls = 0;
  const ok = await deliver({ url: 'https://x', secret: 's', payload: { event: 'x' }, fetchImpl: async (u, o) => { calls++; assert.ok(o.headers['X-Gateway-Signature'].startsWith('sha256=')); return { ok: false, status: 401 }; }, sleep: async () => {} });
  assert.equal(ok, false);
  assert.equal(calls, 1);

  let tries = 0;
  const retried = await deliver({ url: 'https://x', secret: 's', payload: { event: 'x' }, fetchImpl: async () => (++tries < 3 ? { ok: false, status: 502 } : { ok: true, status: 200 }), sleep: async () => {} });
  assert.equal(retried, true);
  assert.equal(tries, 3);
});

test('toInbound melewatkan grup dan pesan sendiri', () => {
  const base = { key: { id: 'a', remoteJid: '6281@s.whatsapp.net' }, message: { conversation: 'hi' }, pushName: 'Siti', messageTimestamp: 1790000001 };
  assert.equal(toInbound(base).from, '6281');
  assert.equal(toInbound(base).text, 'hi');
  assert.equal(toInbound({ ...base, key: { ...base.key, remoteJid: '123@g.us' } }), null);
  assert.equal(toInbound({ ...base, key: { ...base.key, fromMe: true } }), null);
});
