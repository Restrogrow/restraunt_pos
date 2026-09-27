package com.restrogrow.app.orderwatch

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.media.MediaPlayer
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import android.util.JsonReader
import java.io.InputStreamReader
import java.net.HttpURLConnection
import java.net.URL
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean

private const val TAG = "OrderWatch"
private const val CHANNEL_ORDERS = "orders"
private const val CHANNEL_SERVICE = "orderwatch_service"
private const val NOTIF_ID_FOREGROUND = 3001
private const val PREFS = "order_watch_prefs"
private const val BASE_URL_KEY = "baseUrl"
private const val POLL_INTERVAL_MS = 10_000L

// must match RING_TIMEOUT_MS in app/utils/orderAlerts.js
private const val RING_TIMEOUT_MS = 10L * 60 * 1000

class OrderWatchService : Service() {

    private val executor = Executors.newSingleThreadExecutor()
    private val stopped = AtomicBoolean(false)
    @Volatile private var loopStarted = false
    @Volatile private var ringPlayer: MediaPlayer? = null
    @Volatile private var ringingOrderId: Int = -1
    @Volatile private var ringUntil: Long = 0

    companion object {
        const val ACTION_START = "com.restrogrow.app.orderwatch.START"
        const val ACTION_STOP = "com.restrogrow.app.orderwatch.STOP"
        const val ACTION_MARK_HANDLED = "com.restrogrow.app.orderwatch.MARK_HANDLED"
        const val ACTION_QUIET = "com.restrogrow.app.orderwatch.QUIET"
        const val EXTRA_ORDER_ID = "orderId"

        fun start(context: Context, baseUrl: String) {
            val intent = Intent(context, OrderWatchService::class.java).apply {
                action = ACTION_START
                putExtra(BASE_URL_KEY, baseUrl)
            }
            try {
                ContextCompat.startForegroundService(context, intent)
            } catch (e: Exception) {
                Log.w(TAG, "startForegroundService failed: ${e.message}")
            }
        }

        fun stop(context: Context) {
            try {
                context.startService(
                    Intent(context, OrderWatchService::class.java).apply { action = ACTION_STOP }
                )
            } catch (_: Exception) {
                // app is in a state where services can't be started — the
                // foregrounded flag already silences the service anyway
            }
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelfSafely()
            return START_NOT_STICKY
        }

        if (intent?.action == ACTION_MARK_HANDLED) {
            val id = intent.getIntExtra(EXTRA_ORDER_ID, -1)
            if (id > 0) stopRingFor(id)
            return START_STICKY
        }

        if (intent?.action == ACTION_QUIET) {
            // app just came to the foreground — the in-app watcher takes
            // over alerting, so whatever the service was ringing stops now
            stopRing()
            return START_STICKY
        }

        val baseUrl = intent?.getStringExtra(BASE_URL_KEY)
            ?: getSharedPreferences(PREFS, MODE_PRIVATE).getString(BASE_URL_KEY, null)
            ?: run {
                stopSelfSafely()
                return START_NOT_STICKY
            }
        getSharedPreferences(PREFS, MODE_PRIVATE).edit().putString(BASE_URL_KEY, baseUrl).apply()

        createChannels()
        val serviceNotification = buildServiceNotification()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(
                NOTIF_ID_FOREGROUND,
                serviceNotification,
                ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC
            )
        } else {
            startForeground(NOTIF_ID_FOREGROUND, serviceNotification)
        }

        // One poll loop per service instance — repeated ACTION_START
        // deliveries (login twice, app relaunch) must not stack loops.
        if (!loopStarted && !stopped.get()) {
            loopStarted = true
            executor.execute { pollLoop(baseUrl) }
        }
        return START_STICKY
    }

    override fun onDestroy() {
        stopped.set(true)
        stopRing()
        executor.shutdownNow()
        super.onDestroy()
    }

    private fun stopSelfSafely() {
        stopped.set(true)
        stopRing()
        stopSelf()
    }

    // ── Polling loop ────────────────────────────────────────────────────────

    private fun pollLoop(baseUrl: String) {
        var seenIds: HashSet<Int>? = null
        while (!stopped.get()) {
            try {
                val orders = fetchOrders(baseUrl)
                if (orders != null) {
                    if (seenIds == null) {
                        // first successful poll seeds the baseline so a service
                        // restart doesn't re-alert for orders already sitting
                        // in the list
                        seenIds = orders.map { it.id }.toHashSet()
                    } else {
                        val fresh = orders.filter { it.id !in seenIds }
                        for (o in fresh) {
                            seenIds.add(o.id)
                            if (AppStateFlag.foregrounded) continue // JS watcher owns alerting now
                            if (HandledStore.isHandled(this, o.id)) continue
                            postOrderNotification(o)
                            if (o.status == "Pending") startRing(o.id)
                        }
                    }
                }
                enforceRingCap()
            } catch (e: Exception) {
                Log.w(TAG, "poll tick failed: ${e.message}")
            }
            try {
                Thread.sleep(POLL_INTERVAL_MS)
            } catch (_: InterruptedException) {
                break
            }
        }
    }

    // A looping ALARM-stream player left running forever would need a manual
    // force-stop to silence, so the service re-asserts its own 10-minute cap.
    // Also silences the moment the app returns to the foreground — the user
    // looking at the app means the in-app watcher owns alerting now.
    private fun enforceRingCap() {
        val playing = try { ringPlayer?.isPlaying == true } catch (_: Exception) { false }
        if (playing && (AppStateFlag.foregrounded || System.currentTimeMillis() >= ringUntil)) {
            stopRing()
        }
    }

    // ── Notifications ───────────────────────────────────────────────────────

    private fun buildServiceNotification(): Notification {
        val launch = packageManager.getLaunchIntentForPackage(packageName)
        val pi = launch?.let {
            PendingIntent.getActivity(
                this, 0, it,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
        }
        return NotificationCompat.Builder(this, CHANNEL_SERVICE)
            .setContentTitle("Restrogrow order watch")
            .setContentText("Watching for new orders")
            .setOngoing(true)
            .setContentIntent(pi)
            .setSmallIcon(getAppIconResId())
            .setPriority(NotificationCompat.PRIORITY_MIN)
            .build()
    }

    private fun buildOrderNotification(o: OrderRow): Notification {
        val launch = packageManager.getLaunchIntentForPackage(packageName)
            ?: return buildServiceNotification()
        launch.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        launch.putExtra(DeepLinkBus.EXTRA_ORDER_ID, o.id)
        launch.putExtra(DeepLinkBus.EXTRA_ORDER_NUMBER, o.number)
        val contentPI = PendingIntent.getActivity(
            this, o.id, launch,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val handledPI = PendingIntent.getBroadcast(
            this, o.id,
            Intent(this, MarkHandledReceiver::class.java).apply {
                action = ACTION_MARK_HANDLED
                putExtra(EXTRA_ORDER_ID, o.id)
            },
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val text = "${o.customerName} · ${o.type} · ₹${o.total}"
        return NotificationCompat.Builder(this, CHANNEL_ORDERS)
            .setSmallIcon(getAppIconResId())
            .setContentTitle("New order ${o.number}")
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setCategory(NotificationCompat.CATEGORY_ALARM)
            .setAutoCancel(true)
            .setContentIntent(contentPI)
            .addAction(0, "Mark handled", handledPI)
            .build()
    }

    private fun postOrderNotification(o: OrderRow) {
        val notification = buildOrderNotification(o)
        try {
            NotificationManagerCompat.from(this).notify(o.id, notification)
        } catch (_: SecurityException) {
            // POST_NOTIFICATIONS revoked — the ALARM-stream ring still fires
        }
    }

    private fun getAppIconResId(): Int {
        val resId = applicationInfo.icon
        return if (resId != 0) resId else android.R.drawable.ic_dialog_info
    }

    private fun createChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val nm = NotificationManagerCompat.from(this)

        val orders = NotificationChannel(
            CHANNEL_ORDERS,
            "New orders",
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            // No channel sound — the service plays its own looping ALARM-stream
            // ringtone it can stop on demand. A channel sound would overlap it
            // (and can't be stopped once started).
            setSound(null, null)
            enableVibration(true)
            vibrationPattern = longArrayOf(0, 800, 400, 800)
        }
        nm.createNotificationChannel(orders)

        val service = NotificationChannel(
            CHANNEL_SERVICE,
            "Order watch status",
            NotificationManager.IMPORTANCE_MIN
        )
        nm.createNotificationChannel(service)
    }

    // ── Ringing (the app's telephone ring, ALARM stream, looping) ──────────

    private fun startRing(orderId: Int) {
        stopRing()
        ringingOrderId = orderId
        ringUntil = System.currentTimeMillis() + RING_TIMEOUT_MS
        val player = buildOrderRingPlayer(this) ?: return
        this.ringPlayer = player
        try {
            player.start()
        } catch (_: Exception) {
            // alarm volume might be zero — the notification still arrived
        }
    }

    private fun stopRingFor(orderId: Int) {
        if (ringingOrderId == orderId) {
            stopRing()
        }
    }

    private fun stopRing() {
        val player = ringPlayer
        ringPlayer = null
        ringingOrderId = -1
        try {
            player?.stop()
        } catch (_: Exception) {
        }
        try {
            player?.release()
        } catch (_: Exception) {
        }
    }

    // ── HTTP + session-cookie reuse ─────────────────────────────────────────

    private fun fetchOrders(baseUrl: String): List<OrderRow>? {
        var conn: HttpURLConnection? = null
        return try {
            val url = "$baseUrl/api/get_orders.php?limit=50&date=${todayIso()}"
            conn = URL(url).openConnection() as HttpURLConnection
            conn.requestMethod = "GET"
            conn.connectTimeout = 8000
            conn.readTimeout = 8000
            val cookie = android.webkit.CookieManager.getInstance().getCookie(url)
            if (!cookie.isNullOrBlank()) {
                conn.setRequestProperty("Cookie", cookie)
            }
            conn.connect()
            if (conn.responseCode != 200) {
                Log.w(TAG, "get_orders.php -> HTTP ${conn.responseCode}")
                return null
            }
            val body = conn.inputStream.bufferedReader().readText()
            parseOrders(body)
        } catch (e: Exception) {
            Log.w(TAG, "fetchOrders failed: ${e.message}")
            null
        } finally {
            conn?.disconnect()
        }
    }

    /**
     * Streaming parse of get_orders.php's
     * {"success":true,...,"orders":[{...}]} shape. Kept dependency-free with
     * android.util.JsonReader, which ships with every Android build. Tolerant
     * of nulls and of numeric fields arriving as strings (PDO stringify
     * settings differ between hosts) — one weird row must not kill the
     * whole parse, or the service would silently never alert.
     */
    private fun parseOrders(body: String): List<OrderRow> {
        val out = mutableListOf<OrderRow>()
        try {
            JsonReader(InputStreamReader(body.byteInputStream(), Charsets.UTF_8)).use { reader ->
                reader.beginObject()
                while (reader.hasNext()) {
                    when (reader.nextName()) {
                        "orders" -> {
                            reader.beginArray()
                            while (reader.hasNext()) {
                                var id = -1
                                var number = ""
                                var customerName = ""
                                var type = ""
                                var total = ""
                                var status = ""
                                reader.beginObject()
                                while (reader.hasNext()) {
                                    when (reader.nextName()) {
                                        "id" -> id = readIntLenient(reader)
                                        "order_number" -> number = readStringLenient(reader)
                                        "customer_name" -> customerName = readStringLenient(reader)
                                        "order_type" -> type = readStringLenient(reader)
                                        "total" -> total = readStringLenient(reader)
                                        "order_status" -> status = readStringLenient(reader)
                                        else -> reader.skipValue()
                                    }
                                }
                                reader.endObject()
                                if (id > 0) {
                                    out.add(OrderRow(id, number, customerName, type, total, status))
                                }
                            }
                            reader.endArray()
                        }
                        else -> reader.skipValue()
                    }
                }
                reader.endObject()
            }
        } catch (e: Exception) {
            Log.w(TAG, "parseOrders failed: ${e.message}")
        }
        return out
    }

    private fun readIntLenient(reader: JsonReader): Int = try {
        if (reader.peek() == android.util.JsonToken.NUMBER) reader.nextInt()
        else reader.nextString().trim().toIntOrNull() ?: -1
    } catch (_: Exception) {
        -1
    }

    private fun readStringLenient(reader: JsonReader): String = try {
        if (reader.peek() == android.util.JsonToken.NULL) {
            reader.nextNull()
            ""
        } else {
            // nextString() stringifies numbers too
            reader.nextString() ?: ""
        }
    } catch (_: Exception) {
        ""
    }

    data class OrderRow(
        val id: Int,
        val number: String,
        val customerName: String,
        val type: String,
        val total: String,
        val status: String
    )

    private fun todayIso(): String {
        val sdf = SimpleDateFormat("yyyy-MM-dd", Locale.US).apply {
            timeZone = TimeZone.getTimeZone("Asia/Kolkata")
        }
        return sdf.format(Date())
    }
}
