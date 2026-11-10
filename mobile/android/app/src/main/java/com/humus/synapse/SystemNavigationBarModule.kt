package com.humus.synapse

import android.graphics.Color
import com.facebook.react.BaseReactPackage
import com.facebook.react.bridge.NativeModule
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import com.facebook.react.bridge.UiThreadUtil
import com.facebook.react.module.annotations.ReactModule
import com.facebook.react.module.model.ReactModuleInfo
import com.facebook.react.module.model.ReactModuleInfoProvider

@ReactModule(name = SystemNavigationBarModule.NAME)
class SystemNavigationBarModule(context: ReactApplicationContext) : ReactContextBaseJavaModule(context) {
  override fun getName(): String = NAME

  @ReactMethod
  fun setAppearance(background: String, darkIcons: Boolean, modalBackground: String, modalDarkIcons: Boolean) {
    val activityColor = Color.parseColor(background)
    val modalColor = Color.parseColor(modalBackground)
    UiThreadUtil.runOnUiThread {
      (reactApplicationContext.currentActivity as? MainActivity)?.setNavigationBarAppearance(
          activityColor, darkIcons, modalColor, modalDarkIcons)
    }
  }

  companion object {
    const val NAME = "SystemNavigationBar"
  }
}

class SystemNavigationBarPackage : BaseReactPackage() {
  override fun getModule(name: String, reactContext: ReactApplicationContext): NativeModule? =
      if (name == SystemNavigationBarModule.NAME) SystemNavigationBarModule(reactContext) else null

  override fun getReactModuleInfoProvider(): ReactModuleInfoProvider = ReactModuleInfoProvider {
    mapOf(SystemNavigationBarModule.NAME to ReactModuleInfo(
        SystemNavigationBarModule.NAME,
        SystemNavigationBarModule::class.java.name,
        false, false, false, false))
  }
}
