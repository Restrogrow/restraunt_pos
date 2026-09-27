package com.restrogrow.app.orderwatch

import android.content.Context
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition

class OrderWatchModule : Module() {
    override fun definition() = ModuleDefinition {
        Name("OrderWatch")

        // Starts the foreground service polling this API base. Safe to call
        // repeatedly — the service guards against stacking poll loops.
        Function("start") { baseUrl: String ->
            val context = appContext.reactContext
            if (context != null) {
                OrderWatchService.start(context, baseUrl)
            }
        }

        Function("stop") {
            val context = appContext.reactContext
            if (context != null) {
                OrderWatchService.stop(context)
            }
        }

        // JS records that an order was accepted/rejected/completed so the
        // service stops ringing for it and suppresses its notification.
        Function("markHandled") { orderId: Int ->
            val context = appContext.reactContext
            if (context != null) {
                HandledStore.mark(context, orderId)
            }
        }

        Function("setAppForeground") { foreground: Boolean ->
            AppStateFlag.foregrounded = foreground
            if (foreground) {
                //silence any background ring the instant the app opens
                try {
                    val context = appContext.reactContext
                    context?.startService(
                        android.content.Intent(context, OrderWatchService::class.java).apply {
                            action = OrderWatchService.ACTION_QUIET
                        }
                    )
                } catch (_: Exception) {
                }
            }
        }

        // Pops a pending notification-tap deep link, if any (cold start from
        // a notification or a warm tap while the app is backgrounded).
        Function("consumePendingDeepLink") {
            val pending = DeepLinkBus.consume()
            if (pending == null) {
                mapOf<String, Any>("orderId" to -1)
            } else {
                mapOf<String, Any>(
                    "orderId" to pending.first,
                    "orderNumber" to (pending.second ?: "")
                )
            }
        }

        // Emitted when MainActivity hands over a notification-tap extra, so
        // JS can react immediately instead of waiting for its poll tick.
        Events("onDeepLink")
    }
}
