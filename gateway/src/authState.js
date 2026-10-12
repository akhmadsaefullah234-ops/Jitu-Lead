'use strict';

const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');

/**
 * Multi-file auth state (same layout as Baileys' useMultiFileAuthState, so existing
 * login folders keep working) whose writes are atomic: data goes to a temp file,
 * is flushed to disk, then renamed over the real file. A crash or power cut leaves
 * either the old or the new file, never half of one. creds.json also keeps a
 * `.bak` of the last good copy.
 *
 * `lib` is the loaded Baileys module (it is ESM, so the caller imports it).
 */
async function useAtomicAuthState(folder, lib) {
  const { BufferJSON, initAuthCreds, proto } = lib;

  await fsp.mkdir(folder, { recursive: true, mode: 0o700 });

  // Temp files left behind by a crash are garbage; the real files are intact.
  for (const name of fs.readdirSync(folder)) {
    if (name.endsWith('.tmp')) fs.rmSync(path.join(folder, name), { force: true });
  }

  const fix = (file) => file.replace(/\//g, '__').replace(/:/g, '-');
  const chains = new Map();

  // Writes to the same file run one after another.
  const serial = (file, job) => {
    const next = (chains.get(file) ?? Promise.resolve()).catch(() => {}).then(job);
    chains.set(file, next);
    next.finally(() => chains.get(file) === next && chains.delete(file)).catch(() => {});

    return next;
  };

  async function writeAtomic(file, data, { backup = false } = {}) {
    const target = path.join(folder, fix(file));
    const tmp = `${target}.${process.pid}.${Date.now()}.tmp`;
    const json = JSON.stringify(data, BufferJSON.replacer);

    const handle = await fsp.open(tmp, 'w', 0o600);
    try {
      await handle.writeFile(json);
      await handle.sync();
    } finally {
      await handle.close();
    }

    if (backup) await fsp.copyFile(target, `${target}.bak`).catch(() => {});
    await fsp.rename(tmp, target);

    // Persist the rename itself.
    const dir = await fsp.open(folder, 'r').catch(() => null);
    if (dir) {
      await dir.sync().catch(() => {});
      await dir.close();
    }
  }

  async function readJson(file) {
    try {
      return JSON.parse(await fsp.readFile(path.join(folder, fix(file)), 'utf8'), BufferJSON.reviver);
    } catch {
      return null;
    }
  }

  const write = (file, data, opts) => serial(file, () => writeAtomic(file, data, opts));
  const remove = (file) => serial(file, () => fsp.rm(path.join(folder, fix(file)), { force: true }));

  const creds = (await readJson('creds.json')) || (await readJson('creds.json.bak')) || initAuthCreds();

  return {
    state: {
      creds,
      keys: {
        get: async (type, ids) => {
          const data = {};
          await Promise.all(
            ids.map(async (id) => {
              let value = await readJson(`${type}-${id}.json`);
              if (type === 'app-state-sync-key' && value) value = proto.Message.AppStateSyncKeyData.fromObject(value);
              data[id] = value;
            }),
          );

          return data;
        },
        set: async (data) => {
          const jobs = [];
          for (const category in data) {
            for (const id in data[category]) {
              const value = data[category][id];
              const file = `${category}-${id}.json`;
              jobs.push(value ? write(file, value) : remove(file));
            }
          }
          await Promise.all(jobs);
        },
      },
    },
    saveCreds: () => write('creds.json', creds, { backup: true }),
  };
}

module.exports = { useAtomicAuthState };
