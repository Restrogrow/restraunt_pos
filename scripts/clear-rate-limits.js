// Clears the local file-based auth rate-limit/lockout state before each test
// run so repeated `npm test` runs don't lock themselves out (main/config/rate_limit.php
// allows 5 logins/60s and locks out after 10 failed/15min, tracked per-IP as
// JSON files - harmless to wipe in local dev).
const fs = require('fs');
const path = require('path');

const dir = path.join(__dirname, '..', 'main', 'tmp', 'rate_limits');

if (fs.existsSync(dir)) {
  for (const file of fs.readdirSync(dir)) {
    if (file.endsWith('.json')) {
      fs.unlinkSync(path.join(dir, file));
    }
  }
  console.log(`Cleared rate-limit state in ${dir}`);
} else {
  console.log('No rate-limit directory yet - nothing to clear.');
}
