// Run with node --test <this-file>. JDK tools are already required by Android builds.
// Executes the real numeric selector on the JVM. Lifecycle checks below are source
// contracts only: they do not simulate Android/RN windows or measure delivered FPS.
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { mkdtempSync, readFileSync, readdirSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const { join, resolve } = require('node:path');
const { test } = require('node:test');

const nativeDir = resolve(__dirname, '../main/java/com/humus/synapse');
const activity = readFileSync(join(nativeDir, 'MainActivity.kt'), 'utf8');
const policy = readFileSync(join(nativeDir, 'WindowRefreshRatePolicy.kt'), 'utf8');
const selector = readFileSync(join(nativeDir, 'SupportedRefreshRatePolicy.java'), 'utf8');

function body(source, start) {
  const marker = source.indexOf(start);
  assert.notEqual(marker, -1, `Missing source contract: ${start}`);
  const opening = source.indexOf('{', marker + start.length);
  let depth = 1;
  for (let index = opening + 1; index < source.length; index++) {
    if (source[index] === '{') depth++;
    if (source[index] === '}' && --depth === 0) return source.slice(opening + 1, index);
  }
  assert.fail(`Unclosed source contract: ${start}`);
}

function run(tool, args) {
  const executable = process.env.JAVA_HOME
    ? join(process.env.JAVA_HOME, 'bin', tool + (process.platform === 'win32' ? '.exe' : ''))
    : tool;
  const result = spawnSync(executable, args, { encoding: 'utf8', timeout: 60000 });
  assert.equal(result.error, undefined, result.error?.message);
  assert.equal(result.status, 0, `${tool} failed\n${result.stdout}\n${result.stderr}`);
  return result.stdout;
}

test('native source packages use canonical compiler discovery paths', () => {
  const root = resolve(__dirname, '../main/java');
  function inspect(directory, parts = []) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      if (entry.isDirectory()) { inspect(join(directory, entry.name), [...parts, entry.name]); }
      else if (/\.(?:java|kt)$/.test(entry.name)) {
        const source = readFileSync(join(directory, entry.name), 'utf8');
        const declared = /^package\s+([\w.]+)/m.exec(source);
        assert.ok(declared, `Missing package: ${entry.name}`);
        assert.equal(parts.join('.'), declared[1], `Package directory mismatch: ${entry.name}`);
      }
    }
  }
  inspect(root);
});

test('JVM selector uses only valid advertised same-resolution rates', () => {
  const outputDir = mkdtempSync(join(tmpdir(), 'synapse-refresh-policy-'));
  try {
    run('javac', ['-d', outputDir, join(nativeDir, 'SupportedRefreshRatePolicy.java'),
      join(__dirname, 'java/com/humus/synapse/SupportedRefreshRatePolicyTest.java')]);
    assert.match(run('java', ['-cp', outputDir, 'com.humus.synapse.SupportedRefreshRatePolicyTest']),
      /22 refresh-rate selection checks passed/);
  } finally {
    rmSync(outputDir, { recursive: true, force: true });
  }
});

test('Android 16 uses descendant adaptive category, without forcing child preferences', () => {
  assert.match(policy, /SDK_INT >= Build\.VERSION_CODES\.BAKLAVA/);
  assert.match(policy, /propagateRequestedFrameRate\(View\.REQUESTED_FRAME_RATE_CATEGORY_HIGH, false\)/);
  assert.match(body(policy, 'private fun clearPreference()'),
    /propagateRequestedFrameRate\(View\.REQUESTED_FRAME_RATE_CATEGORY_DEFAULT, false\)/);
  assert.match(body(policy, 'private fun clearPreference()'), /root\.requestedFrameRate = it/);
});

test('legacy Window hint preserves prior preference and never selects a mode ID', () => {
  assert.match(policy, /if \(attributes\.preferredDisplayModeId != 0\)/);
  assert.match(policy, /previousWindowRefreshRate = attributes\.preferredRefreshRate/);
  assert.match(policy, /attributes\.preferredRefreshRate = rate/);
  assert.match(body(policy, 'private fun clearPreference()'),
    /if \(attributes\.preferredRefreshRate == requestedWindowRefreshRate\)/);
  assert.match(body(policy, 'private fun clearPreference()'), /attributes\.preferredRefreshRate = previous/);
  assert.doesNotMatch(policy, /preferredDisplayModeId\s*=(?!=)/);
  assert.doesNotMatch(policy + selector, /\b(?:60|90|120)(?:f|\.0f)?\b/);
});

test('resume observes displays; pause/destroy release all windows and listeners', () => {
  const resume = body(activity, 'override fun onResume()');
  assert.match(resume, /refreshPolicyActive = true/);
  assert.match(resume, /registerDisplayListener\(displayListener, Handler\(Looper\.getMainLooper\(\)\)\)/);
  assert.match(resume, /updateRefreshRatePreferences\(\)/);
  for (const lifecycle of ['override fun onPause()', 'override fun onDestroy()']) {
    assert.match(body(activity, lifecycle), /stopRefreshRatePreferences\(\)/);
  }
  const stop = body(activity, 'private fun stopRefreshRatePreferences()');
  assert.match(stop, /refreshPolicyActive = false/);
  assert.match(stop, /unregisterDisplayListener\(displayListener\)/);
  assert.match(stop, /refreshPolicies\.values\.forEach \{ it\.release\(\) \}/);
  assert.match(body(activity, 'private fun updateRefreshRatePreferences()'), /if \(refreshPolicyActive\)/);
});

test('display move/configuration/change recalculate; removed/detached display releases', () => {
  for (const callback of ['override fun onDisplayAdded(', 'override fun onDisplayChanged(']) {
    assert.match(activity, new RegExp(callback.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
      + '[^\\n]+updateRefreshRatePreferences\\(\\)'));
  }
  for (const callback of ['override fun onConfigurationChanged(', 'override fun onAttachedToWindow()']) {
    assert.match(body(activity, callback), /updateRefreshRatePreferences\(\)/);
  }
  assert.match(body(activity, 'override fun onDetachedFromWindow()'), /refreshPolicies\[window\]\?\.release\(\)/);
  assert.match(policy, /if \(display == null \|\| !display\.isValid\)/);
  assert.match(policy, /if \(display\.displayId != displayId\) \{\s*clearPreference\(\)/);
  assert.match(body(policy, 'fun onDisplayRemoved('), /if \(removedDisplayId == displayId\) release\(\)/);
  assert.match(body(policy, 'private fun clearPreference()'), /displayId = null/);
});

test('configuration rechecks once after ViewRoot updates and cancels pending work on detach/pause', () => {
  const config = body(activity, 'override fun onConfigurationChanged(');
  assert.match(config, /removeCallbacks\(configurationRefresh\)/);
  assert.match(config, /if \(refreshPolicyActive\) window\.decorView\.post\(configurationRefresh\)/);
  assert.equal((config.match(/\.post\(configurationRefresh\)/g) ?? []).length, 1);
  assert.match(activity, /configurationRefresh = Runnable \{\s*if \(!refreshPolicyDestroyed\) updateRefreshRatePreferences\(\)/);
  for (const lifecycle of ['override fun onDetachedFromWindow()', 'private fun stopRefreshRatePreferences()']) {
    assert.match(body(activity, lifecycle), /removeCallbacks\(configurationRefresh\)/);
  }
});

test('modal attach waits for its display and detachment clears hints without a frame loop', () => {
  assert.match(body(policy, 'fun update()'), /root\.addOnAttachStateChangeListener\(attachListener\)/);
  assert.match(policy, /onViewAttachedToWindow\(view: View\) = update\(\)/);
  assert.match(policy, /onViewDetachedFromWindow\(view: View\) = clearPreference\(\)/);
  assert.match(body(policy, 'fun release()'), /removeOnAttachStateChangeListener\(attachListener\)/);
  assert.match(body(policy, 'fun release()'), /observedRoot = null/);
  assert.match(body(policy, 'fun release()'), /clearPreference\(\)/);
});

test('RN separate Modal windows use supported events and the same per-window policy', () => {
  assert.match(activity, /import com\.facebook\.react\.interfaces\.ExtraWindowEventListener/);
  const create = body(activity, 'override fun onExtraWindowCreate(');
  assert.match(create, /observedReactContext\?\.currentActivity !== this@MainActivity/);
  assert.match(create, /refreshPolicies\.getOrPut\(window\) \{ WindowRefreshRatePolicy\(window\) \}/);
  assert.match(create, /if \(refreshPolicyActive\) policy\.update\(\)/);
  assert.match(body(activity, 'override fun onExtraWindowDestroy('),
    /refreshPolicies\.remove\(window\)\?\.release\(\)/);
  assert.match(body(activity, 'private fun observeExtraWindows('),
    /if \(refreshPolicyDestroyed \|\| observedReactContext === context\) return/);
});

test('React context reload/destruction and Activity destruction remove modal observations', () => {
  assert.match(activity, /beforeReactDestroyListener: \(\) -> Unit = \{ releaseExtraWindows\(\) \}/);
  assert.match(activity, /host\.addBeforeDestroyListener\(beforeReactDestroyListener\)/);
  assert.match(body(activity, 'private fun observeExtraWindows('), /releaseExtraWindows\(\)/);
  const clear = body(activity, 'private fun releaseExtraWindows()');
  assert.match(clear, /removeExtraWindowEventListener\(extraWindowListener\)/);
  assert.match(clear, /observedReactContext = null/);
  assert.match(clear, /filter \{ it !== window \}/);
  assert.match(clear, /refreshPolicies\.remove\(extraWindow\)\?\.release\(\)/);
  const destroy = body(activity, 'override fun onDestroy()');
  assert.match(destroy, /refreshPolicyDestroyed = true/);
  assert.match(destroy, /removeReactInstanceEventListener\(reactInstanceListener\)/);
  assert.match(destroy, /removeBeforeDestroyListener\(beforeReactDestroyListener\)/);
  assert.match(destroy, /refreshPolicies\.clear\(\)/);
});

test('no power settings override, surface internals, timer or frame-production loop', () => {
  assert.doesNotMatch(activity + policy + selector,
    /setFrameRatePowerSavingsBalanced\(|setFrameRateBoostOnTouchEnabled\(|Settings\.(?:System|Global)|WRITE_SETTINGS|PowerManager|SurfaceControl|Choreographer|postFrameCallback|postDelayed|setInterval|requestAnimationFrame|Class\.forName|getDeclaredField/);
  const plist = readFileSync(resolve(__dirname, '../../../../ios/SynapseMobile/Info.plist'), 'utf8');
  assert.match(plist, /<key>CADisableMinimumFrameDurationOnPhone<\/key>\s*<true\s*\/>/);
});
