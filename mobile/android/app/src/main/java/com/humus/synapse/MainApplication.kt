package com.humus.synapse

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build
import com.facebook.react.PackageList
import com.facebook.react.ReactApplication
import com.facebook.react.ReactHost
import com.facebook.react.ReactNativeApplicationEntryPoint.loadReactNative
import com.facebook.react.defaults.DefaultReactHost.getDefaultReactHost
import com.facebook.react.modules.network.OkHttpClientProvider

class MainApplication : Application(), ReactApplication {

  override val reactHost: ReactHost by lazy {
    getDefaultReactHost(
      context = applicationContext,
      packageList =
        PackageList(this).packages.apply {
          // Packages that cannot be autolinked yet can be added manually here, for example:
          // add(MyReactNativePackage())
        },
    )
  }

  override fun onCreate() {
    super.onCreate()
    createNotificationChannel()
    // BUILD_TYPE is a native variant decision, never a dotenv transport opt-in.
    // Install before RN can create its HTTP/WebSocket clients.
    // Check the actual installed package as well as the native build type;
    // dotenv cannot spoof Application.packageName to activate this in debug/prod.
    if (packageName == "com.humus.synapse.lantest" && BuildConfig.BUILD_TYPE == "lanTestRelease") {
      OkHttpClientProvider.setOkHttpClientFactory(LanTestOkHttpClientFactory())
    }
    loadReactNative(this)
  }

  /**
   * The server addresses every push to the "synapse-notifications" channel; on
   * Android 8+ a notification whose channel the app never created is silently
   * dropped, so the channel must exist before the first notice arrives.
   */
  private fun createNotificationChannel() {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
      return
    }
    val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
    val channel = NotificationChannel(
      NOTIFICATION_CHANNEL_ID,
      "Portal notifications",
      NotificationManager.IMPORTANCE_HIGH,
    ).apply {
      description = "Appointment, announcement, and survey notices"
    }
    manager.createNotificationChannel(channel)
  }

  companion object {
    const val NOTIFICATION_CHANNEL_ID = "synapse-notifications"
  }
}
