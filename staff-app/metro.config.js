// Dev only: Metro also forwards /menuwebsite/* to the local XAMPP Apache, so a phone running
// Expo Go reaches the backend through the same address it loads the app from (LAN or
// `expo start --tunnel`). Only Metro's port has to be reachable; Apache stays local.
const http = require('http');
const { getDefaultConfig } = require('expo/metro-config');

const config = getDefaultConfig(__dirname);

config.server = config.server || {};
const previous = config.server.enhanceMiddleware;
config.server.enhanceMiddleware = (middleware, server) => {
  const base = previous ? previous(middleware, server) : middleware;
  return (req, res, next) => {
    if (!req.url.startsWith('/menuwebsite/')) return base(req, res, next);
    const upstream = http.request(
      {
        host: '127.0.0.1',
        port: 80,
        path: req.url,
        method: req.method,
        headers: { ...req.headers, host: 'localhost' },
      },
      (up) => {
        res.writeHead(up.statusCode || 502, up.headers);
        up.pipe(res);
      }
    );
    upstream.on('error', () => {
      res.writeHead(502, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: false, message: 'XAMPP Apache is not running on this PC.' }));
    });
    req.pipe(upstream);
  };
};

module.exports = config;
