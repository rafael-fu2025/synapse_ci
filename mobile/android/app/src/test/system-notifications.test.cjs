// Run with node --test <this-file>. JVM policy executes real Java; Android/RN
// lifecycle, notification delivery and taps below remain source contracts only.
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { mkdtempSync, readFileSync, rmSync, writeFileSync } = require('node:fs');
const { tmpdir } = require('node:os');
const { join, resolve } = require('node:path');
const { test } = require('node:test');

const main = resolve(__dirname, '../main');
const nativeDir = join(main, 'java/com/humus/synapse');
const moduleSource = readFileSync(join(nativeDir, 'SystemNotificationsModule.kt'), 'utf8');
const policy = readFileSync(join(nativeDir, 'SystemNotificationPolicy.java'), 'utf8');
const adapter = readFileSync(resolve(__dirname, '../../../../src/lib/push/systemNotifications.ts'), 'utf8');

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

function run(tool, args, cwd) {
  const executable = process.env.JAVA_HOME
    ? join(process.env.JAVA_HOME, 'bin', tool + (process.platform === 'win32' ? '.exe' : ''))
    : tool;
  const result = spawnSync(executable, args, { cwd, encoding: 'utf8', timeout: 60000 });
  assert.equal(result.error, undefined, result.error?.message);
  assert.equal(result.status, 0, `${tool} failed\n${result.stdout}\n${result.stderr}`);
  return result.stdout;
}

test('the optional module follows existing native package registration and exports the agreed bridge', () => {
  const application = readFileSync(join(nativeDir, 'MainApplication.kt'), 'utf8');
  assert.match(application, /add\(SystemNotificationsPackage\(\)\)/);
  assert.match(moduleSource, /@ReactModule\(name = SystemNotificationsModule\.NAME\)/);
  assert.match(moduleSource, /const val NAME = "SystemNotifications"/);
  assert.match(moduleSource, /class SystemNotificationsPackage : BaseReactPackage\(\)/);
  assert.match(moduleSource, /ReactModuleInfoProvider/);
  assert.match(moduleSource, /@ReactMethod\s+fun post\(ownerKey: String, messageId: String, title: String, body: String\)/);
  assert.match(moduleSource, /@ReactMethod\s+fun clear\(ownerKey: String\)/);
  assert.match(moduleSource, /@ReactMethod\s+fun areNotificationsEnabled\(promise: Promise\)/);
  assert.match(adapter, /export function showSystemNotification\(message: PushMessage, owner: PushOwner\): void/);
  assert.match(adapter, /export function clearSystemNotifications\(owner: PushOwner\): void/);
  assert.match(adapter, /export function hasSystemNotifications\(\): boolean/);
  assert.match(adapter, /export async function checkSystemNotifications\(\): Promise<boolean \| null>/);
  assert.match(adapter, /const OWNER_KEY_PREFIX = 'synapse-push-owner-'/);
  assert.match(policy, /OWNER_KEY_PREFIX = "synapse-push-owner-"/);
});

test('foreground bridge and background FCM use the existing channel and monochrome small icon', () => {
  const manifest = readFileSync(join(main, 'AndroidManifest.xml'), 'utf8');
  const application = readFileSync(join(nativeDir, 'MainApplication.kt'), 'utf8');
  const icon = readFileSync(join(main, 'res/drawable/ic_notification.xml'), 'utf8');
  const channelId = /const val NOTIFICATION_CHANNEL_ID = "([^"]+)"/.exec(application)?.[1];
  assert.equal(channelId, 'synapse-notifications');
  assert.match(manifest, /xmlns:tools="http:\/\/schemas\.android\.com\/tools"/);
  assert.match(manifest, /<meta-data\s+android:name="com\.google\.firebase\.messaging\.default_notification_channel_id"\s+android:value="synapse-notifications"\s+tools:replace="android:value"\s*\/>/);
  assert.match(manifest, /<meta-data\s+android:name="com\.google\.firebase\.messaging\.default_notification_icon"\s+android:resource="@drawable\/ic_notification"\s*\/>/);
  assert.match(moduleSource, /NotificationCompat\.Builder\(reactApplicationContext, MainApplication\.NOTIFICATION_CHANNEL_ID\)/);
  assert.match(moduleSource, /\.setSmallIcon\(R\.drawable\.ic_notification\)/);
  assert.match(icon, /<vector[\s\S]*android:width="24dp"[\s\S]*android:height="24dp"/);
  assert.match(icon, /android:fillColor="#FFFFFFFF"/);
  assert.doesNotMatch(icon, /<(?:bitmap|inset|adaptive-icon|gradient)|@(?:mipmap|drawable)\//);
  assert.match(moduleSource, /\.setAutoCancel\(true\)/);
  assert.match(moduleSource, /\.setOnlyAlertOnce\(true\)/);
  assert.match(moduleSource, /\.setVisibility\(NotificationCompat\.VISIBILITY_PRIVATE\)/);
});

test('posts recheck foreground/app/API33/channel availability without prompting or changing settings', () => {
  const post = body(moduleSource, 'fun post(');
  const enabled = body(moduleSource, 'private fun canShowNotifications()');
  assert.match(post, /if \(messageId\.isBlank\(\)\) return/);
  assert.match(post, /UiThreadUtil\.runOnUiThread[\s\S]*synchronized\(stateLock\)[\s\S]*!policy\.canPost\(ownerKey\) \|\| !isForeground\(\)/);
  assert.match(post, /if \(!canShowNotifications\(\) \|\| !policy\.rememberPost\(ownerKey, messageId\)\) return@synchronized/);
  assert.match(moduleSource, /hasActiveReactInstance\(\)[\s\S]*lifecycleState == LifecycleState\.RESUMED[\s\S]*currentActivity is MainActivity/);
  assert.match(enabled, /!notifications\.areNotificationsEnabled\(\)/);
  assert.match(enabled, /SDK_INT >= Build\.VERSION_CODES\.TIRAMISU[\s\S]*ContextCompat\.checkSelfPermission[\s\S]*Manifest\.permission\.POST_NOTIFICATIONS[\s\S]*PackageManager\.PERMISSION_GRANTED/);
  assert.match(enabled, /SDK_INT >= Build\.VERSION_CODES\.O[\s\S]*getNotificationChannel\(MainApplication\.NOTIFICATION_CHANNEL_ID\) \?: return false/);
  assert.match(enabled, /channel\.importance == NotificationManager\.IMPORTANCE_NONE/);
  assert.match(body(moduleSource, 'fun areNotificationsEnabled('), /promise\.resolve\(canShowNotifications\(\)\)/);
  assert.match(post, /var remembered = false[\s\S]*policy\.rememberPost\(ownerKey, messageId\)[\s\S]*remembered = true/);
  assert.match(post, /catch \(_: RuntimeException\)[\s\S]*if \(remembered\) policy\.forgetPost\(ownerKey, messageId\)/);
  assert.doesNotMatch(moduleSource, /requestPermissions|requestPermission\(|startActivity\(|ACTION_APP_NOTIFICATION_SETTINGS|Settings\.|createNotificationChannel/);
});

test('synchronous retirement guards queued posts and delayed clears touch only that owner tag namespace', () => {
  const clear = body(moduleSource, 'fun clear(');
  const post = body(moduleSource, 'fun post(');
  assert.ok(clear.indexOf('policy.retire(ownerKey)') < clear.indexOf('UiThreadUtil.runOnUiThread'));
  assert.equal((post.match(/policy\.canPost\(ownerKey\)/g) ?? []).length, 2);
  assert.match(clear, /manager\.activeNotifications/);
  assert.match(clear, /SystemNotificationPolicy\.ownerTagPrefix\(ownerKey\)/);
  assert.match(clear, /notification\.id == NOTIFICATION_ID && tag\.startsWith\(prefix\)/);
  assert.match(clear, /manager\.cancel\(tag, NOTIFICATION_ID\)/);
  assert.match(body(moduleSource, 'override fun invalidate()'), /synchronized\(stateLock\) \{ policy\.invalidate\(\) \}/);
  assert.doesNotMatch(moduleSource, /cancelAll\(|manager\.cancel\(NOTIFICATION_ID\)|notifications\.cancel\(NOTIFICATION_ID\)/);
  assert.match(policy, /retiredThroughEpoch = Math\.max\(retiredThroughEpoch, epoch\)/);
  assert.match(policy, /MAX_RECENT_MESSAGES = 128/);
  assert.match(policy, /recentMessages\.size\(\) > MAX_RECENT_MESSAGES[\s\S]*oldest\.remove\(\)/);
  assert.doesNotMatch(policy, /HashSet|retiredOwners|retiredKeys/);
});

test('notification taps reuse the launch intent and RNFirebase message id with unique immutable pending-intent data', () => {
  const intent = body(moduleSource, 'private fun notificationIntent(');
  const activity = readFileSync(join(nativeDir, 'MainActivity.kt'), 'utf8');
  const manifest = readFileSync(join(main, 'AndroidManifest.xml'), 'utf8');
  assert.match(intent, /getLaunchIntentForPackage\(context\.packageName\) \?: return null/);
  assert.match(intent, /launchIntent\.setClass\(context, MainActivity::class\.java\)/);
  assert.match(intent, /launchIntent\.action = Intent\.ACTION_MAIN/);
  assert.match(intent, /launchIntent\.data = Uri\.Builder\(\)[\s\S]*\.scheme\("synapse-notification"\)[\s\S]*\.authority\(context\.packageName\)[\s\S]*\.appendPath\(SystemNotificationPolicy\.notificationTag\(ownerKey, messageId\)\)/);
  assert.match(intent, /Intent\.FLAG_ACTIVITY_NEW_TASK or Intent\.FLAG_ACTIVITY_CLEAR_TOP or Intent\.FLAG_ACTIVITY_SINGLE_TOP/);
  assert.match(intent, /launchIntent\.putExtra\("google\.message_id", messageId\)/);
  assert.equal((intent.match(/\.putExtra\(/g) ?? []).length, 1);
  assert.match(intent, /PendingIntent\.getActivity\(context, NOTIFICATION_ID, launchIntent,[\s\S]*PendingIntent\.FLAG_UPDATE_CURRENT or PendingIntent\.FLAG_IMMUTABLE/);
  assert.match(manifest, /android:launchMode="singleTask"/);
  assert.doesNotMatch(activity, /override fun onNewIntent/);
  assert.doesNotMatch(moduleSource, /launchIntent\.action = Intent\.ACTION_VIEW|Uri\.parse\(|setFullScreenIntent|FLAG_MUTABLE/);
});

test('LAN notifications are generic at both the JS owner guard and immutable native variant boundary', () => {
  const post = body(moduleSource, 'fun post(');
  assert.match(moduleSource, /context\.packageName == "com\.humus\.synapse\.lantest" &&\s*BuildConfig\.BUILD_TYPE == "lanTestRelease"/);
  assert.match(post, /\.setContentTitle\(if \(isLanTest\) LAN_TITLE else title\)/);
  assert.match(post, /\.setContentText\(if \(isLanTest\) LAN_BODY else body\)/);
  assert.match(moduleSource, /LAN_TITLE = "Synapse LAN Test"/);
  assert.match(moduleSource, /LAN_BODY = "Open the app to check for updates"/);
  assert.match(adapter, /owner\.lanTest \? 'Synapse LAN Test'/);
  assert.match(adapter, /owner\.lanTest \? 'Open the app to check for updates'/);
  assert.match(adapter, /!isPushOwnerCurrent\(owner\) \|\| !acceptsPushMessage\(message, owner\)/);
  assert.doesNotMatch(adapter, /JSON\.stringify\(owner|owner\.(?:credentialBinding|apiBaseUrl|profileId|account|runtimeId)/);
  assert.match(policy, /MessageDigest\.getInstance\("SHA-256"\)/);
});

test('no Firebase store, custom receiver, background service, immersive UI or independent navigation is introduced', () => {
  const manifest = readFileSync(join(main, 'AndroidManifest.xml'), 'utf8');
  assert.doesNotMatch(moduleSource, /import (?:com\.google\.firebase|io\.invertase\.firebase)|storeFirebaseMessage|popRemoteMessage|MessagingStore|BroadcastReceiver|HeadlessJs|startService\(|JobScheduler|WorkManager|AlarmManager|FLAG_FULLSCREEN|SYSTEM_UI_FLAG|WindowInsets|PowerManager/);
  assert.doesNotMatch(manifest, /<receiver|<service|android:scheme="synapse-notification"/);
  assert.doesNotMatch(adapter, /Linking|navigate\(|requestPushPermission|requestNotifications|setTimeout|setInterval|AsyncStorage|Keychain/);
});

test('JVM policy covers owner-scoped dedup, hash-safe tags, bounded memory, failed-post retry and retirement ordering', t => {
  const outputDir = mkdtempSync(join(tmpdir(), 'synapse-system-notifications-'));
  try {
    const harness = join(outputDir, 'SystemNotificationPolicyContract.java');
    writeFileSync(harness, `package com.humus.synapse;

public final class SystemNotificationPolicyContract {
  private static int checks = 0;
  private static final String ONE = "synapse-push-owner-1";
  private static final String TWO = "synapse-push-owner-2";
  private static final String THREE = "synapse-push-owner-3";
  private static void check(boolean actual, String label) {
    checks++;
    if (!actual) throw new AssertionError(label);
  }

  public static void main(String[] args) {
    SystemNotificationPolicy policy = new SystemNotificationPolicy();
    for (String invalid : new String[] { null, "", "credential", "synapse-push-owner-", "synapse-push-owner-0",
        "synapse-push-owner-01", "synapse-push-owner--1", "synapse-push-owner-1.5", "synapse-push-owner-1e3",
        "synapse-push-owner-1 ", "synapse-push-owner-+1", "synapse-push-owner-9007199254740992",
        "synapse-push-owner-99999999999999999" }) {
      check(!policy.canPost(invalid), "invalid owner cannot post");
      check(!policy.retire(invalid), "invalid owner cannot retire another epoch");
    }
    check(policy.canPost("synapse-push-owner-9007199254740991"), "maximum safe JS epoch accepted");
    check(!policy.rememberPost(ONE, null), "null message id rejected");
    check(!policy.rememberPost(ONE, "  "), "blank message id rejected");
    check(policy.rememberPost(ONE, "fcm-id"), "first message accepted");
    check(!policy.rememberPost(ONE, "fcm-id"), "same owner/message deduplicated");
    check(policy.rememberPost(ONE, "second-id"), "second message accepted");
    check(policy.rememberPost(TWO, "fcm-id"), "same message distinct owner accepted");
    policy.forgetPost(ONE, "second-id");
    check(policy.rememberPost(ONE, "second-id"), "failed posting does not poison dedup");
    check(policy.rememberPost(ONE, "Aa"), "first String.hashCode collision accepted");
    check(policy.rememberPost(ONE, "BB"), "second String.hashCode collision stays distinct");
    String tag = SystemNotificationPolicy.notificationTag(ONE, "fcm-id");
    check(tag.equals(SystemNotificationPolicy.notificationTag(ONE, "fcm-id")), "tag stable");
    check(!tag.equals(SystemNotificationPolicy.notificationTag(TWO, "fcm-id")), "owner tags distinct");
    check(tag.startsWith(SystemNotificationPolicy.ownerTagPrefix(ONE)), "owner prefix exact");
    check(!SystemNotificationPolicy.notificationTag("synapse-push-owner-10", "fcm-id")
        .startsWith(SystemNotificationPolicy.ownerTagPrefix(ONE)), "epoch prefix cannot collide");
    check(!tag.contains("fcm-id"), "tag never exposes raw FCM id");
    check(SystemNotificationPolicy.notificationTag(ONE, "x".repeat(10000)).length() == tag.length(), "long ids have bounded tags");
    check(!SystemNotificationPolicy.notificationTag(ONE, "Aa")
        .equals(SystemNotificationPolicy.notificationTag(ONE, "BB")), "hash collision cannot replace tap payload");
    check(!SystemNotificationPolicy.notificationTag(ONE, "fcm-id")
        .equals(SystemNotificationPolicy.notificationTag(ONE, " fcm-id ")), "exact RNFirebase ids preserved");

    SystemNotificationPolicy bounded = new SystemNotificationPolicy();
    check(SystemNotificationPolicy.MAX_RECENT_MESSAGES == 128, "documented bounded dedup capacity");
    for (int index = 0; index < SystemNotificationPolicy.MAX_RECENT_MESSAGES; index++) {
      check(bounded.rememberPost(ONE, "id-" + index), "populate bounded cache");
    }
    check(!bounded.rememberPost(ONE, "id-0"), "oldest retained until capacity exceeded");
    check(!bounded.rememberPost(ONE, "id-127"), "newest retained");
    check(bounded.rememberPost(ONE, "id-128"), "capacity overflow accepted");
    check(bounded.rememberPost(ONE, "id-0"), "oldest evicted at capacity");
    check(!bounded.rememberPost(ONE, "id-2"), "other recent ids stay deduplicated");

    check(policy.retire(ONE), "first owner retired");
    check(!policy.canPost(ONE), "queued first owner work rejected after synchronous retire");
    check(!policy.rememberPost(ONE, "late-id"), "late first owner native call rejected");
    check(policy.canPost(TWO), "newer owner remains active");
    check(!policy.rememberPost(TWO, "fcm-id"), "old owner clear does not forget newer owner's dedup");
    check(policy.retire(ONE), "delayed repeated clear safe");
    check(policy.canPost(TWO), "delayed first clear cannot retire newer owner");
    check(policy.retire(TWO), "second owner retired");
    check(!policy.canPost(ONE) && !policy.canPost(TWO), "all retired epochs blocked");
    check(policy.canPost(THREE), "next epoch allowed");
    check(policy.rememberPost(THREE, "fcm-id"), "new owner may see same message id");
    check(policy.retire(THREE), "third owner retired");
    check(policy.retire(ONE), "old clear remains valid");
    check(!policy.canPost(THREE), "old clear cannot roll retirement watermark backward");

    SystemNotificationPolicy queued = new SystemNotificationPolicy();
    check(queued.retire(ONE), "clear can precede any observed post");
    check(!queued.rememberPost(ONE, "queued-id"), "unseen late work cannot recreate retired owner");
    check(queued.rememberPost(TWO, "queued-id"), "new owner still works after unseen retirement");
    for (int index = 1; index <= 10000; index++) queued.retire("synapse-push-owner-" + index);
    check(!queued.canPost(ONE), "arbitrarily old owner stays retired without tombstone set");
    check(!queued.canPost("synapse-push-owner-10000"), "last retirement blocked");
    check(queued.canPost("synapse-push-owner-10001"), "next owner survives many retirements");
    queued.invalidate();
    check(!queued.canPost("synapse-push-owner-10001"), "React invalidation drops queued posts");
    check(!queued.rememberPost("synapse-push-owner-10002", "id"), "destroyed module cannot accept new owner");
    System.out.println("System notification policy JVM checks passed: " + checks);
  }
}
`, 'utf8');
    run('javac', ['-d', outputDir, join(nativeDir, 'SystemNotificationPolicy.java'), harness], outputDir);
    const output = run('java', ['-cp', outputDir, 'com.humus.synapse.SystemNotificationPolicyContract'], outputDir);
    assert.match(output, /System notification policy JVM checks passed: \d+/);
    t.diagnostic(output.trim());
  } finally {
    rmSync(outputDir, { recursive: true, force: true });
  }
});
