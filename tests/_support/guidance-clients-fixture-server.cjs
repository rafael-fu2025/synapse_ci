'use strict';

// Read-only synthetic presentation server; no application backend or real client data.
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { once } = require('node:events');

const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
const renderer = path.join(__dirname, 'render-guidance-clients-fixture.php');
const types = { '.js': 'text/javascript', '.css': 'text/css', '.woff': 'font/woff', '.woff2': 'font/woff2',
  '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.jpg': 'image/jpeg' };

function createFixtureServer() {
  let origin;
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://fixture.local');
    res.setHeader('Cache-Control', 'private, no-store');
    if (req.method !== 'GET') {
      res.writeHead(405).end('This synthetic fixture accepts reads only.');
      return;
    }
    if (url.pathname.startsWith('/assets/') || /^\/favicon/.test(url.pathname)) {
      const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
      if (!file.startsWith(publicRoot + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
        res.writeHead(404).end('Asset unavailable');
        return;
      }
      res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream' });
      fs.createReadStream(file).pipe(res);
      return;
    }
    if (url.pathname === '/api/notifications') {
      res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ notifications: [], unread_count: 0 }));
      return;
    }
    const result = spawnSync('php', [renderer], {
      cwd: root, input: JSON.stringify({ origin, path: url.pathname + url.search }),
      encoding: 'utf8', timeout: 15000,
    });
    if (result.status !== 0) {
      console.error(result.stderr || result.stdout);
      res.writeHead(500, { 'Content-Type': 'text/plain' }).end('Synthetic presentation failed to render.');
      return;
    }
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', Vary: 'X-Requested-With' }).end(result.stdout);
  });
  return {
    server,
    async start(port = 0) {
      server.listen(port, '127.0.0.1');
      await once(server, 'listening');
      origin = `http://127.0.0.1:${server.address().port}`;
      return origin;
    },
    async close() {
      if (server.listening) {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
      }
    },
  };
}

module.exports = { createFixtureServer };
if (require.main === module) {
  const fixture = createFixtureServer();
  fixture.start(Number(process.argv[2] || 0)).then(origin => console.log(`Synthetic Guidance UI: ${origin}/guidance/clients`));
  for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => fixture.close().then(() => process.exit(0)));
  }
}
