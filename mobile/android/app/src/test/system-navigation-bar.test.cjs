// Source contracts only. Android compilation and on-device checks cover platform behavior.
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve, join } = require('node:path');
const { test } = require('node:test');

const main = resolve(__dirname, '../main');
const native = join(main, 'java/com/humus/synapse');
const policy = readFileSync(join(native, 'SystemNavigationBarPolicy.kt'), 'utf8');
const activity = readFileSync(join(native, 'MainActivity.kt'), 'utf8');
const moduleSource = readFileSync(join(native, 'SystemNavigationBarModule.kt'), 'utf8');

test('transparent navigation controls retain compatibility guards and are never hidden', () => {
  assert.match(policy, /SDK_INT >= Build\.VERSION_CODES\.O -> Color\.TRANSPARENT/);
  assert.match(policy, /darkIcons -> Color\.BLACK/);
  assert.match(policy, /SDK_INT >= Build\.VERSION_CODES\.P[\s\S]*navigationBarDividerColor = Color\.TRANSPARENT/);
  assert.match(policy, /SDK_INT >= Build\.VERSION_CODES\.Q[\s\S]*isNavigationBarContrastEnforced = false/);
  assert.match(policy, /isAppearanceLightNavigationBars =\s*Build\.VERSION\.SDK_INT >= Build\.VERSION_CODES\.O && darkIcons/);
  assert.doesNotMatch(policy, /\.hide\(|FLAG_FULLSCREEN|SYSTEM_UI_FLAG_HIDE_NAVIGATION/);
});

test('the optional bridge is registered and updates only the current Activity on the UI thread', () => {
  const application = readFileSync(join(native, 'MainApplication.kt'), 'utf8');
  assert.match(application, /add\(SystemNavigationBarPackage\(\)\)/);
  assert.match(moduleSource, /@ReactMethod\s+fun setAppearance/);
  assert.match(moduleSource, /UiThreadUtil\.runOnUiThread[\s\S]*reactApplicationContext\.currentActivity as\? MainActivity/);
  assert.match(moduleSource, /ReactModuleInfoProvider/);
});

test('Activity and modal windows reuse existing window ownership and appearance restoration', () => {
  assert.match(activity, /onExtraWindowCreate\(window: Window\)[\s\S]*getOrPut\(window\)[\s\S]*updateNavigationBarAppearance\(\)/);
  assert.match(activity, /onExtraWindowDestroy\(window: Window\)[\s\S]*refreshPolicies\.remove\(window\)/);
  assert.match(activity, /target === window\) activityAppearance else modalNavigationBarAppearance \?: activityAppearance/);
  assert.match(activity, /SystemNavigationBarPolicy\.apply\(target, appearance\.background, appearance\.darkIcons\)/);
  assert.match(activity, /if \(refreshPolicyDestroyed\) return/);
});

test('startup themes disable the contrast scrim only on supported API levels and follow night mode', () => {
  const styles = readFileSync(join(main, 'res/values/styles.xml'), 'utf8');
  assert.match(styles, /name="AppTheme" parent="SystemNavigationBarTheme"/);
  assert.match(styles, /navigationBarColor">@android:color\/black/);
  for (const version of [26, 28, 29]) {
    const themed = readFileSync(join(main, `res/values-v${version}/styles.xml`), 'utf8');
    assert.match(themed, /navigationBarColor">@android:color\/transparent/);
    assert.match(themed, /windowLightNavigationBar">@bool\/navigation_bar_dark_icons/);
    if (version >= 28) { assert.match(themed, /navigationBarDividerColor">@android:color\/transparent/); }
    if (version >= 29) { assert.match(themed, /enforceNavigationBarContrast">false/); }
    else { assert.doesNotMatch(themed, /enforceNavigationBarContrast/); }
  }
  assert.match(readFileSync(join(main, 'res/values/bools.xml'), 'utf8'), /navigation_bar_dark_icons">true/);
  assert.match(readFileSync(join(main, 'res/values-night/bools.xml'), 'utf8'), /navigation_bar_dark_icons">false/);
});
