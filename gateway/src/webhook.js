'use strict';

const crypto = require('node:crypto');

function sign(body, secret) {
  return 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex');
}

/**
 * Sends one event to the CRM, signed with the session's secret. Retries a few
 * times because the CRM stores each message id once, so a repeat is harmless.
 */
async function deliver({ url, secret, payload, timeoutMs = 10000, fetchImpl = fetch, sleep = (ms) => new Promise((r) => setTimeout(r, ms)), log }) {
  if (!url || !secret) return false;

  const body = JSON.stringify(payload);

  for (let attempt = 1; attempt <= 4; attempt++) {
    try {
      const res = await fetchImpl(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Gateway-Signature': sign(body, secret) },
        body,
        redirect: 'error',
        signal: AbortSignal.timeout(timeoutMs),
      });

      if (res.ok) return true;
      // The CRM refused it for good (bad signature, unknown channel): do not hammer it.
      if (res.status >= 400 && res.status < 500 && res.status !== 429) {
        log?.warn({ status: res.status, event: payload.event }, 'webhook ditolak CRM');
        return false;
      }
    } catch (err) {
      log?.warn({ err: err.message, attempt }, 'webhook gagal terkirim');
    }

    if (attempt < 4) await sleep(500 * 2 ** attempt);
  }

  return false;
}

module.exports = { sign, deliver };
