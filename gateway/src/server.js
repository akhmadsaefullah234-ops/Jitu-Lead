'use strict';

const http = require('node:http');
const crypto = require('node:crypto');
const { SessionManager, SessionError } = require('./sessions');

function sameKey(given, expected) {
  const a = crypto.createHash('sha256').update(String(given)).digest();
  const b = crypto.createHash('sha256').update(expected).digest();

  return crypto.timingSafeEqual(a, b);
}

function readJson(req, limit = 64 * 1024) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];

    req.on('data', (c) => {
      size += c.length;
      if (size > limit) {
        reject(new SessionError('Isi permintaan terlalu besar.', 413));
        req.destroy();
      } else chunks.push(c);
    });
    req.on('end', () => {
      if (size === 0) return resolve({});
      try {
        resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')));
      } catch {
        reject(new SessionError('JSON tidak valid.'));
      }
    });
    req.on('error', reject);
  });
}

function createServer({ config, sessions }) {
  return http.createServer(async (req, res) => {
    const reply = (status, body) => {
      const json = JSON.stringify(body);
      res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(json), 'Cache-Control': 'no-store' });
      res.end(json);
    };

    try {
      const route = `${req.method} ${req.url.split('?')[0]}`;

      if (route === 'GET /health') return reply(200, { ok: true, sessions: sessions.sessions.size });

      const auth = req.headers.authorization || '';
      if (!auth.startsWith('Bearer ') || !sameKey(auth.slice(7), config.apiKey)) return reply(401, { error: 'API key salah.' });

      const id = req.headers['x-session-id'];
      if (!SessionManager.validId(id)) return reply(400, { error: 'Header X-Session-Id wajib diisi.' });

      switch (route) {
        case 'GET /session':
          return reply(200, { status: sessions.status(id) });

        case 'POST /session/start': {
          const body = await readJson(req);
          return reply(200, { status: sessions.start(id, body) });
        }

        case 'GET /session/qr':
          return reply(200, sessions.qr(id));

        case 'POST /session/logout':
          await sessions.logout(id);
          return reply(200, { status: 'disconnected' });

        case 'POST /messages': {
          const body = await readJson(req);
          if ((body.type ?? 'text') !== 'text') return reply(400, { error: 'Hanya pesan teks yang didukung.' });
          return reply(200, { id: await sessions.send(id, body.to, body.text) });
        }

        default:
          return reply(404, { error: 'Tidak ditemukan.' });
      }
    } catch (err) {
      if (err instanceof SessionError) return reply(err.status, { error: err.message });
      sessions.log?.error({ err: err.message }, 'kesalahan tak terduga');
      return reply(500, { error: 'Kesalahan di gateway.' });
    }
  });
}

module.exports = { createServer };
