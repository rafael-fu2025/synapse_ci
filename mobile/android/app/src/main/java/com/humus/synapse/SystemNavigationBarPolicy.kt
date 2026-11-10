package com.humus.synapse

import android.graphics.Color
import android.os.Build
import android.view.Window
import androidx.core.view.WindowInsetsControllerCompat

/** Draw the existing app surface behind system controls without hiding those controls. */
internal object SystemNavigationBarPolicy {
  @Suppress("DEPRECATION")
  fun apply(window: Window, background: Int, darkIcons: Boolean) {
    // Android 7 cannot draw dark navigation buttons, so preserve a readable fallback.
    window.navigationBarColor = when {
      Build.VERSION.SDK_INT >= Build.VERSION_CODES.O -> Color.TRANSPARENT
      darkIcons -> Color.BLACK
      else -> background
    }
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
      window.navigationBarDividerColor = Color.TRANSPARENT
    }
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
      window.isNavigationBarContrastEnforced = false
    }
    WindowInsetsControllerCompat(window, window.decorView).isAppearanceLightNavigationBars =
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && darkIcons
  }
}
