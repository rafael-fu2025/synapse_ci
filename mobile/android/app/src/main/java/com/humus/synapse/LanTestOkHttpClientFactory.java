package com.humus.synapse;

import com.facebook.react.modules.network.OkHttpClientFactory;
import com.facebook.react.modules.network.OkHttpClientProvider;
import okhttp3.OkHttpClient;

/** Installed by MainApplication only for the native lanTestRelease build type. */
public final class LanTestOkHttpClientFactory implements OkHttpClientFactory {
  @Override
  public OkHttpClient createNewNetworkModuleClient() {
    // Keep RN's cookie-jar container, timeouts, TLS and WebSocket defaults. RN's
    // no-context builder has no disk cache, so cached redirects cannot bypass
    // the network interceptor. Do not disable redirects globally (FCM/HTTPS).
    return OkHttpClientProvider.createClientBuilder()
        .cache(null)
        .addNetworkInterceptor(new LanApiRedirectInterceptor())
        .build();
  }
}
