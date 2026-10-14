'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { deliver } = require('./webhook');

const ID = /^[A-Za-z0-9_-]{8,128}$/;
const E164 = /^\+?\d{6,15}$/;
const MAX_SEND_BYTES = 16 * 1024 * 1024; // WhatsApp's own limit for files is about 16 MB
const MARKER = 'needs_relink';
const V7 = 'baileys7'; // written once a login has connected under the current library

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

  marker(id) {
    return path.join(this.dir(id), MARKER);
  }

  /** A paired login that was never connected by the current library (made by the old gateway), so its keys cannot be trusted. */
  legacyLogin(id) {
    if (fs.existsSync(path.join(this.dir(id), V7))) return false;

    try {
      return JSON.parse(fs.readFileSync(path.join(this.dir(id), 'auth', 'creds.json'), 'utf8')).registered === true;
    } catch {
      return false;
    }
  }

  writeMeta(id, meta) {
    const file = path.join(this.dir(id), 'meta.json');
    const tmp = `${file}.${process.pid}.tmp`;

    fs.writeFileSync(tmp, JSON.stringify(meta), { mode: 0o600 });
    fs.renameSync(tmp, file);
  }

  restore() {
    for (const id of fs.readdirSync(this.root)) {
      const metaFile = path.join(this.dir(id), 'meta.json');
      if (!SessionManager.validId(id) || !fs.existsSync(metaFile) || !fs.existsSync(path.join(this.dir(id), 'auth'))) continue;

      try {
        const meta = JSON.parse(fs.readFileSync(metaFile, 'utf8'));

        if (fs.existsSync(this.marker(id))) {
          // Flagged as broken before the restart: wait for the agency to scan again instead of reusing the bad login.
          this.sessions.set(id, { id, status: 'needs_relink', qr: null, qrAt: 0, conn: null, meta, retries: 0, decryptFails: 0 });
          continue;
        }

        if (this.legacyLogin(id)) {
          const session = { id, status: 'qr_required', qr: null, qrAt: 0, conn: null, meta, retries: 0, decryptFails: 0 };
          this.sessions.set(id, session);
          this.needsRelink(session, 'login dibuat oleh versi gateway lama, scan ulang diperlukan');
          continue;
        }

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

    let existing = this.get(id);
    const meta = { webhook_url, signing_secret };

    fs.mkdirSync(this.dir(id), { recursive: true });

    if (existing?.status === 'needs_relink' || fs.existsSync(this.marker(id))) {
      // A broken login cannot be repaired: drop it so a fresh QR can be scanned.
      clearTimeout(existing?.timer);
      this.sessions.delete(id);
      existing = undefined;
      fs.rmSync(path.join(this.dir(id), 'auth'), { recursive: true, force: true });
      fs.rmSync(this.marker(id), { force: true });
    }

    this.writeMeta(id, meta);

    if (existing) {
      existing.meta = meta;
      if (existing.status === 'connected' || existing.conn) return existing.status;
    }

    return this.open(id, meta);
  }

  open(id, meta) {
    const session = this.get(id) ?? { id, status: 'qr_required', qr: null, qrAt: 0, conn: null, meta, retries: 0, decryptFails: 0 };
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
          try {
            fs.writeFileSync(path.join(this.dir(id), V7), '1', { mode: 0o600 });
          } catch (err) {
            this.log?.warn({ id, err: err.message }, 'penanda versi login gagal ditulis');
          }
          this.emit(session, { event: 'session', status: 'connected' });
        },
        onClose: ({ loggedOut, needsRelink }) => (needsRelink ? this.needsRelink(session, 'WhatsApp menolak login yang tersimpan') : this.closed(session, loggedOut)),
        onDecryptOk: () => {
          session.decryptFails = 0;
        },
        onDecryptFail: () => {
          // Several messages in a row that cannot be opened means the saved keys no longer match WhatsApp's.
          if (++session.decryptFails >= (this.config.relinkAfterFails ?? 3) && session.status !== 'needs_relink') {
            this.needsRelink(session, `${session.decryptFails} pesan berturut-turut tidak bisa dibuka`);
          }
        },
        onMessage: (m) => this.emit(session, { event: 'message', ...m }),
        onStatus: (s) => this.emit(session, { event: 'status', ...s }),
      },
    });

    return session.status;
  }

  /** The login is unusable: stop, remember it across restarts, and tell the CRM so the agency scans again. */
  needsRelink(session, reason) {
    if (session.status === 'needs_relink') return;

    this.log?.warn({ id: session.id, reason }, 'sesi WhatsApp tidak sehat, perlu scan ulang');
    clearTimeout(session.timer);
    session.status = 'needs_relink';
    session.qr = null;

    const conn = session.conn;
    session.conn = null;
    try {
      conn?.end?.();
    } catch {
      // already closed
    }

    try {
      fs.writeFileSync(this.marker(session.id), new Date().toISOString(), { mode: 0o600 });
    } catch (err) {
      this.log?.error({ id: session.id, err: err.message }, 'penanda perlu-scan-ulang gagal ditulis');
    }

    this.emit(session, { event: 'session', status: 'needs_relink', reason });
  }

  closed(session, loggedOut) {
    if (session.status === 'needs_relink') return;

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
    if (s.status === 'needs_relink') return { status: 'needs_relink', qr: null, expires_in: 0 };

    return { status: 'qr_required', qr: s.qr, expires_in: s.qr ? Math.max(1, 20 - Math.floor((Date.now() - s.qrAt) / 1000)) : 0 };
  }

  async send(id, to, text) {
    const s = this.get(id);

    if (s?.status === 'needs_relink') throw new SessionError('Sesi WhatsApp perlu scan ulang.', 503);
    if (!s || s.status !== 'connected' || !s.conn) throw new SessionError('Sesi WhatsApp belum terhubung.', 503);
    if (typeof to !== 'string' || !E164.test(to)) throw new SessionError('Nomor tujuan tidak valid.');
    if (typeof text !== 'string' || text.trim() === '' || text.length > 4096) throw new SessionError('Isi pesan kosong atau terlalu panjang.');

    return s.conn.send(to.replace(/^\+/, '') + '@s.whatsapp.net', text);
  }

  /** Sends a picture or a file. `media`: { type: 'image'|'document', caption?, mime_type, filename?, data (base64) }. */
  async sendMedia(id, to, media) {
    const s = this.get(id);

    if (s?.status === 'needs_relink') throw new SessionError('Sesi WhatsApp perlu scan ulang.', 503);
    if (!s || s.status !== 'connected' || !s.conn) throw new SessionError('Sesi WhatsApp belum terhubung.', 503);
    if (typeof to !== 'string' || !E164.test(to)) throw new SessionError('Nomor tujuan tidak valid.');
    if (typeof media.data !== 'string' || typeof media.mime_type !== 'string' || !/^[\w.+-]+\/[\w.+-]+$/.test(media.mime_type)) throw new SessionError('Data file tidak valid.');
    if (media.caption != null && (typeof media.caption !== 'string' || media.caption.length > 1024)) throw new SessionError('Keterangan terlalu panjang.');

    const buffer = Buffer.from(media.data, 'base64');
    if (buffer.length === 0) throw new SessionError('File kosong.');
    if (buffer.length > MAX_SEND_BYTES) throw new SessionError('File lebih dari 16 MB.', 413);

    return s.conn.sendMedia(to.replace(/^\+/, '') + '@s.whatsapp.net', {
      type: media.type, buffer, mimetype: media.mime_type, filename: String(media.filename ?? 'file').slice(0, 120), caption: media.caption || undefined,
    });
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
