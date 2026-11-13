package com.humus.synapse;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.Iterator;
import java.util.LinkedHashMap;
import java.util.Map;

/** Android-free ownership/dedup policy, called under the bridge's state lock. */
final class SystemNotificationPolicy {
  static final String OWNER_KEY_PREFIX = "synapse-push-owner-";
  static final String TAG_PREFIX = "synapse:foreground:";
  static final int MAX_RECENT_MESSAGES = 128;
  private static final long MAX_JS_EPOCH = 9007199254740991L;

  private final LinkedHashMap<String, Long> recentMessages = new LinkedHashMap<>();
  private long retiredThroughEpoch = 0;
  private boolean invalidated = false;

  boolean canPost(String ownerKey) {
    long epoch = ownerEpoch(ownerKey);
    return !invalidated && epoch > retiredThroughEpoch;
  }

  boolean rememberPost(String ownerKey, String messageId) {
    if (!canPost(ownerKey) || messageId == null || messageId.trim().isEmpty()) return false;
    String tag = notificationTag(ownerKey, messageId);
    if (recentMessages.containsKey(tag)) return false;
    recentMessages.put(tag, ownerEpoch(ownerKey));
    if (recentMessages.size() > MAX_RECENT_MESSAGES) {
      Iterator<String> oldest = recentMessages.keySet().iterator();
      oldest.next();
      oldest.remove();
    }
    return true;
  }

  void forgetPost(String ownerKey, String messageId) {
    recentMessages.remove(notificationTag(ownerKey, messageId));
  }

  boolean retire(String ownerKey) {
    long epoch = ownerEpoch(ownerKey);
    if (epoch == 0) return false;
    // PushOwner epochs increase within a React context. A watermark rejects even
    // arbitrarily late work without retaining an unbounded set of retired keys.
    retiredThroughEpoch = Math.max(retiredThroughEpoch, epoch);
    Iterator<Map.Entry<String, Long>> entries = recentMessages.entrySet().iterator();
    while (entries.hasNext()) {
      if (entries.next().getValue() <= retiredThroughEpoch) entries.remove();
    }
    return true;
  }

  void invalidate() {
    invalidated = true;
    recentMessages.clear();
  }

  static String ownerTagPrefix(String ownerKey) {
    return TAG_PREFIX + ownerKey + ":";
  }

  static String notificationTag(String ownerKey, String messageId) {
    // Keep platform tags/data bounded, delimiter-safe, and free of raw FCM ids.
    try {
      byte[] digest = MessageDigest.getInstance("SHA-256")
          .digest(messageId.getBytes(StandardCharsets.UTF_8));
      char[] hex = new char[digest.length * 2];
      final char[] alphabet = "0123456789abcdef".toCharArray();
      for (int index = 0; index < digest.length; index++) {
        int value = digest[index] & 0xff;
        hex[index * 2] = alphabet[value >>> 4];
        hex[index * 2 + 1] = alphabet[value & 0xf];
      }
      return ownerTagPrefix(ownerKey) + new String(hex);
    } catch (NoSuchAlgorithmException impossible) {
      throw new IllegalStateException("SHA-256 is unavailable", impossible);
    }
  }

  private static long ownerEpoch(String ownerKey) {
    if (ownerKey == null || !ownerKey.startsWith(OWNER_KEY_PREFIX)) return 0;
    String suffix = ownerKey.substring(OWNER_KEY_PREFIX.length());
    if (suffix.isEmpty() || suffix.length() > 16 || suffix.charAt(0) == '0') return 0;
    for (int index = 0; index < suffix.length(); index++) {
      char digit = suffix.charAt(index);
      if (digit < '0' || digit > '9') return 0;
    }
    long epoch = Long.parseLong(suffix);
    return epoch <= MAX_JS_EPOCH ? epoch : 0;
  }
}
