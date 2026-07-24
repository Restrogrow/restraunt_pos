const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, 'assets', 'js', 'script.js');
let content = fs.readFileSync(filePath, 'utf8');

// Find the notifOrderTime section and fix the time formatting
// Old code:
//         e = document.getElementById('notifOrderTime');
//         if (e) {
//           try {
//             var dt = new Date(order.created_at);
//             if (!isNaN(dt.getTime())) e.textContent = 'Received ' + dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
//           } catch(ee) {}
//         }

const oldTimeCode = "        e = document.getElementById('notifOrderTime');\n        if (e) {\n          try {\n            var dt = new Date(order.created_at);\n            if (!isNaN(dt.getTime())) e.textContent = 'Received ' + dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });\n          } catch(ee) {}\n        }";

// Try with LF endings
let idx = content.indexOf(oldTimeCode);
let lineEnding = '\n';

if (idx === -1) {
  // Try CRLF
  const crlfVersion = oldTimeCode.replace(/\n/g, '\r\n');
  idx = content.indexOf(crlfVersion);
  lineEnding = '\r\n';
  if (idx !== -1) {
    console.log('Found with CRLF');
  }
}

if (idx === -1) {
  console.log('✗ Could not find the time code in showNewOrderPopup');
  process.exit(1);
}

const newTimeCode = "        e = document.getElementById('notifOrderTime');" + lineEnding +
  "        if (e) {" + lineEnding +
  "          try {" + lineEnding +
  "            var dt = new Date(order.created_at);" + lineEnding +
  "            if (!isNaN(dt.getTime())) {" + lineEnding +
  "              var tz = window.userTimezone || Intl.DateTimeFormat().resolvedOptions().timeZone;" + lineEnding +
  "              e.textContent = 'Received ' + dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: tz });" + lineEnding +
  "            }" + lineEnding +
  "          } catch(ee) {}" + lineEnding +
  "        }";

const oldWithEnding = idx !== -1 ? (idx < content.indexOf('\n', idx) ? oldTimeCode : oldTimeCode.replace(/\n/g, '\r\n')) : oldTimeCode;
content = content.replace(idx !== -1 ? content.substring(idx, idx + (content.indexOf(lineEnding + '        e = document.getElementById', idx + 10) !== -1 ? 
  content.indexOf(lineEnding + '        e = document.getElementById', idx + 10) - idx : 
  oldTimeCode.length
)) : oldTimeCode, newTimeCode);

// Simpler approach - just do a direct string replace
const oldSearch = oldTimeCode;
const newReplace = newTimeCode;

// Try with whatever line endings
let result = content;
const searchLF = oldSearch.replace(/\r\n/g, '\n');
const searchCRLF = oldSearch.replace(/\n/g, '\r\n');

if (result.includes(searchLF)) {
  result = result.replace(searchLF, newReplace.replace(/\r\n/g, '\n'));
  console.log('✓ Fixed with LF endings');
} else if (result.includes(searchCRLF)) {
  result = result.replace(searchCRLF, newReplace.replace(/\n/g, '\r\n'));
  console.log('✓ Fixed with CRLF endings');
} else {
  console.log('✗ Could not find the time code text');
  // Debug: show the surrounding context
  var ctx = content.indexOf('notifOrderTime');
  if (ctx !== -1) {
    console.log('Found notifOrderTime at char ' + ctx);
    console.log(content.substring(ctx - 20, ctx + 200));
  }
  process.exit(1);
}

fs.writeFileSync(filePath, result, 'utf8');

// Verify syntax
try {
  new Function(result);
  console.log('✓ No syntax errors');
} catch(e) {
  console.log('✗ Syntax error:', e.message);
}

if (result.includes('timeZone: tz')) {
  console.log('✓ timeZone: tz fix confirmed in file');
}
