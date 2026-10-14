'use strict';

const { useAtomicAuthState } = require('./authState');

// Baileys 7 is an ES module; load it once, lazily, from this CommonJS file.
let libPromise;
const loadBaileys = () => (libPromise ??= import('@whiskeysockets/baileys'));

const STATUS = { 0: 'failed', 2: 'sent', 3: 'delivered', 4: 'read', 5: 'read' };
const STUB_CIPHERTEXT = 2; // proto.WebMessageInfo.StubType.CIPHERTEXT: the message arrived but could not be decrypted
const PN = '@s.whatsapp.net';
const LID = '@lid';

function textOf(message = {}) {
  return message.conversation || message.extendedTextMessage?.text || message.imageMessage?.caption || message.videoMessage?.caption || null;
}

function typeOf(message = {}) {
  if (message.conversation || message.extendedTextMessage) return 'text';
  if (message.imageMessage) return 'image';
  if (message.videoMessage) return 'video';
  if (message.audioMessage) return 'audio';
  if (message.documentMessage) return 'document';

  return 'unsupported';
}

// Messages that carry no chat content for the CRM (key exchange, deletions, ...).
function isSystemOnly(message = {}) {
  const keys = Object.keys(message).filter((k) => k !== 'messageContextInfo');

  return keys.length > 0 && keys.every((k) => ['protocolMessage', 'senderKeyDistributionMessage', 'reactionMessage', 'peerDataOperationRequestResponseMessage'].includes(k));
}

function seconds(t) {
  const n = typeof t === 'object' && t !== null ? (t.toNumber ? t.toNumber() : t.low) : Number(t);

  return Number.isFinite(n) && n > 0 ? n : Math.floor(Date.now() / 1000);
}

const digits = (jid) => String(jid).split('@')[0].split(':')[0];

/** Last four digits only, so logs show which chat without storing a whole phone number. */
const masked = (jid) => '…' + digits(jid || '').slice(-4);

/**
 * The sender's phone-number jid when the event itself carries it: Baileys 7 puts
 * it in remoteJidAlt, Baileys 6 in senderPn, and old-style chats are the jid itself.
 */
function phoneJid(key = {}) {
  return [key.remoteJidAlt, key.senderPn, key.remoteJid].find((j) => typeof j === 'string' && j.endsWith(PN)) ?? null;
}

/**
 * Decides what to do with a WhatsApp event:
 *   { action: 'forward', inbound }   send to the CRM
 *   { action: 'ciphertext', own? }   arrived but undecryptable (a retry may still deliver it; `own`: sent by our own other device)
 *   { action: 'lookup', lid }        a @lid chat whose phone number is not in the event
 *   { action: 'skip', reason, quiet } not for the CRM; `quiet` marks the expected cases (own messages, groups)
 */
function classify(msg) {
  const key = msg.key ?? {};
  const jid = key.remoteJid || '';

  if (key.fromMe) {
    // Our own account's other devices failing to decrypt is a sign of a broken session, so it is reported.
    return !msg.message && msg.messageStubType === STUB_CIPHERTEXT ? { action: 'ciphertext', own: true } : { action: 'skip', reason: 'pesan_sendiri', quiet: true };
  }
  if (!jid.endsWith(PN) && !jid.endsWith(LID)) return { action: 'skip', reason: 'bukan_chat_pribadi', quiet: true };
  if (!msg.message) {
    return msg.messageStubType === STUB_CIPHERTEXT ? { action: 'ciphertext' } : { action: 'skip', reason: 'tanpa_isi', quiet: false };
  }
  if (isSystemOnly(msg.message)) return { action: 'skip', reason: 'pesan_sistem', quiet: true };

  const phone = phoneJid(key);
  if (!phone) return { action: 'lookup', lid: jid };

  return { action: 'forward', inbound: build(msg, digits(phone)) };
}

function build(msg, from) {
  return {
    id: msg.key.id,
    from,
    type: typeOf(msg.message),
    text: textOf(msg.message),
    name: msg.pushName || null,
    timestamp: seconds(msg.messageTimestamp),
  };
}

/** Turns a WhatsApp event into the shape the CRM expects. Returns null for chats the CRM should not see. */
function toInbound(msg) {
  const c = classify(msg);

  return c.action === 'forward' ? c.inbound : null;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Pictures bigger than this are not sent to the CRM (the chat still shows that a picture arrived).
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

function connector({ log, decryptWaitMs = 45000, lookupRetryMs = 3000, loadLib = loadBaileys, download = null, maxImageBytes = MAX_IMAGE_BYTES }) {
  return ({ authDir, handlers }) => {
    let sock;
    let fetchMedia = null;
    let closed = false;
    const pending = new Map(); // message id -> timer, for undecryptable messages awaiting WhatsApp's retry
    const sentCache = new Map(); // recent outgoing messages, which WhatsApp may ask us to resend

    const clearPending = () => {
      for (const p of pending.values()) clearTimeout(p);
      pending.clear();
    };

    async function phoneForLid(lid) {
      for (let attempt = 0; attempt < 2; attempt++) {
        try {
          const pn = await sock.signalRepository?.lidMapping?.getPNForLID(lid);
          if (pn) return digits(pn);
        } catch (err) {
          log.debug({ err: err.message }, 'lookup nomor dari LID gagal');
        }
        if (attempt === 0) await sleep(lookupRetryMs);
      }

      return null;
    }

    /** Downloads and decrypts a picture so the CRM can show it. A failure only costs the picture, never the message. */
    async function withMedia(msg, inbound) {
      if (inbound.type !== 'image' || !fetchMedia) return inbound;

      const info = msg.message?.imageMessage ?? {};
      const mime = info.mimetype || 'image/jpeg';
      const size = Number(info.fileLength?.toNumber ? info.fileLength.toNumber() : info.fileLength) || 0;

      if (size > maxImageBytes) {
        log.info({ id: msg.key?.id, bytes: size }, 'gambar terlalu besar, tidak dikirim ke CRM');

        return { ...inbound, media: { mime_type: mime, error: 'too_large' } };
      }

      try {
        const buffer = await fetchMedia(msg);

        if (buffer.length > maxImageBytes) return { ...inbound, media: { mime_type: mime, error: 'too_large' } };

        return { ...inbound, media: { mime_type: mime, data: buffer.toString('base64') } };
      } catch (err) {
        log.warn({ id: msg.key?.id, err: err.message }, 'gambar gagal diunduh dari WhatsApp');

        return { ...inbound, media: { mime_type: mime, error: 'download_failed' } };
      }
    }

    function skipped(msg, reason, quiet) {
      log[quiet ? 'debug' : 'info']({ id: msg.key?.id, chat: masked(msg.key?.remoteJid), reason }, 'pesan tidak diteruskan ke CRM');
    }

    async function onUpsert({ messages, type }) {
      for (const msg of messages) {
        const id = msg.key?.id;
        const retried = pending.has(id);

        // History sync arrives as 'append'; only live messages (or a retried one we are waiting for) count.
        if (type !== 'notify' && !retried) continue;

        const c = classify(msg);

        if (c.action === 'ciphertext') {
          if (c.own) {
            log.info({ id, chat: masked(msg.key?.remoteJid), error: msg.messageStubParameters?.[0] }, 'pesan dari perangkat lain akun ini tidak bisa dibuka');
            handlers.onDecryptFail?.({ id, own: true });
            continue;
          }
          if (!id || retried) continue;
          log.info({ id, chat: masked(msg.key?.remoteJid), error: msg.messageStubParameters?.[0] }, 'pesan belum bisa dibuka, menunggu kiriman ulang dari WhatsApp');
          const timer = setTimeout(() => {
            pending.delete(id);
            log.warn({ id, chat: masked(msg.key?.remoteJid) }, 'pesan tetap tidak bisa dibuka setelah menunggu, tidak diteruskan ke CRM');
            handlers.onDecryptFail?.({ id });
          }, decryptWaitMs);
          timer.unref?.();
          pending.set(id, timer);
          continue;
        }

        if (retried) {
          clearTimeout(pending.get(id));
          pending.delete(id);
          if (c.action === 'forward' || c.action === 'lookup') log.info({ id }, 'pesan akhirnya terbaca setelah dikirim ulang');
        }

        if (c.action === 'skip') {
          skipped(msg, c.reason, c.quiet);
        } else if (c.action === 'lookup') {
          const phone = await phoneForLid(c.lid);
          if (phone) {
            handlers.onDecryptOk?.();
            handlers.onMessage(await withMedia(msg, build(msg, phone)));
          } else {
            log.warn({ id, chat: masked(c.lid) }, 'pesan tidak diteruskan ke CRM: nomor HP pengirim tidak diketahui (LID tanpa pemetaan)');
          }
        } else {
          handlers.onDecryptOk?.();
          handlers.onMessage(await withMedia(msg, c.inbound));
        }
      }
    }

    (async () => {
      const baileys = await loadLib();
      const makeWASocket = baileys.default;
      const { DisconnectReason, fetchLatestBaileysVersion, Browsers, makeCacheableSignalKeyStore } = baileys;

      const { state, saveCreds } = await useAtomicAuthState(authDir, baileys);
      const { version } = await fetchLatestBaileysVersion().catch(() => ({ version: undefined }));
      const baileysLog = log.child({ module: 'baileys' }, { level: 'warn' });
      fetchMedia = download ?? ((m) => baileys.downloadMediaMessage(m, 'buffer', {}, { logger: baileysLog, reuploadRequest: sock.updateMediaMessage }));

      sock = makeWASocket({
        version,
        auth: { creds: state.creds, keys: makeCacheableSignalKeyStore(state.keys, baileysLog) },
        browser: Browsers.ubuntu('JITU LEAD'),
        logger: baileysLog,
        markOnlineOnConnect: false,
        syncFullHistory: false,
        getMessage: async (key) => sentCache.get(key.id),
      });

      const broken = new Set([DisconnectReason.badSession, DisconnectReason.multideviceMismatch, DisconnectReason.forbidden]);

      sock.ev.on('creds.update', () => saveCreds().catch((err) => log.error({ err: err.message }, 'gagal menyimpan login WhatsApp')));
      sock.ev.on('connection.update', ({ connection, lastDisconnect, qr }) => {
        if (qr) handlers.onQr(qr);
        if (connection === 'open') handlers.onOpen();
        if (connection === 'close' && !closed) {
          closed = true;
          clearPending();
          const code = lastDisconnect?.error?.output?.statusCode;
          if (code && code !== DisconnectReason.loggedOut) log.warn({ code, error: lastDisconnect?.error?.message }, 'koneksi WhatsApp terputus');
          handlers.onClose({ loggedOut: code === DisconnectReason.loggedOut, needsRelink: broken.has(code) });
        }
      });
      sock.ev.on('messages.upsert', (event) => {
        onUpsert(event).catch((err) => log.error({ err: err.message }, 'pesan masuk gagal diproses'));
      });
      sock.ev.on('messages.update', (updates) => {
        for (const u of updates) {
          const status = STATUS[u.update?.status];
          if (u.key?.fromMe && status) handlers.onStatus({ id: u.key.id, status });
        }
      });
    })().catch((err) => {
      log.error({ err: err.message }, 'koneksi WhatsApp gagal dibuat');
      if (!closed) {
        closed = true;
        handlers.onClose({ loggedOut: false });
      }
    });

    return {
      async send(jid, text) {
        const sent = await sock.sendMessage(jid, { text });
        if (sent?.message) {
          sentCache.set(sent.key.id, sent.message);
          if (sentCache.size > 500) sentCache.delete(sentCache.keys().next().value);
        }

        return sent.key.id;
      },
      async logout() {
        closed = true;
        clearPending();
        await sock?.logout();
      },
      end() {
        closed = true;
        clearPending();
        sock?.end(undefined);
      },
    };
  };
}

module.exports = { connector, toInbound, classify };
