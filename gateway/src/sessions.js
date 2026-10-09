'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { deliver } = require('./webhook');

const ID = /^[A-Za-z0-9_-]{8,128}$/;
const E164 = /^\+?\d{6,15}$/;

class SessionError extends Error {
  constructor(message, status = 400) {
    super(message);
    this.status = status;
  }
}

/**
 * One WhatsApp login per session id (the CRM uses the number's unique token).
 * The actual WhatsApp socket comes from `connector`, so tests can fake it:
 *   connector({ authDir, handlers }) -> { send(jid, text) -> id, logout(), end() }
 */
class SessionManager {
  constructor({ config, connector, log, post = deliver, retryDelayMs = 3000 }) {
    this.config = config;
    this.connector = connector;
    this.log = log;
    this.post = post;
    this.retryDelayMs = retryDelayMs;
    this.sessions = new Map();
    this.root = path.join(config.dataDir, 'sessions');
    fs.mkdirSync(this.root, { recursive: true });
  }

  static validId(id) {
    return typeof id === 'string' && ID.test(id);
  }

  dir(id) {
    return path.join(this.root, id);
  }

  restore() {
    for (const id of fs.readdirSync(this.root)) {
      const metaFile = path.join(this.dir(id), 'meta.json');
      if (!SessionManager.validId(id) || !fs.existsSync(metaFile) || !fs.existsSync(path.join(this.dir(id), 'auth'))) continue;

      try {
        const meta = JSON.parse(fs.readFileSync(metaFile, 'utf8'));
        this.open(id, meta);
      } catch (err) {
        this.log?.error({ id, err: err.message }, 'sesi tidak bisa dipulihkan');
      }
    }
  }

  get(id) {
    return this.sessions.get(id);
  }

  status(id) {
    return this.get(id)?.status ?? 'disconnected';
  }

  start(id, { webhook_url, signing_secret }) {
    if (!webhook_url || !signing_secret) throw new SessionError('webhook_url dan signing_secret wajib diisi.');

    try {
      const url = new URL(webhook_url);
      if (!['https:', 'http:'].includes(url.protocol)) throw new Error('protocol');
    } catch {
      throw new SessionError('webhook_url tidak valid.');
    }

    if (!this.sessions.has(id) && this.sessions.size >= this.config.maxSessions) {
      throw new SessionError('Kapasitas gateway penuh.', 503);
    }

    const existing = this.get(id);
    const meta = { webhook_url, signing_secret };

    fs.mkdirSync(this.dir(id), { recursive: true });
    fs.writeFileSync(path.join(this.dir(id), 'meta.json'), JSON.stringify(meta), { mode: 0o600 });

    if (existing) {
      existing.meta = meta;
      if (existing.status === 'connected' || existing.conn) return existing.status;
    }

    return this.open(id, meta);
  }

  open(id, meta) {
    const session = this.get(id) ?? { id, status: 'qr_required', qr: null, qrAt: 0, conn: null, meta, retries: 0 };
    session.meta = meta;
    session.status = session.status === 'connected' ? 'connected' : 'qr_required';
    this.sessions.set(id, session);

    session.conn = this.connector({
      authDir: path.join(this.dir(id), 'auth'),
      handlers: {
        onQr: (qr) => {
          session.qr = qr;
          session.qrAt = Date.now();
          session.status = 'qr_required';
        },
        onOpen: () => {
          session.status = 'connected';
          session.qr = null;
          session.retries = 0;
          this.emit(session, { event: 'session', status: 'connected' });
        },
        onClose: ({ loggedOut }) => this.closed(session, loggedOut),
        onMessage: (m) => this.emit(session, { event: 'message', ...m }),
        onStatus: (s) => this.emit(session, { event: 'status', ...s }),
      },
    });

    return session.status;
  }

  closed(session, loggedOut) {
    session.conn = null;
    session.qr = null;

    if (loggedOut) {
      this.wipe(session.id);
      this.sessions.delete(session.id);
      this.emit(session, { event: 'session', status: 'disconnected' });
      return;
    }

    session.status = 'qr_required';
    // A dropped connection is retried with a growing pause; the saved login is reused, so no new QR is needed.
    const delay = Math.min(this.retryDelayMs * 2 ** session.retries++, 60000);
    session.timer = setTimeout(() => this.sessions.has(session.id) && !session.conn && this.open(session.id, session.meta), delay);
    session.timer.unref?.();
  }

  qr(id) {
    const s = this.get(id);

    if (!s) return { status: 'disconnected' };
    if (s.status === 'connected') return { status: 'connected' };

    return { status: 'qr_required', qr: s.qr, expires_in: s.qr ? Math.max(1, 20 - Math.floor((Date.now() - s.qrAt) / 1000)) : 0 };
  }

  async send(id, to, text) {
    const s = this.get(id);

    if (!s || s.status !== 'connected' || !s.conn) throw new SessionError('Sesi WhatsApp belum terhubung.', 503);
    if (typeof to !== 'string' || !E164.test(to)) throw new SessionError('Nomor tujuan tidak valid.');
    if (typeof text !== 'string' || text.trim() === '' || text.length > 4096) throw new SessionError('Isi pesan kosong atau terlalu panjang.');

    return s.conn.send(to.replace(/^\+/, '') + '@s.whatsapp.net', text);
  }

  async logout(id) {
    const s = this.get(id);

    if (s) {
      clearTimeout(s.timer);
      try {
        await s.conn?.logout();
      } catch (err) {
        this.log?.warn({ id, err: err.message }, 'logout gagal, sesi tetap dihapus');
      }
      s.conn?.end?.();
      this.sessions.delete(id);
    }

    this.wipe(id);
  }

  wipe(id) {
    fs.rmSync(this.dir(id), { recursive: true, force: true });
  }

  emit(session, payload) {
    return this.post({
      url: session.meta.webhook_url,
      secret: session.meta.signing_secret,
      payload,
      timeoutMs: this.config.webhookTimeoutMs,
      log: this.log,
    });
  }
}

module.exports = { SessionManager, SessionError };
