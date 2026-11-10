package com.humus.synapse

import android.content.res.Configuration
import android.hardware.display.DisplayManager
import android.graphics.Color
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.Window
import com.facebook.react.ReactActivity
import com.facebook.react.ReactActivityDelegate
import com.facebook.react.ReactInstanceEventListener
import com.facebook.react.bridge.ReactContext
import com.facebook.react.defaults.DefaultNewArchitectureEntryPoint.fabricEnabled
import com.facebook.react.defaults.DefaultReactActivityDelegate
import com.facebook.react.interfaces.ExtraWindowEventListener

class MainActivity : ReactActivity() {

  private val displayManager by lazy { getSystemService(DisplayManager::class.java) }
  private val refreshPolicies = mutableMapOf<Window, WindowRefreshRatePolicy>()
  private var refreshPolicyActive = false
  private var refreshPolicyDestroyed = false
  private data class NavigationBarAppearance(val background: Int, val darkIcons: Boolean)
  private var navigationBarAppearance: NavigationBarAppearance? = null
  private var modalNavigationBarAppearance: NavigationBarAppearance? = null
  private val configurationRefresh = Runnable {
    if (!refreshPolicyDestroyed) updateRefreshRatePreferences()
  }
  private var observedReactContext: ReactContext? = null
  private val beforeReactDestroyListener: () -> Unit = { releaseExtraWindows() }
  private val displayListener = object : DisplayManager.DisplayListener {
    override fun onDisplayAdded(displayId: Int) = updateRefreshRatePreferences()

    override fun onDisplayChanged(displayId: Int) = updateRefreshRatePreferences()

    override fun onDisplayRemoved(displayId: Int) {
      refreshPolicies.values.forEach { it.onDisplayRemoved(displayId) }
    }
  }
  private val extraWindowListener = object : ExtraWindowEventListener {
    override fun onExtraWindowCreate(window: Window) {
      if (observedReactContext?.currentActivity !== this@MainActivity) return
      val policy = refreshPolicies.getOrPut(window) { WindowRefreshRatePolicy(window) }
      updateNavigationBarAppearance()
      if (refreshPolicyActive) policy.update()
    }

    override fun onExtraWindowDestroy(window: Window) {
      refreshPolicies.remove(window)?.release()
    }
  }
  private val reactInstanceListener = object : ReactInstanceEventListener {
    override fun onReactContextInitialized(context: ReactContext) {
      observeExtraWindows(context)
    }
  }

  override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    refreshPolicies[window] = WindowRefreshRatePolicy(window)
    updateNavigationBarAppearance()
    val host = (application as MainApplication).reactHost
    host.addReactInstanceEventListener(reactInstanceListener)
    host.addBeforeDestroyListener(beforeReactDestroyListener)
    host.currentReactContext?.let { observeExtraWindows(it) }
  }

  private fun observeExtraWindows(context: ReactContext) {
    if (refreshPolicyDestroyed || observedReactContext === context) return
    releaseExtraWindows()
    observedReactContext = context
    // RN 0.88 emits these supported events for its separate Modal Dialog windows.
    context.addExtraWindowEventListener(extraWindowListener)
  }

  private fun releaseExtraWindows() {
    observedReactContext?.removeExtraWindowEventListener(extraWindowListener)
    observedReactContext = null
    refreshPolicies.keys.filter { it !== window }.forEach { extraWindow ->
      refreshPolicies.remove(extraWindow)?.release()
    }
  }

  override fun onResume() {
    super.onResume()
    refreshPolicyActive = true
    displayManager.registerDisplayListener(displayListener, Handler(Looper.getMainLooper()))
    updateRefreshRatePreferences()
  }

  override fun onPause() {
    stopRefreshRatePreferences()
    super.onPause()
  }

  override fun onDestroy() {
    refreshPolicyDestroyed = true
    stopRefreshRatePreferences()
    releaseExtraWindows()
    val host = (application as MainApplication).reactHost
    host.removeReactInstanceEventListener(reactInstanceListener)
    host.removeBeforeDestroyListener(beforeReactDestroyListener)
    refreshPolicies.clear()
    super.onDestroy()
  }

  override fun onAttachedToWindow() {
    super.onAttachedToWindow()
    updateRefreshRatePreferences()
  }

  override fun onDetachedFromWindow() {
    window.decorView.removeCallbacks(configurationRefresh)
    refreshPolicies[window]?.release()
    super.onDetachedFromWindow()
  }

  override fun onConfigurationChanged(newConfig: Configuration) {
    super.onConfigurationChanged(newConfig)
    updateRefreshRatePreferences()
    // ViewRoot updates its display after the Activity callback. Recheck once on
    // the UI queue so an attached window cannot retain the old display's hint.
    window.decorView.removeCallbacks(configurationRefresh)
    if (refreshPolicyActive) window.decorView.post(configurationRefresh)
  }

  private fun stopRefreshRatePreferences() {
    refreshPolicyActive = false
    window.decorView.removeCallbacks(configurationRefresh)
    displayManager.unregisterDisplayListener(displayListener)
    refreshPolicies.values.forEach { it.release() }
  }

  private fun updateRefreshRatePreferences() {
    updateNavigationBarAppearance()
    if (refreshPolicyActive) refreshPolicies.values.forEach { it.update() }
  }

  fun setNavigationBarAppearance(background: Int, darkIcons: Boolean, modalBackground: Int, modalDarkIcons: Boolean) {
    navigationBarAppearance = NavigationBarAppearance(background, darkIcons)
    modalNavigationBarAppearance = NavigationBarAppearance(modalBackground, modalDarkIcons)
    updateNavigationBarAppearance()
  }

  private fun updateNavigationBarAppearance() {
    if (refreshPolicyDestroyed) return
    val dark = resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK == Configuration.UI_MODE_NIGHT_YES
    val fallback = NavigationBarAppearance(if (dark) Color.rgb(18, 18, 18) else Color.rgb(246, 247, 251), !dark)
    val activityAppearance = navigationBarAppearance ?: fallback
    refreshPolicies.keys.forEach { target ->
      val appearance = if (target === window) activityAppearance else modalNavigationBarAppearance ?: activityAppearance
      SystemNavigationBarPolicy.apply(target, appearance.background, appearance.darkIcons)
    }
  }

  /**
   * Returns the name of the main component registered from JavaScript. This is used to schedule
   * rendering of the component.
   */
  override fun getMainComponentName(): String = "SynapseMobile"

  /**
   * Returns the instance of the [ReactActivityDelegate]. We use [DefaultReactActivityDelegate]
   * which allows you to enable New Architecture with a single boolean flags [fabricEnabled]
   */
  override fun createReactActivityDelegate(): ReactActivityDelegate =
      DefaultReactActivityDelegate(this, mainComponentName, fabricEnabled)
}
