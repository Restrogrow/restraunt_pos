# Access Website on Phone from VS Code

## Quick Method (No VS Code Port Forwarding Needed)

### 1. Make sure both devices are on the same WiFi network

### 2. Access from your phone's browser:
```
http://192.168.18.72/test/restraunt_pos/dashboard.php
```

Or for the login page:
```
http://192.168.18.72/test/restraunt_pos/admin/login.php
```

### 3. If it doesn't work, check Windows Firewall:
- Open Windows Defender Firewall
- Click "Allow an app or feature through Windows Firewall"
- Make sure "Apache HTTP Server" is checked for both Private and Public networks
- Or temporarily disable firewall to test

---

## VS Code Port Forwarding Method

### Option 1: Using VS Code's Built-in Port Forwarding

1. **Install "Live Server" extension** (if not already installed):
   - Open VS Code Extensions (Ctrl+Shift+X)
   - Search for "Live Server"
   - Install it

2. **Or use VS Code's built-in port forwarding**:
   - Press `Ctrl+Shift+P` (or `Cmd+Shift+P` on Mac)
   - Type "Ports: Focus on Ports View"
   - Click the "+" button to add a port
   - Enter port `80`
   - VS Code will create a forwarded port URL

### Option 2: Using ngrok (Recommended for External Access)

1. **Install ngrok**:
   - Download from: https://ngrok.com/download
   - Extract to a folder

2. **Run ngrok**:
   ```bash
   ngrok http 80
   ```

3. **Use the ngrok URL** on your phone:
   - ngrok will give you a URL like: `https://abc123.ngrok.io`
   - Access: `https://abc123.ngrok.io/test/restraunt_pos/dashboard.php`

---

## Troubleshooting

### If you can't access from phone:

1. **Check XAMPP is running**:
   - Open XAMPP Control Panel
   - Make sure Apache is running (green)

2. **Check Windows Firewall**:
   ```powershell
   # Run as Administrator
   netsh advfirewall firewall add rule name="Apache" dir=in action=allow protocol=TCP localport=80
   ```

3. **Check your local IP hasn't changed**:
   ```powershell
   ipconfig
   ```
   Look for IPv4 Address under your WiFi adapter

4. **Test from computer first**:
   - Try: `http://192.168.18.72/test/restraunt_pos/dashboard.php` on your computer
   - If it works on computer but not phone, it's a network/firewall issue

---

## Quick Access URLs

**Dashboard (after login):**
```
http://192.168.18.72/test/restraunt_pos/dashboard.php
```

**Login Page:**
```
http://192.168.18.72/test/restraunt_pos/admin/login.php
```

**Website (Customer Menu):**
```
http://192.168.18.72/test/restraunt_pos/website/index.php
```

---

## Note
Your local IP (192.168.18.72) may change if you reconnect to WiFi. If it stops working, run `ipconfig` again to get the new IP.

