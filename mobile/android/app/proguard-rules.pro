# Project specific ProGuard rules.
# These run on top of the default Android optimize file and on top of the
# consumer rules each native dependency ships with.

# React Native core, the New Architecture bindings and the Hermes runtime.
-keep class com.facebook.react.** { *; }
-keep class com.facebook.jni.** { *; }
-keep class com.facebook.hermes.** { *; }
-keep class com.facebook.soloader.** { *; }
-keep class com.facebook.proguard.annotations.DoNotStrip
-keep class com.facebook.proguard.annotations.KeepGettersAndSetters
-keepclassmembers class com.facebook.proguard.annotations.DoNotStrip { *; }
-keepclassmembers class com.facebook.proguard.annotations.KeepGettersAndSetters { *; }

# The application entry points stay readable in release stack traces.
-keep class com.humus.synapse.MainActivity { *; }
-keep class com.humus.synapse.MainApplication { *; }

# react-native-config resolves the app namespace BuildConfig through reflection.
-keep class com.humus.synapse.BuildConfig { public static <fields>; }
-keep class com.lugg.** { *; }

# Preserve exact class definitions for the optimized release APK packaging gate,
# while allowing method optimization rather than keeping the entire app package.
-keep,allowoptimization class com.humus.synapse.WindowRefreshRatePolicy { *; }
-keep,allowoptimization class com.humus.synapse.SupportedRefreshRatePolicy { *; }
-keep,allowoptimization class com.humus.synapse.SupportedRefreshRatePolicy$Mode { *; }
-keep,allowoptimization class com.humus.synapse.LanTestOkHttpClientFactory { *; }
-keep,allowoptimization class com.humus.synapse.LanApiRedirectInterceptor { *; }

# WorkManager and the notification machinery reach these by name.
-keep class androidx.work.** { *; }
-dontwarn com.facebook.react.**
