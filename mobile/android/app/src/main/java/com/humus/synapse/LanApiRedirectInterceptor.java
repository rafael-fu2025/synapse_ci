package com.humus.synapse;

import java.io.IOException;
import java.net.ProtocolException;
import okhttp3.HttpUrl;
import okhttp3.Interceptor;
import okhttp3.Response;

/**
 * Reject LAN API redirects inside OkHttp's follow-up loop, before it can forward
 * Authorization, cookies or a replayable credential-bearing body to another hop.
 * An application interceptor (or RN fetch redirect option) runs too late.
 */
public final class LanApiRedirectInterceptor implements Interceptor {
  static boolean isLanApi(HttpUrl url) {
    return "http".equals(url.scheme()) && url.port() == 8080
        && ("172.16.153.237".equals(url.host()) || "192.168.1.11".equals(url.host()));
  }

  @Override
  public Response intercept(Chain chain) throws IOException {
    HttpUrl requestUrl = chain.request().url();
    Response response = chain.proceed(chain.request());
    // All 3xx, including same-origin and relative redirects, fail closed. The
    // fixed API origin has no legitimate redirect contract; never inspect/log
    // Location or credentials. ProtocolException is not retryable by OkHttp.
    if (isLanApi(requestUrl) && response.code() >= 300 && response.code() < 400) {
      response.close();
      throw new ProtocolException("LAN API redirects are not permitted");
    }
    return response;
  }
}
