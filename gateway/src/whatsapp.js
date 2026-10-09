'use strict';

const baileys = require('@whiskeysockets/baileys');

const makeWASocket = baileys.default;
const { useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, Browsers } = baileys;

const STATUS = { 0: 'failed', 2: 'sent', 3: 'delivered', 4: 'read', 5: 'read' };

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

function seconds(t) {
  const n = typeof t === 'object' && t !== null ? (t.toNumber ? t.toNumber() : t.low) : Number(t);

  return Number.isFinite(n) && n > 0 ? n : Math.floor(Date.now() / 1000);
}

/** Turns a WhatsApp event into the shape the CRM expects. Returns null for chats the CRM should not see. */
function toInbound(msg) {
  const jid = msg.key?.remoteJid || '';

  if (msg.key?.fromMe || !msg.message || (!jid.endsWith('@s.whatsapp.net') && !msg.key?.senderPn)) return null;

  const from = (msg.key.senderPn || jid).split('@')[0].split(':')[0];

  return {
    id: msg.key.id,
    from,
    type: typeOf(msg.message),
    text: textOf(msg.message),
    name: msg.pushName || null,
    timestamp: seconds(msg.messageTimestamp),
  };
}

function connector({ log }) {
  return ({ authDir, handlers }) => {
    let sock;
    let closed = false;

    (async () => {
      const { state, saveCreds } = await useMultiFileAuthState(authDir);
      const { version } = await fetchLatestBaileysVersion().catch(() => ({ version: undefined }));

      sock = makeWASocket({ version, auth: state, browser: Browsers.ubuntu('JITU LEAD'), logger: log.child({ module: 'baileys' }, { level: 'warn' }), printQRInTerminal: false, markOnlineOnConnect: false, syncFullHistory: false });

      sock.ev.on('creds.update', saveCreds);
      sock.ev.on('connection.update', ({ connection, lastDisconnect, qr }) => {
        if (qr) handlers.onQr(qr);
        if (connection === 'open') handlers.onOpen();
        if (connection === 'close' && !closed) {
          closed = true;
          handlers.onClose({ loggedOut: lastDisconnect?.error?.output?.statusCode === DisconnectReason.loggedOut });
        }
      });
      sock.ev.on('messages.upsert', ({ messages, type }) => {
        if (type !== 'notify') return;
        for (const m of messages) {
          const inbound = toInbound(m);
          if (inbound) handlers.onMessage(inbound);
        }
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
        return sent.key.id;
      },
      async logout() {
        closed = true;
        await sock?.logout();
      },
      end() {
        closed = true;
        sock?.end(undefined);
      },
    };
  };
}

module.exports = { connector, toInbound };
