// Direct printing to a thermal printer from the browser, no print dialog:
//  - 'bluetooth': Bluetooth (BLE) printers through Web Bluetooth. Chrome / Edge on
//    Android, Windows, Mac and ChromeOS. Not on iPhone / iPad (no Web Bluetooth there).
//  - 'serial': USB printers and Bluetooth printers paired in Windows (they show up as
//    a COM port) through Web Serial. Chrome / Edge on a computer.
// The printer picked once is remembered on this device and reconnected on its own when
// the dashboard opens, so the POS just prints. Bytes come from escpos.js.
// window.printDirect(builder) is what the POS calls for every direct mode (network too).
(function (global) {
  var SPP = '00001101-0000-1000-8000-00805f9b34fb';
  // services thermal printers use for their "write bytes here" channel
  var BLE_SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000ffe0-0000-1000-8000-00805f9b34fb',
    '0000ae30-0000-1000-8000-00805f9b34fb',
    '0000fee7-0000-1000-8000-00805f9b34fb',
    '0000ae00-0000-1000-8000-00805f9b34fb',
    '6e400001-b5a3-f393-e0a9-e50e24dcca9e',
    '0000fff0-0000-1000-8000-00805f9b34fb',
    '0000ffe5-0000-1000-8000-00805f9b34fb',
    '0000ffb0-0000-1000-8000-00805f9b34fb',
    '0000ff10-0000-1000-8000-00805f9b34fb',
    '0000ff80-0000-1000-8000-00805f9b34fb',
    '0000fee9-0000-1000-8000-00805f9b34fb',
    'bef8d6c9-9c21-4c9e-b632-bd58c1009f9f'
  ];
  var DEVICE_KEY = 'printerDevice';

  var supported = {
    bluetooth: !!(global.navigator && navigator.bluetooth && global.isSecureContext),
    serial: !!(global.navigator && navigator.serial && global.isSecureContext)
  };

  var state = 'idle';          // idle | connecting | connected | disconnected
  var kind = null;             // which transport is open: 'bluetooth' | 'serial'
  var device = null, channel = null, chunk = 180;
  var port = null;
  var listeners = [];
  var queue = Promise.resolve();
  var connecting = null;
  var lastError = '';

  // every step goes to the console as [printer] ..., so a printer that won't print can be traced
  function log() { console.info.apply(console, ['[printer]'].concat([].slice.call(arguments))); }

  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function withTimeout(p, ms, msg) {
    return Promise.race([p, new Promise(function (_, rej) { setTimeout(function () { rej(new Error(msg || 'Timed out')); }, ms); })]);
  }
  function say(msg, type) {
    var fn = typeof global.showMessage === 'function' ? global.showMessage : global.showNotification;
    if (typeof fn === 'function') fn(msg, type || 'info'); else console.log('[printer]', msg);
  }
  function settings() { return global.getPrinterSettings ? global.getPrinterSettings() : { mode: 'browser' }; }
  function saved() { try { return JSON.parse(localStorage.getItem(DEVICE_KEY) || 'null'); } catch (e) { return null; } }
  function remember(info) { if (info) localStorage.setItem(DEVICE_KEY, JSON.stringify(info)); else localStorage.removeItem(DEVICE_KEY); }
  function setState(s) { state = s; listeners.forEach(function (fn) { try { fn(api.info()); } catch (e) {} }); }

  /* ── Bluetooth (BLE) ── */
  async function bleOpen(dev) {
    log('connecting to', dev.name || dev.id);
    var server = await withTimeout(dev.gatt.connect(), 8000, 'The printer did not answer. Is it on, close by, and not connected to another phone?');
    var services = [];
    try { services = await server.getPrimaryServices(); } catch (e) { log('service list failed', e && e.message); }
    log('services:', services.map(function (x) { return x.uuid; }).join(', ') || 'none');
    // known printer services first, then anything else that takes writes
    services.sort(function (a, b) {
      var ia = BLE_SERVICES.indexOf(a.uuid), ib = BLE_SERVICES.indexOf(b.uuid);
      return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
    });
    for (var i = 0; i < services.length; i++) {
      var chars = [];
      try { chars = await services[i].getCharacteristics(); } catch (e) { continue; }
      var c = chars.find(function (ch) { return ch.properties.write; }) || chars.find(function (ch) { return ch.properties.writeWithoutResponse; });
      log('  ' + services[i].uuid + ':', chars.map(function (ch) { return String(ch.uuid || '?').slice(4, 8) + (ch.properties.write ? ' w' : '') + (ch.properties.writeWithoutResponse ? ' wnr' : ''); }).join(', '));
      if (c) { log('printing through', services[i].uuid, '/', c.uuid, c.properties.write ? '(write)' : '(write without response)'); return c; }
    }
    try { dev.gatt.disconnect(); } catch (e) {}
    throw new Error('Connected to ' + (dev.name || 'the device') + ', but it has no print channel this website knows. Is it the receipt printer?');
  }

  function bleAttach(dev, ch) {
    device = dev; channel = ch; kind = 'bluetooth'; chunk = 180;
    if (!dev._rgWatched) {
      dev._rgWatched = true;
      dev.addEventListener('gattserverdisconnected', function () {
        if (device !== dev) return;
        log('printer disconnected');
        channel = null; setState('disconnected');
        // printer switched off or out of range for a moment: try to get it back quietly
        setTimeout(function () { if (device === dev && state === 'disconnected') api.reconnect(); }, 2000);
      });
    }
    remember({ kind: 'bluetooth', id: dev.id, name: dev.name || 'Bluetooth printer' });
    setState('connected');
  }

  async function blePick() {
    var dev = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: BLE_SERVICES });
    setState('connecting');
    bleAttach(dev, await bleOpen(dev));
  }

  async function bleReconnect(info) {
    var dev = device;
    if (!dev && navigator.bluetooth.getDevices) {
      var list = await navigator.bluetooth.getDevices();
      dev = list.find(function (d) { return d.id === info.id; }) || list.find(function (d) { return d.name && d.name === info.name; });
    }
    if (!dev) return false;  // this browser can't reopen it without a tap
    if (!dev.gatt.connected && dev.watchAdvertisements) {
      // wait until the printer is in range before connecting
      try {
        await withTimeout(new Promise(function (res, rej) {
          dev.addEventListener('advertisementreceived', function h() { dev.removeEventListener('advertisementreceived', h); res(); });
          dev.watchAdvertisements().catch(rej);
        }), 3000);
      } catch (e) {}
    }
    bleAttach(dev, await bleOpen(dev));
    return true;
  }

  async function bleWrite(bytes) {
    log('sending', bytes.length, 'bytes over Bluetooth');
    var withResp = channel.properties.write;
    var size = withResp ? chunk : 20;
    for (var i = 0; i < bytes.length;) {
      var part = bytes.slice(i, i + size);
      try {
        if (withResp) await (channel.writeValueWithResponse ? channel.writeValueWithResponse(part) : channel.writeValue(part));
        else { await channel.writeValueWithoutResponse(part); await sleep(8); }
        i += part.length;
      } catch (e) {
        // some printers only take 20 bytes per write
        if (size > 20) { size = chunk = 20; continue; }
        throw e;
      }
    }
  }

  /* ── USB / COM port ── */
  function baud() { return parseInt(settings().serialBaud, 10) || 9600; }

  async function serialOpen(p) {
    // speed changed in Printer settings: reopen at the new one
    if (p.writable && p._rgBaud !== baud()) { try { await p.close(); } catch (e) {} }
    if (!p.readable && !p.writable) { await p.open({ baudRate: baud() }); p._rgBaud = baud(); }
    port = p; kind = 'serial';
    var info = p.getInfo ? p.getInfo() : {};
    var bt = !!info.bluetoothServiceClassId;
    remember({ kind: 'serial', vendor: info.usbVendorId || null, product: info.usbProductId || null, name: bt ? 'Bluetooth printer (COM)' : info.usbVendorId ? 'USB printer' : 'Printer on COM port' });
    setState('connected');
  }

  async function serialPick() {
    var p = await navigator.serial.requestPort({ allowedBluetoothServiceClassIds: [SPP] });
    setState('connecting');
    await serialOpen(p);
  }

  async function serialReconnect(info) {
    if (port) { await serialOpen(port); return true; }
    var ports = await navigator.serial.getPorts();
    var p = ports.find(function (x) { var i = x.getInfo(); return info.vendor && i.usbVendorId === info.vendor && i.usbProductId === info.product; }) ||
      (ports.length === 1 ? ports[0] : null);
    if (!p) return false;
    await serialOpen(p);
    return true;
  }

  async function serialWrite(bytes) {
    log('sending', bytes.length, 'bytes over USB / COM');
    var w = port.writable.getWriter();
    try { await w.write(bytes); } finally { w.releaseLock(); }
  }

  if (supported.serial) {
    navigator.serial.addEventListener('disconnect', function (e) {
      if (e.target === port) { port = null; setState('disconnected'); }
    });
    navigator.serial.addEventListener('connect', function () {
      if (state !== 'connected' && settings().mode === 'serial') api.reconnect();
    });
  }

  /* ── public ── */
  var api = {
    supported: supported,
    info: function () {
      var s = saved();
      return { state: state, kind: kind, name: s ? s.name : null, savedKind: s ? s.kind : null, mode: settings().mode, lastError: lastError };
    },
    onChange: function (fn) { listeners.push(fn); },
    isConnected: function (want) {
      if (state !== 'connected' || (want && kind !== want)) return false;
      return kind === 'bluetooth' ? !!(channel && device && device.gatt.connected) : !!(port && port.writable && port._rgBaud === baud());
    },

    // Opens the browser's printer picker. Needs a click (browsers only show it then).
    pick: async function (want) {
      want = want || settings().mode;
      if (!supported[want]) { say(want === 'serial' ? 'Use Chrome or Edge on a computer to print over USB.' : 'This browser can\'t reach Bluetooth printers. Use Chrome or Edge (not on iPhone / iPad).', 'error'); return false; }
      await api.disconnect(true);
      try {
        if (want === 'bluetooth') await blePick(); else await serialPick();
        lastError = '';
        say('Printer connected: ' + api.info().name, 'success');
        return true;
      } catch (e) {
        log('connect failed:', e && e.name, e && e.message);
        setState(saved() ? 'disconnected' : 'idle');
        // picker closed, or opened too long after the tap (the caller then asks with a button)
        if (e && (e.name === 'NotFoundError' || e.name === 'AbortError' || e.name === 'SecurityError')) return false;
        lastError = e && e.message ? e.message : 'Could not connect to the printer';
        setState(state);
        await showProblem('Printer did not connect', lastError, want);
        return false;
      }
    },

    // Quietly reopens the printer picked before. No picker, no click needed.
    reconnect: function () {
      if (connecting) return connecting;
      var info = saved(), mode = settings().mode;
      if (!info || info.kind !== mode || !supported[mode]) return Promise.resolve(false);
      if (api.isConnected(mode)) return Promise.resolve(true);
      setState('connecting');
      connecting = (mode === 'bluetooth' ? bleReconnect(info) : serialReconnect(info))
        .catch(function (e) { console.warn('Printer reconnect failed', e); return false; })
        .then(function (ok) { connecting = null; if (!ok) setState('disconnected'); return ok; });
      return connecting;
    },

    disconnect: async function (keep) {
      var d = device, p = port;
      device = channel = port = null; kind = null;
      if (d) { try { d.gatt.disconnect(); } catch (e) {} }
      if (p) { try { await p.close(); } catch (e) {} }
      if (!keep) setState(saved() ? 'disconnected' : 'idle');
    },

    forget: async function () {
      var d = device, p = port;
      await api.disconnect(true);
      if (d && d.forget) { try { await d.forget(); } catch (e) {} }
      if (p && p.forget) { try { await p.forget(); } catch (e) {} }
      remember(null);
      setState('idle');
    },

    // Sends bytes; jobs wait their turn so a KOT and a bill never mix on paper.
    write: function (bytes) {
      var job = queue.then(async function () {
        var mode = settings().mode;
        if (!api.isConnected(mode) && !(await quickReconnect())) throw new Error('Printer not connected');
        if (kind === 'bluetooth') await bleWrite(bytes); else await serialWrite(bytes);
      });
      queue = job.catch(function () {});
      return job;
    }
  };

  // A printer that's off shouldn't hold up the till: give a reconnect 3 seconds, then move on
  // (it carries on in the background and the POS pill turns green when it's back).
  function quickReconnect() {
    return Promise.race([api.reconnect(), sleep(3000).then(function () { return false; })]);
  }

  global.rgPrinter = api;

  global.isDirectPrintMode = function (mode) {
    mode = mode || settings().mode;
    return mode === 'network' || mode === 'bluetooth' || mode === 'serial';
  };

  // Prints an EscPosBuilder on the printer from Printer settings. Returns true when the print is
  // on its way (it's sent in the background, so the POS never waits for the paper), false when
  // the caller should show the normal print preview instead. If sending fails later, onFail runs
  // (callers pass "show the preview").
  global.printDirect = async function (builder, onFail) {
    var mode = settings().mode;
    if (mode === 'network') return global.printViaNetwork(builder);
    if (mode !== 'bluetooth' && mode !== 'serial') return false;
    var bytes = builder.toUint8Array();
    if (!api.isConnected(mode)) {
      var ok = await quickReconnect();
      // still a click going on (Test print): the printer list can open straight away
      if (!ok && navigator.userActivation && navigator.userActivation.isActive) ok = await api.pick(mode);
      // otherwise ask; the "Connect and print" tap opens the browser's printer list
      if (!ok) ok = (await askToConnect(mode)) && (await api.pick(mode));
      if (!ok) return false;
    }
    api.write(bytes).then(function () { log('sent', bytes.length, 'bytes'); }).catch(function (e) {
      log('send failed:', e && e.name, e && e.message);
      lastError = 'Printing failed: ' + (e && e.message ? e.message : 'printer did not respond');
      if (state === 'connected') setState('disconnected'); else setState(state);
      say('Printing failed: ' + (e && e.message ? e.message : 'printer did not respond') + '.' + (onFail ? ' Showing preview instead.' : ''), 'error');
      if (typeof onFail === 'function') onFail();
    });
    return true;
  };

  // A connect that went wrong gets a window that stays until it's read, with what to try next.
  async function showProblem(title, msg, mode) {
    var tips = mode === 'serial'
      ? ['Check the USB cable, or that the printer is paired in Windows Bluetooth settings.', 'If the receipt comes out as symbols, change Speed in Settings > Printer.']
      : ['Turn the printer off and on, and keep it close.', 'Disconnect it from any other phone or app (a printer takes one connection at a time).', 'If the list shows two names for it, try the other one.', 'Older printers that only use classic Bluetooth can\'t be reached by a website on Android. On a Windows PC, pair it in Windows and choose USB printer.'];
    if (global.Swal) {
      await global.Swal.fire({
        title: title, icon: 'error', confirmButtonText: 'OK', confirmButtonColor: '#2563eb',
        html: '<p style="margin:0 0 10px;">' + escHtml(msg) + '</p><ul style="text-align:left;margin:0;padding-left:18px;font-size:.88em;line-height:1.5;">' +
          tips.map(function (t) { return '<li>' + escHtml(t) + '</li>'; }).join('') + '</ul>'
      });
    } else say(msg, 'error');
  }
  function escHtml(t) { return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  async function askToConnect(mode) {
    if (!supported[mode]) { say('This browser can\'t reach the printer directly. Showing preview instead.', 'error'); return false; }
    var name = (saved() || {}).name;
    var text = name ? name + ' is not connected. Turn it on, then connect.' : 'No ' + (mode === 'bluetooth' ? 'Bluetooth' : 'USB') + ' printer is connected on this device yet.';
    if (global.Swal) {
      var r = await global.Swal.fire({
        title: 'Connect the printer?', text: text, icon: 'info',
        showCancelButton: true, confirmButtonText: 'Connect and print', cancelButtonText: 'Show preview',
        confirmButtonColor: '#2563eb', cancelButtonColor: '#6b7280', reverseButtons: true
      });
      return !!r.isConfirmed;
    }
    return global.confirm(text + '\n\nConnect and print?');
  }

  /* ── printer pill on the POS, beside "Point of Sale" ── */
  function paintPill() {
    var head = document.querySelector('#posPage .page-header');
    var info = api.info(), on = info.mode === 'bluetooth' || info.mode === 'serial';
    var p = document.getElementById('rgPrinterPill');
    if (!head || !on) { if (p) p.remove(); return; }
    if (!p) {
      p = document.createElement('button'); p.type = 'button'; p.id = 'rgPrinterPill';
      head.classList.add('po-head'); head.appendChild(p);
    }
    var cls = info.state === 'connected' ? 'on' : info.state === 'connecting' ? 'sync' : 'off';
    p.className = 'po-pill rg-printer-pill ' + cls;
    p.innerHTML = '<span class="material-symbols-rounded">' + (info.state === 'connecting' ? 'sync' : 'print') + '</span>' +
      (info.state === 'connected' ? 'Printer ready' : info.state === 'connecting' ? 'Connecting printer…' : info.name ? 'Printer off · tap to connect' : 'Connect printer');
    p.title = info.name ? info.name : 'Connect your receipt printer';
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest('#rgPrinterPill')) return;
    if (api.isConnected(settings().mode)) { say('Printing to ' + (api.info().name || 'your printer'), 'success'); return; }
    // the click lets the picker open if the quiet reconnect can't find it
    api.reconnect().then(function (ok) { if (!ok) api.pick(); });
  });

  api.onChange(paintPill);

  document.addEventListener('DOMContentLoaded', function () {
    paintPill();
    if (supported.bluetooth || supported.serial) api.reconnect();
    // the POS header may be drawn after load; repaint when pages switch
    document.addEventListener('click', function () { setTimeout(paintPill, 0); });
  });
})(window);
