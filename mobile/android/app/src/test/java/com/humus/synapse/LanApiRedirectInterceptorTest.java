package com.humus.synapse;

import com.facebook.react.modules.network.CookieJarContainer;
import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetAddress;
import java.net.InetSocketAddress;
import java.net.ProtocolException;
import java.net.Proxy;
import java.net.Socket;
import java.net.SocketAddress;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.List;
import javax.net.SocketFactory;
import okhttp3.Call;
import okhttp3.Connection;
import okhttp3.HttpUrl;
import okhttp3.Interceptor;
import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Protocol;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;
import okhttp3.ResponseBody;
import okio.Buffer;
import okio.BufferedSource;

/** Real OkHttp follow-up pipeline with in-memory sockets: no network/device/backend. */
public final class LanApiRedirectInterceptorTest {
  private static int checks;

  private static void check(boolean condition, String message) {
    checks++;
    if (!condition) { throw new AssertionError(message); }
  }

  public static void main(String[] args) throws Exception {
    OkHttpClient client = new LanTestOkHttpClientFactory().createNewNetworkModuleClient();
    check(client.followRedirects() && client.followSslRedirects(), "Normal HTTPS redirects remain enabled");
    check(client.cookieJar() instanceof CookieJarContainer, "RN cookie container retained");
    check(client.connectTimeoutMillis() == 0 && client.readTimeoutMillis() == 0
        && client.writeTimeoutMillis() == 0, "RN timeout defaults retained");
    check(client.cache() == null, "No cached redirect bypass");
    check(client.networkInterceptors().size() == 1
        && client.networkInterceptors().get(0) instanceof LanApiRedirectInterceptor,
        "Redirect check is a network interceptor, not an after-follow-up application interceptor");

    for (String host : Arrays.asList("172.16.153.237", "192.168.1.11")) {
      for (int code = 300; code < 400; code++) {
        for (String location : Arrays.asList("/same-origin", "http://example.invalid/leak", "https://example.invalid/leak")) {
          MemorySockets sockets = new MemorySockets(reply(code, location), reply(200, null));
          Request request = credentialRequest("http://" + host + ":8080/api/v1/login");
          try (Response ignored = offlineClient(client, sockets).newCall(request).execute()) {
            throw new AssertionError("LAN API accepted redirect " + code);
          } catch (ProtocolException expected) {
            check("LAN API redirects are not permitted".equals(expected.getMessage()), "No URL/credential in exception");
            check(sockets.created.size() == 1, "No follow-up connection or retry on redirect");
            String sent = sockets.created.get(0).sent.toString(StandardCharsets.UTF_8);
            check(sent.contains("Authorization: Bearer native-unit-test")
                && sent.contains("Cookie: native-unit-test=1") && sent.contains("password=native-unit-test"),
                "Credential-bearing initial request exercised");
            check(!sent.contains("same-origin") && !sent.contains("/leak"), "No redirect request sent");
          }
        }
      }
    }

    MemorySockets success = new MemorySockets(reply(200, null));
    try (Response response = offlineClient(client, success)
        .newCall(credentialRequest("http://172.16.153.237:8080/api/v1/login")).execute()) {
      check(response.code() == 200 && success.created.size() == 1, "Successful LAN response untouched");
    }
    MemorySockets ordinary = new MemorySockets(reply(302, "/next"), reply(200, null));
    try (Response response = offlineClient(client, ordinary)
        .newCall(new Request.Builder().url("http://normal.invalid:8080/start").build()).execute()) {
      check(response.code() == 200 && ordinary.created.size() == 2, "Non-LAN normal redirect behavior retained");
    }

    for (String url : Arrays.asList("https://172.16.153.237:8080/api", "https://fcm.googleapis.com/fcm/send",
        "https://192.168.1.11:8081/socket", "http://172.16.153.237:8081/api", "http://192.168.1.11/api",
        "http://172.16.153.237.example.invalid:8080/api", "http://172.16.153.238:8080/api")) {
      check(!LanApiRedirectInterceptor.isLanApi(HttpUrl.get(url)), "Exact scheme/host/port only");
      TrackingBody body = new TrackingBody();
      Response response = new LanApiRedirectInterceptor().intercept(new FakeChain(url, 302, body));
      check(response.code() == 302 && !body.closed, "Other HTTP/HTTPS response not intercepted");
      response.close();
    }
    check(!LanApiRedirectInterceptor.isLanApi(new Request.Builder().url("wss://172.16.153.237:8080/socket")
        .build().url()), "WSS is not a LAN HTTP API");
    TrackingBody redirectBody = new TrackingBody();
    try {
      new LanApiRedirectInterceptor().intercept(new FakeChain("http://192.168.1.11:8080/api", 307, redirectBody));
      throw new AssertionError("Expected redirect rejection");
    } catch (ProtocolException expected) {
      check(redirectBody.closed, "Rejected response body is closed before throwing");
    }
    System.out.println(checks + " LAN native transport checks passed (in-memory; no network)");
  }

  private static Request credentialRequest(String url) {
    return new Request.Builder().url(url).header("Authorization", "Bearer native-unit-test")
        .header("Cookie", "native-unit-test=1")
        .post(RequestBody.create(MediaType.get("application/x-www-form-urlencoded"), "password=native-unit-test"))
        .build();
  }

  private static OkHttpClient offlineClient(OkHttpClient client, MemorySockets sockets) {
    return client.newBuilder().proxy(Proxy.NO_PROXY).socketFactory(sockets)
        .dns(host -> Collections.singletonList(InetAddress.getByAddress(new byte[] {127, 0, 0, 1})))
        .build();
  }

  private static String reply(int code, String location) {
    return "HTTP/1.1 " + code + " Unit Test\r\nContent-Length: 0\r\nConnection: close\r\n"
        + (location == null ? "" : "Location: " + location + "\r\n") + "\r\n";
  }

  private static final class MemorySockets extends SocketFactory {
    final List<MemorySocket> created = new ArrayList<>();
    final List<String> responses;
    MemorySockets(String... responses) { this.responses = Arrays.asList(responses); }
    @Override public Socket createSocket() {
      if (created.size() >= responses.size()) { throw new AssertionError("Unexpected follow-up socket"); }
      MemorySocket socket = new MemorySocket(responses.get(created.size()));
      created.add(socket);
      return socket;
    }
    @Override public Socket createSocket(String host, int port) { return createSocket(); }
    @Override public Socket createSocket(String host, int port, InetAddress local, int localPort) { return createSocket(); }
    @Override public Socket createSocket(InetAddress host, int port) { return createSocket(); }
    @Override public Socket createSocket(InetAddress host, int port, InetAddress local, int localPort) { return createSocket(); }
  }

  private static final class MemorySocket extends Socket {
    final ByteArrayOutputStream sent = new ByteArrayOutputStream();
    final InputStream input;
    boolean closed;
    MemorySocket(String response) { input = new ByteArrayInputStream(response.getBytes(StandardCharsets.UTF_8)); }
    @Override public void connect(SocketAddress endpoint, int timeout) { /* no real socket */ }
    @Override public void connect(SocketAddress endpoint) { /* no real socket */ }
    @Override public InputStream getInputStream() { return input; }
    @Override public OutputStream getOutputStream() { return sent; }
    @Override public void setSoTimeout(int timeout) { /* no real socket */ }
    @Override public void setTcpNoDelay(boolean value) { /* no real socket */ }
    @Override public boolean isClosed() { return closed; }
    @Override public boolean isConnected() { return true; }
    @Override public boolean isInputShutdown() { return false; }
    @Override public boolean isOutputShutdown() { return false; }
    @Override public InetAddress getInetAddress() { return InetAddress.getLoopbackAddress(); }
    @Override public int getPort() { return 8080; }
    @Override public SocketAddress getRemoteSocketAddress() { return new InetSocketAddress(getInetAddress(), 8080); }
    @Override public void close() throws IOException { closed = true; input.close(); }
  }

  private static final class TrackingBody extends ResponseBody {
    final BufferedSource source = new Buffer();
    boolean closed;
    @Override public MediaType contentType() { return null; }
    @Override public long contentLength() { return 0; }
    @Override public BufferedSource source() { return source; }
    @Override public void close() { closed = true; }
  }

  private static final class FakeChain implements Interceptor.Chain {
    final Request request;
    final int code;
    final ResponseBody body;
    FakeChain(String url, int code, ResponseBody body) {
      request = new Request.Builder().url(url).build(); this.code = code; this.body = body;
    }
    @Override public Request request() { return request; }
    @Override public Response proceed(Request ignored) {
      return new Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(code).message("Unit Test").body(body).build();
    }
    @Override public Connection connection() { return null; }
    @Override public Call call() { return null; }
    @Override public int connectTimeoutMillis() { return 0; }
    @Override public Interceptor.Chain withConnectTimeout(int timeout, java.util.concurrent.TimeUnit unit) { return this; }
    @Override public int readTimeoutMillis() { return 0; }
    @Override public Interceptor.Chain withReadTimeout(int timeout, java.util.concurrent.TimeUnit unit) { return this; }
    @Override public int writeTimeoutMillis() { return 0; }
    @Override public Interceptor.Chain withWriteTimeout(int timeout, java.util.concurrent.TimeUnit unit) { return this; }
  }
}
