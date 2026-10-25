#include "../../firmware/bmg/compost/commissioning_policy.h"
#include <assert.h>
#include <stdint.h>

int main() {
  using namespace commissioning;
  assert(!elapsed(100, 100, 1));
  assert(elapsed(110, 100, 10));
  assert(!elapsed(0x10, 0xfffffff0, 33));
  assert(elapsed(0x10, 0xfffffff0, 32));
  assert(elapsed(0x7520, 0xfffffff0, 30000));
  assert(!elapsed(0x10, 0xfffffff0, 30000));

  assert(isStop("stop"));
  assert(!isStop("run_now"));
  assert(!isStop("s"));
  assert(!isStop("r"));
  assert(!isStop("STOP"));
  assert(!isStop(""));
  assert(!isStop(nullptr));
  assert(acceptDisabledSchedule(false, true));
  assert(!acceptDisabledSchedule(true, true));
  assert(!acceptDisabledSchedule(false, false));
  assert(!acceptDisabledSchedule(true, false));

  assert(!success(-1));
  assert(!success(199));
  assert(success(200));
  assert(success(201));
  assert(success(299));
  assert(!success(300));
  assert(!success(401));
  assert(!success(503));

  assert(decimalId("1") == 1);
  assert(decimalId("1234567890123") == 1234567890123ULL);
  assert(decimalId("9223372036854775807") == uint64_t(INT64_MAX));
  assert(decimalId("9223372036854775808") == 0);
  assert(decimalId("18446744073709551616") == 0);
  assert(decimalId("0") == 0);
  assert(decimalId("01") == 0);
  assert(decimalId("-1") == 0);
  assert(decimalId("+1") == 0);
  assert(decimalId("1.0") == 0);
  assert(decimalId("1e2") == 0);
  assert(decimalId(" 1") == 0);
  assert(decimalId("1\n") == 0);
  assert(decimalId("") == 0);
  assert(decimalId(nullptr) == 0);
  assert(strcmp(REJECT_MESSAGE, "Commissioning firmware: motor outputs disabled") == 0);
  assert(strstr(STOP_MESSAGE, "physical stop not verified") != nullptr);
}
