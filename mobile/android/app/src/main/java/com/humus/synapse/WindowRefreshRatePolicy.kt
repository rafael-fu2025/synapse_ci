package com.humus.synapse

import android.os.Build
import android.view.View
import android.view.ViewGroup
import android.view.Window

/** A display preference, not a rendering-rate guarantee or a display-mode override. */
internal class WindowRefreshRatePolicy(private val window: Window) {
  private var displayId: Int? = null
  private var previousWindowRefreshRate: Float? = null
  private var requestedWindowRefreshRate: Float? = null
  private var adaptiveRoot: ViewGroup? = null
  private var previousRootFrameRate: Float? = null
  private var observedRoot: View? = null
  private val attachListener = object : View.OnAttachStateChangeListener {
    override fun onViewAttachedToWindow(view: View) = update()

    override fun onViewDetachedFromWindow(view: View) = clearPreference()
  }

  fun update() {
    val root = window.decorView
    if (observedRoot !== root) {
      release()
      // Dialog.show() can notify RN before DecorView is attached. Wait for the
      // real display instead of assuming the default display or missing its hint.
      root.addOnAttachStateChangeListener(attachListener)
      observedRoot = root
    }
    val display = root.display
    if (display == null || !display.isValid) {
      clearPreference()
      return
    }
    if (display.displayId != displayId) {
      clearPreference()
      displayId = display.displayId
    }

    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.BAKLAVA) {
      // RN's ReactSurfaceView is a ViewGroup, not an android.view.SurfaceView.
      // A hint on DecorView alone does not propagate. HIGH + false includes new
      // children but preserves their explicit requests; votes last only while
      // invalidated so Android can downshift when idle. Leave the Android 15/16
      // touch-boost and power-saving defaults (and user settings) untouched.
      val group = root as? ViewGroup ?: return
      if (adaptiveRoot !== group) {
        previousRootFrameRate = group.requestedFrameRate
        group.propagateRequestedFrameRate(View.REQUESTED_FRAME_RATE_CATEGORY_HIGH, false)
        adaptiveRoot = group
      }
      return
    }

    // API 24-35: request a supported rate at the current resolution, never a mode
    // ID. Before API 34, defer on multi-resolution displays because a legacy rate
    // hint can select a default-resolution mode. Android 15 has ARR but no group
    // propagation. Android still honors battery/thermal/user caps.
    val attributes = window.attributes
    if (attributes.preferredDisplayModeId != 0) {
      release()
      return
    }
    val currentMode = display.mode
    val modes = display.supportedModes.map { mode ->
      SupportedRefreshRatePolicy.Mode(mode.physicalWidth, mode.physicalHeight, mode.refreshRate)
    }
    val rate = SupportedRefreshRatePolicy.preferredRate(
        currentMode.physicalWidth, currentMode.physicalHeight, modes,
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE)
    if (rate == 0f) {
      release()
      return
    }
    if (previousWindowRefreshRate == null) {
      previousWindowRefreshRate = attributes.preferredRefreshRate
    }
    requestedWindowRefreshRate = rate
    if (attributes.preferredRefreshRate != rate) {
      attributes.preferredRefreshRate = rate
      window.attributes = attributes
    }
  }

  fun onDisplayRemoved(removedDisplayId: Int) {
    if (removedDisplayId == displayId) release()
  }

  fun release() {
    observedRoot?.removeOnAttachStateChangeListener(attachListener)
    observedRoot = null
    clearPreference()
  }

  private fun clearPreference() {
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.BAKLAVA) {
      adaptiveRoot?.let { root ->
        if (root.requestedFrameRate == View.REQUESTED_FRAME_RATE_CATEGORY_HIGH) {
          root.propagateRequestedFrameRate(View.REQUESTED_FRAME_RATE_CATEGORY_DEFAULT, false)
          previousRootFrameRate?.let { root.requestedFrameRate = it }
        }
      }
    }
    adaptiveRoot = null
    previousRootFrameRate = null
    previousWindowRefreshRate?.let { previous ->
      val attributes = window.attributes
      if (attributes.preferredRefreshRate == requestedWindowRefreshRate) {
        attributes.preferredRefreshRate = previous
        window.attributes = attributes
      }
    }
    previousWindowRefreshRate = null
    requestedWindowRefreshRate = null
    displayId = null
  }
}
