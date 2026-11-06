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

# react-native-config resolves its values through generated BuildConfig fields.
-keep class com.lugg.** { *; }

# WorkManager and the notification machinery reach these by name.
-keep class androidx.work.** { *; }
-dontwarn com.facebook.react.**
