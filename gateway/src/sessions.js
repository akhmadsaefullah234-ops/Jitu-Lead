'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { deliver } = require('./webhook');
const { findLabel, isBusiness, clean } = require('./labels');

const ID = /^[A-Za-z0-9_-]{8,128}$/;
const E164 = /^\+?\d{6,15}$/;
const MAX_SEND_BYTES = 16 * 1024 * 1024; // WhatsApp's own limit for files is about 16 MB
const MARKER = 'needs_relink';
const LABELS = 'labels.json';
const MAX_CHAT_LABELS = 5000; // chats whose labels are remembered, to skip putting on a label a chat already has
const masked = (phone) => '…' + String(phone).replace(/\D/g, '').slice(-4);
const V7 = 'baileys7'; // written once a login has connected under the current library
const LOGGED_OUT = 'perangkat tertaut dilepas dari HP';

class SessionError extends Error {
  /** `code`: a word the CRM acts on (label_not_found, not_business), sent as `error` with the text as `message`. */
  constructor(message, status = 400, code = null) {
    super(message);
    this.status = status;
    this.code = code;
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
    this.resyncEveryMs = config.labelResyncEveryMs ?? 10 * 60 * 1000; // at most one label resync per session per 10 minutes
    this.resyncDelayMs = config.labelResyncDelayMs ?? 15000; // after connecting, before the automatic resync
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

    fs.writeFileSync(tmp, JSON.stringify(meta), { mode: 0o640 });
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
    session.labels ??= this.readLabels(id);
    session.chatLabels ??= new Map();
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
            fs.writeFileSync(path.join(this.dir(id), V7), '1', { mode: 0o640 });
          } catch (err) {
            this.log?.warn({ id, err: err.message }, 'penanda versi login gagal ditulis');
          }
          this.emit(session, { event: 'session', status: 'connected' });
          this.scheduleLabelResync(session);
        },
        onClose: ({ loggedOut, needsRelink }) => {
          // A logout asked by the CRM, or a session already replaced, is not news.
          if (this.sessions.get(id) !== session || session.loggingOut) return;
          clearTimeout(session.resyncTimer);
          // A linked login removed on the phone needs a new scan; one that never finished pairing just starts over.
          if (loggedOut && fs.existsSync(path.join(this.dir(id), V7))) return this.needsRelink(session, LOGGED_OUT);
          if (loggedOut) return this.forget(session);
          return needsRelink ? this.needsRelink(session, 'WhatsApp menolak login yang tersimpan') : this.closed(session);
        },
        onDecryptOk: () => {
          session.decryptFails = 0;
        },
        onDecryptFail: () => {
          // Several messages in a row that cannot be opened means the saved keys no longer match WhatsApp's.
          if (++session.decryptFails >= (this.config.relinkAfterFails ?? 3) && session.status !== 'needs_relink') {
            this.needsRelink(session, `${session.decryptFails} pesan berturut-turut tidak bisa dibuka`);
          }
        },
        onLabel: (label) => this.rememberLabel(session, label),
        onLabelAssociation: ({ type, chatId, labelId }) => this.rememberChatLabel(session, chatId, labelId, type !== 'remove'),
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
    clearTimeout(session.resyncTimer);
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
      fs.writeFileSync(this.marker(session.id), new Date().toISOString(), { mode: 0o640 });
    } catch (err) {
      this.log?.error({ id: session.id, err: err.message }, 'penanda perlu-scan-ulang gagal ditulis');
    }

    this.emit(session, { event: 'session', status: 'needs_relink', reason });
  }

  /** Logged out before it ever connected: nothing worth keeping. */
  forget(session) {
    session.conn = null;
    this.wipe(session.id);
    this.sessions.delete(session.id);
    this.emit(session, { event: 'session', status: 'disconnected' });
  }

  /** A dropped connection (not a logout). */
  closed(session) {
    if (session.status === 'needs_relink') return;

    session.conn = null;
    session.qr = null;
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

  /** Labels are only reported when they change, so they are kept on disk across restarts. */
  readLabels(id) {
    try {
      const list = JSON.parse(fs.readFileSync(path.join(this.dir(id), LABELS), 'utf8'));

      return new Map((Array.isArray(list) ? list : []).filter((l) => l?.id).map((l) => [String(l.id), clean(l)]));
    } catch {
      return new Map();
    }
  }

  rememberLabel(session, label) {
    if (!label.deleted) session.resyncSeen?.add(String(label.id));
    const known = session.labels.get(String(label.id)) ?? {};
    session.labels.set(String(label.id), clean({ ...known, ...Object.fromEntries(Object.entries(label).filter(([, v]) => v !== undefined)) }));

    try {
      const file = path.join(this.dir(session.id), LABELS);
      fs.writeFileSync(`${file}.tmp`, JSON.stringify([...session.labels.values()]), { mode: 0o640 });
      fs.renameSync(`${file}.tmp`, file);
    } catch (err) {
      this.log?.warn({ id: session.id, err: err.message }, 'daftar label gagal disimpan');
    }
  }

  rememberChatLabel(session, chatId, labelId, on) {
    const set = session.chatLabels.get(chatId) ?? new Set();
    on ? set.add(labelId) : set.delete(labelId);
    session.chatLabels.delete(chatId);
    session.chatLabels.set(chatId, set);
    if (session.chatLabels.size > MAX_CHAT_LABELS) session.chatLabels.delete(session.chatLabels.keys().next().value);
  }

  /** The account's WhatsApp Business labels: [{ id, name, color }]. */
  labels(id) {
    const s = this.get(id);
    if (!s) throw new SessionError('Sesi WhatsApp belum terhubung.', 503);

    return [...(s.labels ?? new Map()).values()].filter((l) => !l.deleted).map(({ id: labelId, name, color }) => ({ id: labelId, name, color }));
  }

  /**
   * Asks WhatsApp for every label again, for a number linked before the gateway kept labels (they are otherwise only
   * reported when they change). At most once per 10 minutes per session: a call sooner returns the labels already
   * known with `resynced: false` and `retry_after` (seconds). Labels the full resync no longer reports are marked
   * deleted. Only labels are taken from it.
   */
  async resyncLabels(id) {
    const s = this.get(id);

    if (s?.status === 'needs_relink') throw new SessionError('Sesi WhatsApp perlu scan ulang.', 503);
    if (!s || s.status !== 'connected' || !s.conn) throw new SessionError('Sesi WhatsApp belum terhubung.', 503);
    if (typeof s.conn.resyncLabels !== 'function') throw new SessionError('resync_failed', 502, 'resync_failed');

    const wait = (s.resyncAt ?? 0) + this.resyncEveryMs - Date.now();
    if (wait > 0) return { labels: this.labels(id), resynced: false, retry_after: Math.ceil(wait / 1000) };

    s.resyncAt = Date.now();
    clearTimeout(s.resyncTimer);
    const seen = (s.resyncSeen = new Set());

    try {
      await s.conn.resyncLabels();
    } catch (err) {
      this.log?.warn({ id, err: err.message }, 'sinkron ulang label gagal');
      throw new SessionError('resync_failed', 502, 'resync_failed');
    } finally {
      s.resyncSeen = null;
    }

    if (seen.size > 0) {
      for (const label of [...s.labels.values()]) {
        if (!label.deleted && !seen.has(String(label.id))) this.rememberLabel(s, { ...label, deleted: true });
      }
    }

    const labels = this.labels(id);
    this.log?.info({ id, labels: labels.length }, 'label WhatsApp disinkron ulang');

    return { labels, resynced: true };
  }

  /** Once connected, a number with no labels on record (linked before labels were kept) asks for them once. */
  scheduleLabelResync(session) {
    clearTimeout(session.resyncTimer);
    if ([...session.labels.values()].some((l) => !l.deleted)) return;

    session.resyncTimer = setTimeout(() => {
      if (this.sessions.get(session.id) !== session || session.status !== 'connected' || !session.conn) return;
      if ([...session.labels.values()].some((l) => !l.deleted) || isBusiness(session.conn.platform?.()) === false) return;

      this.resyncLabels(session.id).catch(() => {});
    }, this.resyncDelayMs);
    session.resyncTimer.unref?.();
  }

  /**
   * Puts the label named `label` on the chat with `phone`. Only labels the account already has: an unknown one is
   * "label_not_found" (put it on any chat once on the phone so it syncs here), a personal account "not_business".
   */
  async addChatLabel(id, phone, label) {
    const s = this.get(id);

    if (s?.status === 'needs_relink') throw new SessionError('Sesi WhatsApp perlu scan ulang.', 503);
    if (!s || s.status !== 'connected' || !s.conn) throw new SessionError('Sesi WhatsApp belum terhubung.', 503);
    if (typeof phone !== 'string' || !E164.test(phone)) throw new SessionError('Nomor tujuan tidak valid.');
    if (typeof label !== 'string' || label.trim() === '' || label.length > 60) throw new SessionError('Nama label tidak valid.');

    const business = isBusiness(s.conn.platform?.());
    const found = findLabel([...s.labels.values()], label);

    if (business === false) throw new SessionError('not_business', 422, 'not_business');
    if (!found) throw new SessionError('label_not_found', 422, 'label_not_found');

    const jid = phone.replace(/^\+/, '') + '@s.whatsapp.net';
    if (s.chatLabels.get(jid)?.has(found.id)) return { label: { id: found.id, name: found.name }, already: true };

    await s.conn.addChatLabel(jid, found.id);
    this.rememberChatLabel(s, jid, found.id, true);
    this.log?.info({ id, chat: masked(phone), label: found.name }, 'label WhatsApp dipasang');

    return { label: { id: found.id, name: found.name }, already: false };
  }

  async logout(id) {
    const s = this.get(id);

    if (s) {
      s.loggingOut = true;
      clearTimeout(s.timer);
      clearTimeout(s.resyncTimer);
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
