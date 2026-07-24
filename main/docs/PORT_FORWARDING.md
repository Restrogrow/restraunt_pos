# Port Forwarding Guide - Access on Phone

## Your Server Details:
- **Local IP**: `192.168.18.72`
- **Port**: `80` (XAMPP Apache)
- **Project Path**: `test/restraunt_pos`

---

## Method 1: Direct IP Access (Easiest - No Setup Needed)

### Steps:
1. Make sure your phone and computer are on the **same WiFi network**
2. Open browser on your phone
3. Go to:
   ```
   http://192.168.18.72/test/restraunt_pos/dashboard.php
   ```

### If it doesn't work:
- Check Windows Firewall (see Method 3 below)
- Make sure XAMPP Apache is running
- Verify both devices are on same WiFi

---

## Method 2: VS Code Port Forwarding

### Option A: Using VS Code Built-in Port Forwarding

1. **Open VS Code**
2. **Press** `Ctrl+Shift+P` (or `Cmd+Shift+P` on Mac)
3. **Type**: `Ports: Focus on Ports View`
4. **Click** the `+` button (or press Enter)
5. **Enter port**: `80`
6. **VS Code will show** a forwarded URL like: `http://localhost:PORT`
7. **On your phone**, use your computer's IP instead:
   ```
   http://192.168.18.72:PORT/test/restraunt_pos/dashboard.php
   ```

### Option B: Using Live Server Extension

1. **Install Live Server** extension in VS Code
2. **Right-click** on `dashboard.php`
3. **Select**: "Open with Live Server"
4. **Note the port** shown (usually 5500 or similar)
5. **On your phone**: `http://192.168.18.72:PORT/dashboard.php`

---

## Method 3: Fix Windows Firewall (If Method 1 doesn't work)

### Run PowerShell as Administrator:

```powershell
# Allow Apache through firewall
netsh advfirewall firewall add rule name="Apache HTTP Server" dir=in action=allow protocol=TCP localport=80

# Or allow all incoming on port 80
netsh advfirewall firewall add rule name="Port 80" dir=in action=allow protocol=TCP localport=80
```

### Or Manually:
1. Open **Windows Defender Firewall**
2. Click **"Allow an app or feature through Windows Firewall"**
3. Click **"Change settings"** (if needed)
4. Find **"Apache HTTP Server"** and check both **Private** and **Public**
5. If not found, click **"Allow another app"** and browse to `C:\xampp\apache\bin\httpd.exe`

---

## Method 4: Using ngrok (For External Access)

### If you want to access from anywhere (not just same WiFi):

1. **Download ngrok**: https://ngrok.com/download
2. **Extract** and open terminal in that folder
3. **Run**:
   ```bash
   ngrok http 80
   ```
4. **Copy the URL** shown (like: `https://abc123.ngrok.io`)
5. **On your phone** (anywhere, any network):
   ```
   https://abc123.ngrok.io/test/restraunt_pos/dashboard.php
   ```

---

## Quick Access URLs

### Dashboard (after login):
```
http://192.168.18.72/test/restraunt_pos/dashboard.php
```

### Login Page:
```
http://192.168.18.72/test/restraunt_pos/admin/login.php
```

### Customer Menu:
```
http://192.168.18.72/test/restraunt_pos/website/index.php
```

---

## Troubleshooting

### Can't access from phone:
1. ✅ Check XAMPP Apache is running (green in XAMPP Control Panel)
2. ✅ Check both devices on same WiFi network
3. ✅ Check Windows Firewall (see Method 3)
4. ✅ Try accessing from computer first: `http://192.168.18.72/test/restraunt_pos/dashboard.php`
5. ✅ Check IP hasn't changed: Run `ipconfig` and look for IPv4 Address

### IP Address Changed:
If your IP changes (after reconnecting WiFi), run:
```powershell
ipconfig
```
Look for **IPv4 Address** under your WiFi adapter and update the URL.

---

## Recommended: Use Method 1 (Direct IP)
It's the simplest and doesn't require any setup. Just make sure:
- Both devices on same WiFi
- Windows Firewall allows port 80
- XAMPP Apache is running

