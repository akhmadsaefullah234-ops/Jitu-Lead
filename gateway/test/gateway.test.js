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
    const conn = { handlers, sent, loggedOut: false, sendMedia: async (jid, m) => (sent.push({ jid, media: m }), 'wamid.m' + sent.length), send: async (jid, text) => (sent.push({ jid, text }), 'wamid.' + sent.length), logout: async () => { conn.loggedOut = true; }, end() {} };
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

test('kirim gambar dan dokumen lewat POST /messages; data rusak, jenis asing, dan ukuran berlebih ditolak', async () => {
  const g = await boot();
  await g.call('POST', '/session/start', HOOK);
  g.sockets[0].handlers.onOpen();
  const data = Buffer.from('PDFDATA').toString('base64');

  const doc = await g.call('POST', '/messages', { to: '+6281234567890', type: 'document', text: 'Price list', media: { mime_type: 'application/pdf', filename: 'harga.pdf', data } });
  assert.equal(doc.status, 200);
  assert.equal(doc.body.id, 'wamid.m1');
  assert.deepEqual(g.sockets[0].sent[0].media, { type: 'document', buffer: Buffer.from('PDFDATA'), mimetype: 'application/pdf', filename: 'harga.pdf', caption: 'Price list' });
  assert.equal(g.sockets[0].sent[0].jid, '6281234567890@s.whatsapp.net');

  assert.equal((await g.call('POST', '/messages', { to: '+6281234567890', type: 'image', media: { mime_type: 'image/png', data } })).status, 200);
  assert.equal((await g.call('POST', '/messages', { to: '+6281234567890', type: 'video', media: { mime_type: 'video/mp4', data } })).status, 400);
  assert.equal((await g.call('POST', '/messages', { to: '+6281234567890', type: 'image', media: { mime_type: 'bukan mime', data } })).status, 400);
  assert.equal((await g.call('POST', '/messages', { to: '+6281234567890', type: 'image', media: { mime_type: 'image/png', data: '' } })).status, 400);
  assert.equal((await g.call('POST', '/messages', { to: 'abc', type: 'image', media: { mime_type: 'image/png', data } })).status, 400);
  g.close();
});

test('toInbound melewatkan grup dan pesan sendiri', () => {
  const base = { key: { id: 'a', remoteJid: '6281@s.whatsapp.net' }, message: { conversation: 'hi' }, pushName: 'Siti', messageTimestamp: 1790000001 };
  assert.equal(toInbound(base).from, '6281');
  assert.equal(toInbound(base).text, 'hi');
  assert.equal(toInbound({ ...base, key: { ...base.key, remoteJid: '123@g.us' } }), null);
  assert.equal(toInbound({ ...base, key: { ...base.key, fromMe: true } }), null);
});

// --- LID, dekripsi, kesehatan sesi, auth atomik ---

const { classify, connector } = require('../src/whatsapp');
const { useAtomicAuthState } = require('../src/authState');

const msgFrom = (over = {}) => ({ key: { id: 'm1', remoteJid: '1234567890@lid', remoteJidAlt: '6281234567890@s.whatsapp.net', ...over.key }, message: { conversation: 'Halo' }, pushName: 'Budi', messageTimestamp: 1790000000, ...over, key: { id: 'm1', remoteJid: '1234567890@lid', remoteJidAlt: '6281234567890@s.whatsapp.net', ...over.key } });

test('chat @lid: nomor HP diambil dari remoteJidAlt (Baileys 7) atau senderPn (Baileys 6)', () => {
  assert.equal(toInbound(msgFrom()).from, '6281234567890');
  assert.equal(toInbound(msgFrom({ key: { remoteJidAlt: undefined, senderPn: '6285550001111:7@s.whatsapp.net' } })).from, '6285550001111');
  assert.deepEqual(classify(msgFrom({ key: { remoteJidAlt: undefined } })), { action: 'lookup', lid: '1234567890@lid' });
});

test('classify: CIPHERTEXT tidak dibuang diam-diam, pesan sistem dan grup dilewati', () => {
  const cipher = { key: { id: 'c1', remoteJid: '1234567890@lid', remoteJidAlt: '6281@s.whatsapp.net' }, messageStubType: 2, messageStubParameters: ['No matching sessions found for message'] };
  assert.equal(classify(cipher).action, 'ciphertext');
  assert.deepEqual(classify({ ...cipher, key: { ...cipher.key, fromMe: true } }), { action: 'ciphertext', own: true });
  assert.equal(classify({ key: { id: 'x', remoteJid: '6281@s.whatsapp.net' } }).reason, 'tanpa_isi');
  assert.equal(classify({ key: { id: 'x', remoteJid: '6281@s.whatsapp.net' }, message: { protocolMessage: {} } }).reason, 'pesan_sistem');
  assert.equal(classify({ key: { id: 'x', remoteJid: '1@g.us' }, message: { conversation: 'a' } }).reason, 'bukan_chat_pribadi');
});

// Soket Baileys palsu: menguji connector asli (alur upsert, retry dekripsi, lookup LID) tanpa jaringan.
async function fakeConnector(extra = {}) {
  const { EventEmitter } = require('node:events');
  const real = await import('@whiskeysockets/baileys');
  const sock = Object.assign({ ev: new EventEmitter() }, { signalRepository: { lidMapping: { getPNForLID: async () => null } }, sentMessages: [], sendMessage: async (jid, content) => { const m = { key: { id: 'out' + sock.sentMessages.length, remoteJid: jid }, message: { conversation: content.text } }; sock.sentMessages.push(m); return m; }, end() {}, logout: async () => {} });
  const logs = [];
  const mk = (level) => (obj, text) => logs.push({ level, ...obj, text });
  const log = { debug: mk('debug'), info: mk('info'), warn: mk('warn'), error: mk('error'), child: () => log };
  const lib = { ...real, default: (cfg) => { sock.cfg = cfg; return sock; }, fetchLatestBaileysVersion: async () => ({ version: [2, 3000, 1] }) };
  const events = { messages: [], dec: [], closes: [], fails: 0, oks: 0 };
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'gw-auth-'));
  const conn = connector({ log, decryptWaitMs: 40, lookupRetryMs: 5, loadLib: async () => lib, ...extra })({
    authDir: dir,
    handlers: { onQr() {}, onOpen() {}, onClose: (c) => events.closes.push(c), onMessage: (m) => events.messages.push(m), onStatus() {}, onDecryptOk: () => events.oks++, onDecryptFail: (f) => events.fails++ },
  });
  await new Promise((r) => setTimeout(r, 50));

  return { sock, logs, events, conn, dir };
}

test('connector: pesan @lid diteruskan dengan nomor HP, riwayat (append) dan grup tidak', async () => {
  const f = await fakeConnector();
  f.sock.ev.emit('messages.upsert', { type: 'notify', messages: [msgFrom()] });
  f.sock.ev.emit('messages.upsert', { type: 'append', messages: [msgFrom({ key: { id: 'old' } })] });
  await new Promise((r) => setTimeout(r, 20));
  assert.equal(f.events.messages.length, 1);
  assert.equal(f.events.messages[0].from, '6281234567890');
  assert.equal(f.events.oks, 1);
});

test('connector: gambar diunduh dan dikirim ke CRM; gagal atau terlalu besar tidak menjatuhkan pesannya', async () => {
  const image = (id, extra = {}) => msgFrom({ key: { id }, message: { imageMessage: { mimetype: 'image/png', caption: 'rumahnya', ...extra } } });
  const f = await fakeConnector({ download: async (m) => { if (m.key.id === 'bad') throw new Error('mmg gone'); return Buffer.from('PNGDATA'); }, maxImageBytes: 100 });

  f.sock.ev.emit('messages.upsert', { type: 'notify', messages: [image('ok'), image('bad'), image('big', { fileLength: 5000 })] });
  await new Promise((r) => setTimeout(r, 40));

  const by = Object.fromEntries(f.events.messages.map((m) => [m.id, m]));
  assert.equal(by.ok.type, 'image');
  assert.equal(by.ok.text, 'rumahnya');
  assert.deepEqual(by.ok.media, { mime_type: 'image/png', data: Buffer.from('PNGDATA').toString('base64') });
  assert.deepEqual(by.bad.media, { mime_type: 'image/png', error: 'download_failed' });
  assert.deepEqual(by.big.media, { mime_type: 'image/png', error: 'too_large' });
  assert.ok(f.logs.some((l) => l.level === 'warn' && l.id === 'bad' && /gagal diunduh/.test(l.text)));
});

test('connector: sendMedia mengirim gambar dengan keterangan dan dokumen dengan nama file', async () => {
  const f = await fakeConnector();
  const buffer = Buffer.from('x');
  const imageId = await f.conn.sendMedia('6281234567890@s.whatsapp.net', { type: 'image', buffer, mimetype: 'image/jpeg', filename: 'a.jpg', caption: 'Fasad' });
  const docId = await f.conn.sendMedia('6281234567890@s.whatsapp.net', { type: 'document', buffer, mimetype: 'application/pdf', filename: 'harga.pdf', caption: undefined });
  assert.equal(imageId, 'out0');
  assert.equal(docId, 'out1');
});

test('connector: CIPHERTEXT menunggu retry; bila terbaca, diteruskan; bila tidak, dicatat dan dihitung gagal', async () => {
  const f = await fakeConnector();
  const cipher = (id) => ({ key: { id, remoteJid: '1234567890@lid', remoteJidAlt: '6281234567890@s.whatsapp.net' }, messageStubType: 2, messageStubParameters: ['No matching sessions found for message'] });

  f.sock.ev.emit('messages.upsert', { type: 'notify', messages: [cipher('a')] });
  await new Promise((r) => setTimeout(r, 10));
  assert.equal(f.events.messages.length, 0);
  assert.ok(f.logs.some((l) => l.level === 'info' && l.id === 'a' && /menunggu kiriman ulang/.test(l.text)));

  // WhatsApp mengirim ulang pesan yang sama, kali ini terbaca
  f.sock.ev.emit('messages.upsert', { type: 'notify', messages: [msgFrom({ key: { id: 'a' } })] });
  await new Promise((r) => setTimeout(r, 70));
  assert.equal(f.events.messages.length, 1);
  assert.equal(f.events.fails, 0, 'pesan yang pulih tidak dihitung gagal');

  f.sock.ev.emit('messages.upsert', { type: 'notify', messages: [cipher('b')] });
  await new Promise((r) => setTimeout(r, 90));
  assert.equal(f.events.messages.length, 1);
  assert.equal(f.events.fails, 1);
  assert.ok(f.logs.some((l) => l.level === 'warn' && l.id === 'b' && /tetap tidak bisa dibuka/.test(l.text)));
});

test('connector: LID tanpa nomor di event dicari lewat lid-mapping; bila tetap tidak ada, dicatat (tidak diam)', async () => {
  const f = await fakeConnector();
  const lidOnly = msgFrom({ key: { id: 'l1', remoteJidAlt: undefined } });
  f.sock.signalRepository.lidMapping.getPNForLID = async () => '6289990001111:3@s.whatsapp.net';
  f.sock.ev.emit('messages.upsert', { type: 'notify', messages: [lidOnly] });
  await new Promise((r) => setTimeout(r, 30));
  assert.equal(f.events.messages[0].from, '6289990001111');

  f.sock.signalRepository.lidMapping.getPNForLID = async () => null;
  f.sock.ev.emit('messages.upsert', { type: 'notify', messages: [msgFrom({ key: { id: 'l2', remoteJidAlt: undefined } })] });
  await new Promise((r) => setTimeout(r, 40));
  assert.equal(f.events.messages.length, 1);
  assert.ok(f.logs.some((l) => l.level === 'warn' && l.id === 'l2' && /nomor HP pengirim tidak diketahui/.test(l.text)));
});

test('connector: pesan dari perangkat lain akun yang gagal dibuka dihitung; kode putus badSession berarti perlu scan ulang', async () => {
  const f = await fakeConnector();
  f.sock.ev.emit('messages.upsert', { type: 'notify', messages: [{ key: { id: 'o1', fromMe: true, remoteJid: '1234567890@lid' }, messageStubType: 2 }] });
  await new Promise((r) => setTimeout(r, 10));
  assert.equal(f.events.fails, 1);

  f.sock.ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: Object.assign(new Error('Bad Session'), { output: { statusCode: 500 } }) } });
  assert.deepEqual(f.events.closes, [{ loggedOut: false, needsRelink: true }]);
});

test('connector: pesan keluar disimpan untuk getMessage (kirim ulang atas permintaan WhatsApp)', async () => {
  const f = await fakeConnector();
  const id = await f.conn.send('6281234567890@s.whatsapp.net', 'Halo');
  assert.deepEqual(await f.sock.cfg.getMessage({ id }), { conversation: 'Halo' });
});

test('sesi tidak sehat: gagal buka pesan berturut-turut mengirim needs_relink, mengunci sesi, dan scan ulang membersihkan login', async () => {
  const g = await boot();
  await g.call('POST', '/session/start', HOOK);
  g.sockets[0].handlers.onOpen();
  fs.writeFileSync(path.join(g.dataDir, 'sessions', SID, 'auth', 'creds.json'), '{}');

  g.sockets[0].handlers.onDecryptFail({});
  g.sockets[0].handlers.onDecryptFail({});
  g.sockets[0].handlers.onDecryptOk(); // pesan berhasil: hitungan mulai dari nol
  g.sockets[0].handlers.onDecryptFail({});
  g.sockets[0].handlers.onDecryptFail({});
  assert.equal(g.posted.filter((p) => p.status === 'needs_relink').length, 0);

  g.sockets[0].handlers.onDecryptFail({});
  const event = g.posted.at(-1);
  assert.equal(event.event, 'session');
  assert.equal(event.status, 'needs_relink');
  assert.match(event.reason, /3 pesan/);
  assert.equal((await g.call('GET', '/session')).body.status, 'needs_relink');
  assert.equal((await g.call('POST', '/messages', { to: '+62811111111', text: 'x' })).status, 503);

  // restart gateway: tidak memakai login rusak
  const second = new SessionManager({ config: { apiKey: KEY, dataDir: g.dataDir, maxSessions: 2 }, connector: () => assert.fail('tidak boleh membuka sesi rusak'), post: async () => {} });
  second.restore();
  assert.equal(second.status(SID), 'needs_relink');

  // CRM menekan "Scan QR": login lama dibuang, sesi baru menunggu QR
  assert.equal((await g.call('POST', '/session/start', HOOK)).body.status, 'qr_required');
  assert.equal(fs.existsSync(path.join(g.dataDir, 'sessions', SID, 'auth', 'creds.json')), false);
  assert.equal(fs.existsSync(path.join(g.dataDir, 'sessions', SID, 'needs_relink')), false);
  assert.equal(g.sockets.length, 2);
  g.close();
});

test('putus dengan badSession langsung needs_relink', async () => {
  const g = await boot();
  await g.call('POST', '/session/start', HOOK);
  g.sockets[0].handlers.onClose({ loggedOut: false, needsRelink: true });
  assert.equal(g.posted.at(-1).status, 'needs_relink');
  await new Promise((r) => setTimeout(r, 30));
  assert.equal(g.sockets.length, 1, 'tidak mencoba menyambung ulang dengan login rusak');
  g.close();
});

test('auth state atomik: tulis lewat berkas sementara, sisa .tmp dibuang, creds punya cadangan', async () => {
  const real = await import('@whiskeysockets/baileys');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'gw-atomic-'));
  fs.writeFileSync(path.join(dir, 'session-x.json.123.tmp'), '{"setengah');

  const a = await useAtomicAuthState(dir, real);
  assert.equal(fs.existsSync(path.join(dir, 'session-x.json.123.tmp')), false);

  await a.saveCreds();
  await a.state.keys.set({ session: { '6281.0': { k: Buffer.from('rahasia') } }, 'lid-mapping': { '6281': '1234@lid' } });
  assert.ok(fs.existsSync(path.join(dir, 'creds.json')));
  assert.equal(fs.readdirSync(dir).some((f) => f.endsWith('.tmp')), false);
  assert.equal(fs.statSync(path.join(dir, 'creds.json')).mode & 0o777, 0o600);

  await a.saveCreds();
  assert.ok(fs.existsSync(path.join(dir, 'creds.json.bak')));

  // dibaca ulang: sama persis, termasuk Buffer; berkas lid-mapping ada
  const b = await useAtomicAuthState(dir, real);
  assert.deepEqual(b.state.creds.noiseKey.private, a.state.creds.noiseKey.private);
  assert.equal((await b.state.keys.get('session', ['6281.0']))['6281.0'].k.toString(), 'rahasia');
  assert.equal((await b.state.keys.get('lid-mapping', ['6281']))['6281'], '1234@lid');

  // creds.json rusak (mis. disk bermasalah): pulih dari cadangan
  fs.writeFileSync(path.join(dir, 'creds.json'), '{rusak');
  const c = await useAtomicAuthState(dir, real);
  assert.deepEqual(c.state.creds.noiseKey.private, a.state.creds.noiseKey.private);

  // penghapusan kunci
  await a.state.keys.set({ session: { '6281.0': null } });
  assert.equal(fs.existsSync(path.join(dir, 'session-6281.0.json')), false);
});

test('login lama (dibuat gateway sebelum Baileys 7) ditandai perlu scan ulang saat restart dan CRM diberi tahu', async () => {
  const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'gw-legacy-'));
  const dir = path.join(dataDir, 'sessions', SID);
  fs.mkdirSync(path.join(dir, 'auth'), { recursive: true });
  fs.writeFileSync(path.join(dir, 'meta.json'), JSON.stringify(HOOK));
  fs.writeFileSync(path.join(dir, 'auth', 'creds.json'), JSON.stringify({ registered: true }));

  // sesi yang baru menunggu QR (belum pernah terhubung) tidak dianggap rusak
  const pendingId = 'menunggu-qr-1234';
  fs.mkdirSync(path.join(dataDir, 'sessions', pendingId, 'auth'), { recursive: true });
  fs.writeFileSync(path.join(dataDir, 'sessions', pendingId, 'meta.json'), JSON.stringify(HOOK));
  fs.writeFileSync(path.join(dataDir, 'sessions', pendingId, 'auth', 'creds.json'), JSON.stringify({ registered: false }));

  const posted = [];
  const opened = [];
  const m = new SessionManager({ config: { apiKey: KEY, dataDir, maxSessions: 5 }, connector: ({ authDir }) => (opened.push(authDir), { send: async () => 'x', logout: async () => {}, end() {} }), post: async (p) => posted.push(p.payload) });
  m.restore();

  assert.equal(m.status(SID), 'needs_relink');
  assert.equal(posted.filter((p) => p.status === 'needs_relink').length, 1);
  assert.equal(m.status(pendingId), 'qr_required');
  assert.equal(opened.length, 1);

  // setelah terhubung dengan pustaka baru, restart berikutnya memakai login itu
  const g = await boot();
  await g.call('POST', '/session/start', HOOK);
  fs.writeFileSync(path.join(g.dataDir, 'sessions', SID, 'auth', 'creds.json'), JSON.stringify({ registered: true }));
  g.sockets[0].handlers.onOpen();
  const again = new SessionManager({ config: { apiKey: KEY, dataDir: g.dataDir, maxSessions: 2 }, connector: () => ({ send: async () => 'x', logout: async () => {}, end() {} }), post: async () => assert.fail('tidak boleh menandai rusak') });
  again.restore();
  assert.notEqual(again.status(SID), 'needs_relink');
  g.close();
});
