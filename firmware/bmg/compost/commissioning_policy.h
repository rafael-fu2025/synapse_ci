#pragma once
#include <stdint.h>
#include <string.h>

namespace commissioning {
constexpr const char *VERSION = "bmg-commissioning-0.1.0";
constexpr const char *REJECT_MESSAGE = "Commissioning firmware: motor outputs disabled";
constexpr const char *STOP_MESSAGE = "Software outputs held LOW; physical stop not verified";

inline bool elapsed(uint32_t now, uint32_t since, uint32_t interval) {
  return uint32_t(now - since) >= interval;
}

inline bool isStop(const char *command) {
  return command && strcmp(command, "stop") == 0;
}

// A disabled schedule may contain null PWM. Enabled intent is never applied.
inline bool acceptDisabledSchedule(bool enabled, bool validVersion) {
  return !enabled && validVersion;
}

// SQL drivers may serialize command_id as a decimal string, not a JSON number.
inline uint64_t decimalId(const char *text) {
  if (!text || !*text || *text == '0') return 0;
  uint64_t id = 0;
  for (const char *p = text; *p; ++p) {
    if (*p < '0' || *p > '9') return 0;
    const uint64_t digit = uint64_t(*p - '0');
    if (id > (uint64_t(INT64_MAX) - digit) / 10) return 0;
    id = id * 10 + digit;
  }
  return id;
}

inline bool success(int status) { return status >= 200 && status < 300; }
}  // namespace commissioning
