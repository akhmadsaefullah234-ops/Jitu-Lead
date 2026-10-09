'use strict';

const path = require('node:path');

function load(env = process.env) {
  const apiKey = env.GATEWAY_API_KEY || '';

  if (apiKey.length < 24) {
    throw new Error('GATEWAY_API_KEY wajib diisi, minimal 24 karakter (sama dengan WHATSAPP_GATEWAY_API_KEY di CRM).');
  }

  return {
    apiKey,
    port: Number(env.PORT || 3100),
    host: env.HOST || '127.0.0.1',
    dataDir: path.resolve(env.DATA_DIR || './data'),
    maxSessions: Number(env.MAX_SESSIONS || 50),
    webhookTimeoutMs: Number(env.WEBHOOK_TIMEOUT_MS || 10000),
    logLevel: env.LOG_LEVEL || 'info',
  };
}

module.exports = { load };
