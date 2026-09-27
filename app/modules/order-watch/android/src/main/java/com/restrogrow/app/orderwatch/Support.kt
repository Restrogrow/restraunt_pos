package com.restrogrow.app.orderwatch

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.media.RingtoneManager
import androidx.core.app.NotificationManagerCompat
import org.json.JSONObject

/**
 * A looping ringtone player for the app's own telephone ring
 * (res/raw/order_ring.mp3 — copied from assets/sounds/telephone-ring.mp3)
 * bound to the ALARM stream (USAGE_ALARM) so a new order rings through even
 * when the phone is on silent/vibrate — like an incoming call. Falls back to
 * the system alarm sound if the raw resource is ever missing.
 */
fun buildOrderRingPlayer(context: Context): MediaPlayer? {
    val player = MediaPlayer()
    try {
        player.setAudioAttributes(
            AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_ALARM)
                .setContentType(AudioAttributes.CONTENT_TYPE_MUSIC)
                .build()
        )
        val resId = context.resources.getIdentifier("order_ring", "raw", context.packageName)
        if (resId != 0) {
            val afd = context.resources.openRawResourceFd(resId)
            player.setDataSource(afd.fileDescriptor, afd.startOffset, afd.length)
            afd.close()
        } else {
            val uri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)
                ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE)
                ?: return null
            player.setDataSource(context, uri)
        }
        player.isLooping = true
        player.prepare()
        return player
    } catch (e: Exception) {
        try {
            player.release()
        } catch (_: Exception) {
        }
        return null
    }
}

/**
 * Set from JS on AppState changes. While the app is foregrounded the service
 * stays quiet (no notifications, no ring) — the in-app OrderAlertWatcher owns
 * alerting when someone is looking at the screen. When the app goes to the
 * background or is killed, the service takes over.
 */
object AppStateFlag {
    @Volatile
    var foregrounded: Boolean = false
}

/**
 * Process-wide handoff for notification taps. MainActivity drops the
 * "orderWatchOrderId" extra here (onCreate + onNewIntent); the JS side polls
 * consumePendingDeepLink() once a second and navigates to the order. The
 * value stays pending until consumed, so a cold start from a notification
 * (JS boots several seconds later, possibly after the login screen) still
 * lands on the right order.
 */
object DeepLinkBus {
    const val EXTRA_ORDER_ID = "orderWatchOrderId"
    const val EXTRA_ORDER_NUMBER = "orderWatchOrderNumber"

    @Volatile private var orderId: Int? = null
    @Volatile private var orderNumber: String? = null

    @Synchronized
    fun push(id: Int, number: String?) {
        orderId = id
        orderNumber = number
    }

    @Synchronized
    fun consume(): Pair<Int, String?>? {
        val id = orderId ?: return null
        val number = orderNumber
        orderId = null
        orderNumber = null
        return id to number
    }
}

/**
 * "This order has been dealt with" markers, shared between the JS app
 * (Accept/Reject), the notification's Mark-handled action, and the service's
 * ring/notif suppression. Timestamped entries expire after 12h so the map
 * never grows unbounded.
 */
object HandledStore {
    private const val PREFS = "order_watch_prefs"
    private const val KEY = "handled_ids"
    private const val TTL_MS = 12L * 60 * 60 * 1000

    fun mark(context: Context, orderId: Int) {
        if (orderId <= 0) return
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val map = read(prefs)
        map.put(orderId.toString(), System.currentTimeMillis().toString())
        prefs.edit().putString(KEY, JSONObject(map.toMap()).toString()).apply()
    }

    fun isHandled(context: Context, orderId: Int): Boolean {
        val ts = read(context.getSharedPreferences(PREFS, Context.MODE_PRIVATE))[orderId.toString()]
            ?: return false
        return System.currentTimeMillis() - ts.toLong() < TTL_MS
    }

    private fun read(prefs: android.content.SharedPreferences): MutableMap<String, String> {
        val out = mutableMapOf<String, String>()
        try {
            val obj = JSONObject(prefs.getString(KEY, "{}") ?: "{}")
            for (key in obj.keys()) out[key] = obj.optString(key)
        } catch (_: Exception) {}
        return out
    }
}

/**
 * The "Mark handled" action on an order notification. Cancels the
 * notification and records the order as handled so the service stops ringing
 * for it. Starting the service from here is best-effort — background-start
 * restrictions can reject it, in which case the service's next poll tick
 * reads the handled flag and stops on its own.
 */
class MarkHandledReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val id = intent.getIntExtra(EXTRA_ORDER_ID, -1)
        if (id <= 0) return
        HandledStore.mark(context, id)
        try {
            NotificationManagerCompat.from(context).cancel(id)
        } catch (_: SecurityException) {
        }
        try {
            context.startService(
                Intent(context, OrderWatchService::class.java).apply {
                    action = OrderWatchService.ACTION_MARK_HANDLED
                    putExtra(OrderWatchService.EXTRA_ORDER_ID, id)
                }
            )
        } catch (_: Exception) {
        }
    }

    companion object {
        const val EXTRA_ORDER_ID = "orderId"
    }
}
