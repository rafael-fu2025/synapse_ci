// Temporary network commissioning ONLY. No motor operation is implemented.
#include <Arduino.h>
#include <esp_arduino_version.h>
#include <driver/gpio.h>
#include <ArduinoJson.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <Preferences.h>
#include <time.h>
#include <mbedtls/sha256.h>
#include "commissioning_policy.h"
#include "device_config.h"  // Mandatory private generated header; no defaults.

#if ESP_ARDUINO_VERSION_MAJOR != 3 || ESP_ARDUINO_VERSION_MINOR != 3 || ESP_ARDUINO_VERSION_PATCH != 3
#error "Use Espressif Arduino ESP32 core 3.3.3"
#endif

#if ARDUINOJSON_VERSION_MAJOR != 7 || ARDUINOJSON_VERSION_MINOR != 4 || ARDUINOJSON_VERSION_REVISION != 2
#error "Install ArduinoJson 7.4.2"
#endif

namespace {
constexpr uint8_t MOTOR_IN1 = 4, MOTOR_IN2 = 5, MOTOR_ENABLE = 6;
constexpr uint32_t WIFI_CONNECT_MS = 15000, WIFI_BACKOFF_MS = 30000;
constexpr uint32_t HTTP_TIMEOUT_MS = 5000, NETWORK_BACKOFF_MS = 30000;
constexpr uint32_t HEARTBEAT_MS = 300000, SCHEDULE_MS = 600000, COMMAND_MS = 3500;
constexpr size_t MAX_BODY = 16384;
constexpr const char *API_PATH = "/api/v1/devices/bmg";
Preferences preferences;
bool configured = false, journalReady = false, connecting = false, connected = false;
bool attemptedWifi = false, networkFailed = false;
uint32_t wifiSince = 0, wifiRetrySince = 0, failureSince = 0;
uint32_t heartbeatSince = 0, scheduleSince = 0, commandSince = 0;
bool heartbeatSent = false, scheduleFetched = false, commandsFetched = false;
bool disabledScheduleApplied = false;
uint32_t appliedVersion = 0;

// One atomic NVS blob, not a bounded eviction cache. IDs are server-monotonic.
// Persist pending before sending, then retain the confirmed high-water mark.
struct Journal {
  uint32_t format;
  uint8_t binding[32];
  uint64_t id;
  uint8_t pending;
  uint8_t stop;
  uint8_t reserved[6];
};
Journal journal{};

void outputsOff() {
  // Core 3.3.3 digitalWrite rejects pins before pinMode; IDF sets the latch
  // even while the output driver is disabled, avoiding that initialization gap.
  gpio_set_level(static_cast<gpio_num_t>(MOTOR_ENABLE), LOW);
  gpio_set_level(static_cast<gpio_num_t>(MOTOR_IN1), LOW);
  gpio_set_level(static_cast<gpio_num_t>(MOTOR_IN2), LOW);
}

bool saveJournal() {
  if (preferences.putBytes("ack", &journal, sizeof(journal)) == sizeof(journal)) return true;
  journalReady = false;
  Serial.println("NVS failure: command acknowledgements disabled; outputs LOW");
  return false;
}

bool loadJournal() {
  uint8_t binding[32];
  // Bind deduplication to both deployment and credential, without storing either.
  String identity = String(API_BASE_URL) + "\n" + DEVICE_TOKEN;
  if (mbedtls_sha256(reinterpret_cast<const unsigned char *>(identity.c_str()),
                     identity.length(), binding, 0) != 0) return false;
  if (!preferences.begin("bmgcommission", false)) return false;
  const size_t length = preferences.getBytesLength("ack");
  if (length != 0) {
    if (length != sizeof(journal) || preferences.getBytes("ack", &journal, sizeof(journal)) != sizeof(journal)
        || journal.format != 1 || journal.pending > 1 || journal.stop > 1) return false;
    if (memcmp(binding, journal.binding, sizeof(binding)) == 0) return true;
  }
  journal = {};
  journal.format = 1;
  memcpy(journal.binding, binding, sizeof(binding));
  return saveJournal();
}

bool configValid() {
  String base(API_BASE_URL);
  if (!WIFI_SSID[0] || !DEVICE_TOKEN[0] || base.indexOf('@') >= 0 || base.indexOf('?') >= 0
      || base.indexOf('#') >= 0 || base.endsWith("/")) return false;
  for (size_t i = 0; i < base.length(); ++i) {
    if (uint8_t(base[i]) <= 32 || base[i] == '\\') return false;
  }
  for (const char *p = DEVICE_TOKEN; *p; ++p) {
    if (uint8_t(*p) <= 32 || uint8_t(*p) >= 127) return false;
  }
  if (base.startsWith("https://") && base.length() > 8) {
#ifdef BMG_ROOT_CA_PEM
    return strlen(BMG_ROOT_CA_PEM) > 0;
#else
    return false;
#endif
  }
  if (base.startsWith("http://") && base.length() > 7) {
#ifdef BMG_ALLOW_HTTP
    return BMG_ALLOW_HTTP == true;
#else
    return false;
#endif
  }
  return false;
}

// HTTPClient de-chunks into this bounded sink, including unknown-length bodies.
class BoundedBody : public Stream {
 public:
  String text;
  bool overflow = false;
  BoundedBody() { text.reserve(MAX_BODY); }
  size_t write(uint8_t byte) override { return write(&byte, 1); }
  size_t write(const uint8_t *data, size_t size) override {
    if (size > MAX_BODY - text.length()) { overflow = true; return 0; }
    if (!text.concat(reinterpret_cast<const char *>(data), size)) { overflow = true; return 0; }
    return size;
  }
  int available() override { return 0; }
  int read() override { return -1; }
  int peek() override { return -1; }
  void flush() override {}
};

bool request(const char *path, const String *payload, JsonDocument &response, int *resultCode) {
  outputsOff();
  WiFiClient plain;
  WiFiClientSecure tls;
  HTTPClient http;
  String url = String(API_BASE_URL) + API_PATH + path;
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.setFollowRedirects(HTTPC_DISABLE_FOLLOW_REDIRECTS);
  bool begun = false;
  if (url.startsWith("https://")) {
#ifdef BMG_ROOT_CA_PEM
    tls.setCACert(BMG_ROOT_CA_PEM);
    tls.setHandshakeTimeout(5);
    begun = http.begin(tls, url);
#endif
  } else {
#ifdef BMG_ALLOW_HTTP
    if (BMG_ALLOW_HTTP == true) begun = http.begin(plain, url);
#endif
  }
  if (!begun) return false;
  http.addHeader("X-Device-Token", DEVICE_TOKEN);
  http.addHeader("Accept", "application/json");
  http.addHeader("Content-Type", "application/json");
  const int status = payload ? http.POST(*payload) : http.GET();
  if (resultCode) *resultCode = status;
  bool ok = commissioning::success(status);
  if (ok) {
    BoundedBody body;
    ok = http.getSize() <= int(MAX_BODY) && http.writeToStream(&body) >= 0 && !body.overflow;
    if (ok) ok = !deserializeJson(response, body.text, DeserializationOption::NestingLimit(12))
                  && response.is<JsonObject>();
  }
  http.end();
  // Never log URLs, bodies, headers, Wi-Fi credentials, or exception text.
  Serial.printf("API %s HTTP %d: %s; outputs LOW\n", path, status, ok ? "JSON received" : "failed");
  if (!ok) { networkFailed = true; failureSince = millis(); }
  outputsOff();
  return ok;
}

bool wifiTick() {
  const uint32_t now = millis();
  if (WiFi.status() == WL_CONNECTED) {
    if (!connected) {
      Serial.println("WiFi connected; commissioning only; outputs LOW");
      // System time for TLS certificate dates only; NOT validation of the DS3231.
      if (String(API_BASE_URL).startsWith("https://")) {
        configTime(0, 0, "pool.ntp.org", "time.nist.gov");
        Serial.println("TLS waiting for system time; RTC remains unverified");
      }
    }
    connected = true;
    connecting = false;
    return true;
  }
  if (connected) {
    connected = false;
    wifiRetrySince = now;
    Serial.println("WiFi disconnected; outputs LOW");
  }
  if (connecting) {
    if (commissioning::elapsed(now, wifiSince, WIFI_CONNECT_MS)) {
      WiFi.disconnect(true, false);
      connecting = false;
      wifiRetrySince = now;
      Serial.println("WiFi connection timed out; retry in 30s; outputs LOW");
    }
    return false;
  }
  if (attemptedWifi && !commissioning::elapsed(now, wifiRetrySince, WIFI_BACKOFF_MS)) return false;
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  attemptedWifi = connecting = true;
  wifiSince = now;
  Serial.println("WiFi connecting; outputs LOW");
  return false;
}

void heartbeat() {
  JsonDocument body, response;
  body["firmware_version"] = commissioning::VERSION;
  // RTC is unverified: omit rtc_valid rather than inventing a measurement.
  if (disabledScheduleApplied) body["applied_schedule_version"] = appliedVersion;
  String payload;
  serializeJson(body, payload);
  if (request("/heartbeat", &payload, response, nullptr)) {
    Serial.println("Heartbeat accepted; NETWORK COMMISSIONING ONLY (no motor acceptance)");
  }
}

void pollSchedule() {
  // Always discard local state first; never retain an enabled schedule on errors.
  disabledScheduleApplied = false;
  appliedVersion = 0;
  JsonDocument response;
  if (!request("/schedule", nullptr, response, nullptr)) return;
  const bool validVersion = response["version"].is<uint32_t>()
                            && response["version"].as<uint32_t>() <= 2147483647UL;
  if (!response["enabled"].is<bool>() || !commissioning::acceptDisabledSchedule(
        response["enabled"].as<bool>(), validVersion)) {
    Serial.println("Schedule not applied (enabled or invalid); outputs LOW");
    return;
  }
  // PWM, slots and duration are deliberately not executable; null PWM is valid.
  appliedVersion = response["version"].as<uint32_t>();
  disabledScheduleApplied = true;
  Serial.printf("Disabled schedule v%lu observed/applied as OFF only; outputs LOW\n",
                static_cast<unsigned long>(appliedVersion));
}

bool acknowledgePending() {
  if (!journalReady || !journal.pending) return true;
  outputsOff();
  JsonDocument body, response;
  body["command_id"] = journal.id;
  body["status"] = journal.stop ? "completed" : "rejected";
  body["message"] = journal.stop ? commissioning::STOP_MESSAGE : commissioning::REJECT_MESSAGE;
  String payload;
  serializeJson(body, payload);
  int status = 0;
  if (!request("/commands/ack", &payload, response, &status)) {
    // Expired/cancelled/rebound/missing commands are terminal, not accepted ACKs.
    // Keep the high-water mark so a reboot never re-reports their outcome.
    if (status != 404 && status != 409) return false;
    journal.pending = 0;
    Serial.println("Command retired by server; acknowledgement NOT confirmed");
    saveJournal();
    return false;
  }
  if (strcmp(response["status"] | "", "acknowledged") != 0
      || !response["command_id"].is<uint64_t>() || response["command_id"].as<uint64_t>() != journal.id) return false;
  journal.pending = 0;
  return saveJournal();
}

uint64_t commandId(JsonVariantConst value) {
  if (value.is<const char *>()) return commissioning::decimalId(value.as<const char *>());
  if (!value.is<uint64_t>()) return 0;
  const uint64_t id = value.as<uint64_t>();
  return id <= INT64_MAX ? id : 0;
}

void pollCommands() {
  if (!journalReady || !acknowledgePending()) return;
  JsonDocument response;
  if (!request("/commands", nullptr, response, nullptr) || !response["commands"].is<JsonArray>()) return;
  JsonArray commands = response["commands"].as<JsonArray>();
  if (commands.size() > 20) return;
  // Validate the entire ordered batch before advancing a durable high-water mark.
  uint64_t previous = 0;
  for (JsonVariant entry : commands) {
    if (!entry.is<JsonObject>() || !entry["command"].is<const char *>()) return;
    const uint64_t id = commandId(entry["command_id"]);
    if (id == 0 || id > INT64_MAX || id <= previous) return;
    previous = id;
  }
  for (JsonVariant entry : commands) {
    const uint64_t id = commandId(entry["command_id"]);
    if (id <= journal.id) continue;
    journal.id = id;
    journal.stop = commissioning::isStop(entry["command"].as<const char *>());
    journal.pending = 1;
    if (!saveJournal() || !acknowledgePending()) return;
  }
}

void diagnostic() {
  Serial.printf("NETWORK COMMISSIONING ONLY | WiFi=%s | NVS=%s | outputs=LOW | RTC=unverified | motor acceptance=NOT TESTED\n",
                connected ? "connected" : "offline", journalReady ? "ready" : "fault");
}
}  // namespace

void setup() {
  // Load LOW latches before enabling outputs, before Serial, delays or networking.
  outputsOff();
  pinMode(MOTOR_ENABLE, OUTPUT);
  pinMode(MOTOR_IN1, OUTPUT);
  pinMode(MOTOR_IN2, OUTPUT);
  outputsOff();
  Serial.begin(115200);
  Serial.println("BMG commissioning: MOTOR OUTPUTS DISABLED. Serial '?' is read-only status.");
  WiFi.persistent(false);
  WiFi.setAutoReconnect(false);
  WiFi.mode(WIFI_STA);
  Serial.print("Chip MAC: ");
  Serial.println(WiFi.macAddress());
  configured = configValid();
  if (!configured) Serial.println("Configuration/TLS policy rejected; networking disabled; outputs LOW");
  if (configured) journalReady = loadJournal();
  if (configured && !journalReady) Serial.println("NVS unavailable/corrupt: acknowledgements disabled; outputs LOW");
  diagnostic();
}

void loop() {
  outputsOff();
  // No serial motor commands or generic pin/config writer. Other input is ignored.
  for (uint8_t i = 0; i < 32 && Serial.available(); ++i) {
    if (Serial.read() == '?') diagnostic();
  }
  if (!configured || !wifiTick()) { delay(10); return; }
  if (String(API_BASE_URL).startsWith("https://") && time(nullptr) < 1700000000) {
    delay(10); return;
  }
  uint32_t now = millis();
  if (networkFailed && !commissioning::elapsed(now, failureSince, NETWORK_BACKOFF_MS)) { delay(10); return; }
  networkFailed = false;
  // At most one polling operation per loop; fresh millis after each blocking request.
  if (!heartbeatSent || commissioning::elapsed(now, heartbeatSince, HEARTBEAT_MS)) {
    heartbeat(); heartbeatSent = true; heartbeatSince = millis();
  } else if (!scheduleFetched || commissioning::elapsed(now, scheduleSince, SCHEDULE_MS)) {
    pollSchedule(); scheduleFetched = true; scheduleSince = millis();
  } else if (!commandsFetched || commissioning::elapsed(now, commandSince, COMMAND_MS)) {
    pollCommands(); commandsFetched = true; commandSince = millis();
  }
  outputsOff();
  delay(10);
}
