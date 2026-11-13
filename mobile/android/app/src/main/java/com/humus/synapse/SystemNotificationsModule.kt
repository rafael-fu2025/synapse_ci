package com.humus.synapse

import android.Manifest
import android.annotation.SuppressLint
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.facebook.react.BaseReactPackage
import com.facebook.react.bridge.NativeModule
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import com.facebook.react.bridge.UiThreadUtil
import com.facebook.react.common.LifecycleState
import com.facebook.react.module.annotations.ReactModule
import com.facebook.react.module.model.ReactModuleInfo
import com.facebook.react.module.model.ReactModuleInfoProvider

/** Only JS foreground messages that passed the current owner's scope guard reach this bridge. */
@ReactModule(name = SystemNotificationsModule.NAME)
class SystemNotificationsModule(context: ReactApplicationContext) : ReactContextBaseJavaModule(context) {
  private val stateLock = Any()
  private val policy = SystemNotificationPolicy()
  private val manager = context.getSystemService(NotificationManager::class.java)
  private val notifications = NotificationManagerCompat.from(context)
  private val isLanTest = context.packageName == "com.humus.synapse.lantest" &&
      BuildConfig.BUILD_TYPE == "lanTestRelease"

  override fun getName(): String = NAME

  @ReactMethod
  fun areNotificationsEnabled(promise: Promise) {
    try {
      promise.resolve(canShowNotifications())
    } catch (_: RuntimeException) {
      promise.reject("notification_check_unavailable", "Notification availability could not be checked.")
    }
  }

  @ReactMethod
  fun post(ownerKey: String, messageId: String, title: String, body: String) {
    if (messageId.isBlank()) return
    synchronized(stateLock) {
      if (!policy.canPost(ownerKey)) return
    }
    UiThreadUtil.runOnUiThread {
      // Retirement is recorded synchronously, before either this task or a clear
      // task runs. Check again at notify time, under the same lock as clear().
      synchronized(stateLock) {
        if (!policy.canPost(ownerKey) || !isForeground()) return@synchronized
        var remembered = false
        try {
          if (!canShowNotifications() || !policy.rememberPost(ownerKey, messageId)) return@synchronized
          remembered = true
          val contentIntent = notificationIntent(ownerKey, messageId)
          if (contentIntent == null) {
            policy.forgetPost(ownerKey, messageId)
            return@synchronized
          }
          val notification = NotificationCompat.Builder(reactApplicationContext, MainApplication.NOTIFICATION_CHANNEL_ID)
              .setSmallIcon(R.drawable.ic_notification)
              // LAN text is redacted in JS and again at the native build boundary.
              .setContentTitle(if (isLanTest) LAN_TITLE else title)
              .setContentText(if (isLanTest) LAN_BODY else body)
              .setContentIntent(contentIntent)
              .setAutoCancel(true)
              .setOnlyAlertOnce(true)
              .setPriority(NotificationCompat.PRIORITY_HIGH)
              .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
              .build()
          postNotification(SystemNotificationPolicy.notificationTag(ownerKey, messageId), notification)
        } catch (_: RuntimeException) {
          // Revoking permission between the check and notify must not crash RN,
          // and a failed attempt must not poison this owner's dedup cache.
          if (remembered) policy.forgetPost(ownerKey, messageId)
        }
      }
    }
  }

  @ReactMethod
  fun clear(ownerKey: String) {
    synchronized(stateLock) {
      if (!policy.retire(ownerKey)) return
    }
    UiThreadUtil.runOnUiThread {
      synchronized(stateLock) {
        try {
          // Ask Android for active locally tagged notices instead of retaining
          // an unbounded tag list. Never cancel FCM or another owner's notices.
          val prefix = SystemNotificationPolicy.ownerTagPrefix(ownerKey)
          for (notification in manager.activeNotifications) {
            val tag = notification.tag ?: continue
            if (notification.id == NOTIFICATION_ID && tag.startsWith(prefix)) {
              manager.cancel(tag, NOTIFICATION_ID)
            }
          }
        } catch (_: RuntimeException) {
          // Optional system notices are never a prerequisite for retiring auth.
        }
      }
    }
  }

  override fun invalidate() {
    synchronized(stateLock) { policy.invalidate() }
    super.invalidate()
  }

  private fun isForeground(): Boolean =
      reactApplicationContext.hasActiveReactInstance() &&
          reactApplicationContext.lifecycleState == LifecycleState.RESUMED &&
          reactApplicationContext.currentActivity is MainActivity

  private fun canShowNotifications(): Boolean {
    if (!notifications.areNotificationsEnabled()) return false
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
        ContextCompat.checkSelfPermission(reactApplicationContext, Manifest.permission.POST_NOTIFICATIONS) !=
            PackageManager.PERMISSION_GRANTED) return false
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
      val channel = manager.getNotificationChannel(MainApplication.NOTIFICATION_CHANNEL_ID) ?: return false
      if (channel.importance == NotificationManager.IMPORTANCE_NONE) return false
    }
    return true
  }

  private fun notificationIntent(ownerKey: String, messageId: String): PendingIntent? {
    val context = reactApplicationContext
    val launchIntent = context.packageManager.getLaunchIntentForPackage(context.packageName) ?: return null
    launchIntent.setClass(context, MainActivity::class.java)
    // ACTION_MAIN is intentionally not ACTION_VIEW: data identifies the pending
    // intent only, and must not trigger RN Linking or custom raw URL navigation.
    launchIntent.action = Intent.ACTION_MAIN
    launchIntent.data = Uri.Builder()
        .scheme("synapse-notification")
        .authority(context.packageName)
        .appendPath(SystemNotificationPolicy.notificationTag(ownerKey, messageId))
        .build()
    launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
    // RNFirebase's receiver already stored this notification-bearing RemoteMessage
    // before onMessage reached JS. Its existing opened/initial handlers consume
    // the stored original payload, with the app's entitlement/scope checks.
    launchIntent.putExtra("google.message_id", messageId)
    return PendingIntent.getActivity(context, NOTIFICATION_ID, launchIntent,
        PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
  }

  @SuppressLint("MissingPermission") // Explicit API 33/app/channel checks precede every call.
  private fun postNotification(tag: String, notification: android.app.Notification) {
    notifications.notify(tag, NOTIFICATION_ID, notification)
  }

  companion object {
    const val NAME = "SystemNotifications"
    private const val NOTIFICATION_ID = 0
    private const val LAN_TITLE = "Synapse LAN Test"
    private const val LAN_BODY = "Open the app to check for updates"
  }
}

class SystemNotificationsPackage : BaseReactPackage() {
  override fun getModule(name: String, reactContext: ReactApplicationContext): NativeModule? =
      if (name == SystemNotificationsModule.NAME) SystemNotificationsModule(reactContext) else null

  override fun getReactModuleInfoProvider(): ReactModuleInfoProvider = ReactModuleInfoProvider {
    mapOf(SystemNotificationsModule.NAME to ReactModuleInfo(
        SystemNotificationsModule.NAME,
        SystemNotificationsModule::class.java.name,
        false, false, false, false))
  }
}
