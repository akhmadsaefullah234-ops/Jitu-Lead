'use strict';

const pino = require('pino');
const { load } = require('./config');
const { SessionManager } = require('./sessions');
const { createServer } = require('./server');
const { connector } = require('./whatsapp');

const config = load();
const log = pino({ level: config.logLevel });
const sessions = new SessionManager({ config, connector: connector({ log }), log });

sessions.restore();

const server = createServer({ config, sessions });
server.listen(config.port, config.host, () => log.info({ port: config.port, host: config.host }, 'gateway siap'));

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => server.close(() => process.exit(0)));
}
