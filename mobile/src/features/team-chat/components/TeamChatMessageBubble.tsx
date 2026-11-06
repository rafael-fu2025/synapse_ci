import React, { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Reply } from 'lucide-react-native';
import type { TeamChatMessage } from '../../../api/endpoints/teamChat';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { formatChatTime, normalizeMentions } from '../model';

function preview(value: string, maximum = 180): string {
  const characters = Array.from(value.replace(/\s+/g, ' ').trim());
  return characters.length <= maximum
    ? characters.join('')
    : `${characters.slice(0, maximum).join('')}…`;
}

export function TeamChatMessageBubble({
  message,
  own,
  targeted,
  replyDisabled,
  onReply,
}: {
  message: TeamChatMessage;
  own: boolean;
  targeted: boolean;
  replyDisabled: boolean;
  onReply: (message: TeamChatMessage) => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const mentions = useMemo(
    () => normalizeMentions(message.body, message.mentions),
    [message.body, message.mentions],
  );
  const body = useMemo(() => {
    const pieces: React.ReactNode[] = [];
    let offset = 0;
    mentions.forEach((mention, index) => {
      if (mention.start > offset) {
        pieces.push(message.body.slice(offset, mention.start));
      }
      pieces.push(
        <Text key={`${mention.start}:${mention.user_id}:${index}`} style={styles.mention}>
          {message.body.slice(mention.start, mention.start + mention.length)}
        </Text>,
      );
      offset = mention.start + mention.length;
    });
    if (offset < message.body.length) { pieces.push(message.body.slice(offset)); }
    return pieces;
  }, [mentions, message.body, styles.mention]);

  return (
    <View
      testID={`team-chat-message-${message.id}`}
      accessibilityLabel={`${message.sender.name}, ${formatChatTime(message.created_at)}. ${message.body}`}
      style={[styles.row, own && styles.ownRow, targeted && styles.targeted]}>
      <View style={[styles.bubble, own && styles.ownBubble]}>
        <View style={styles.heading}>
          <Text numberOfLines={1} style={[styles.sender, own && styles.ownText]}>
            {own ? 'You' : message.sender.name}
          </Text>
          <Text style={[styles.time, own && styles.ownMuted]}>
            {formatChatTime(message.created_at)}
          </Text>
        </View>
        {message.reply_to ? (
          <View style={[styles.quote, own && styles.ownQuote]}>
            <Text style={[styles.quoteSender, own && styles.ownText]}>
              Reply to {message.reply_to.sender_name}
            </Text>
            <Text numberOfLines={2} style={[styles.quoteBody, own && styles.ownMuted]}>
              {preview(message.reply_to.body)}
            </Text>
          </View>
        ) : null}
        <Text selectable style={[styles.body, own && styles.ownText]}>{body}</Text>
        {message.tasks.length > 0 ? (
          <View accessibilityLabel={`${message.tasks.length} task references`} style={styles.tasks}>
            {message.tasks.map(task => (
              <View key={task.id} style={[styles.task, own && styles.ownTask]}>
                <Text numberOfLines={2} style={[styles.taskText, own && styles.ownText]}>
                  Task #{task.id}: {task.title}
                </Text>
              </View>
            ))}
          </View>
        ) : null}
      </View>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`Reply to ${message.sender.name}`}
        accessibilityState={{ disabled: replyDisabled }}
        disabled={replyDisabled}
        onPress={() => onReply(message)}
        style={({ pressed }) => [
          styles.reply,
          replyDisabled && styles.disabled,
          pressed && styles.pressed,
        ]}>
        <Reply size={17} color={theme.ink500} />
      </Pressable>
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    row: {
      alignSelf: 'stretch',
      flexDirection: 'row',
      alignItems: 'flex-end',
      gap: 4,
      paddingHorizontal: 12,
      paddingVertical: 4,
      borderWidth: 2,
      borderColor: 'transparent',
      borderRadius: 16,
    },
    ownRow: { flexDirection: 'row-reverse' },
    targeted: { borderColor: '#d1972f', backgroundColor: '#faedcf' },
    bubble: {
      maxWidth: '84%',
      minWidth: 96,
      paddingHorizontal: 12,
      paddingVertical: 9,
      borderRadius: 16,
      borderBottomLeftRadius: 5,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    ownBubble: {
      borderBottomLeftRadius: 16,
      borderBottomRightRadius: 5,
      borderColor: theme.brand,
      backgroundColor: theme.brand,
    },
    heading: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 4 },
    sender: { ...type.caption, flex: 1, color: theme.ink700 },
    time: { fontFamily: font.body, fontSize: 9, color: theme.ink500 },
    body: { ...type.body, color: theme.ink900, lineHeight: 18 },
    ownText: { color: '#ffffff' },
    ownMuted: { color: 'rgba(255,255,255,0.78)' },
    mention: {
      color: theme.mode === 'dark' ? '#ffffff' : theme.brandText,
      fontWeight: '800',
      backgroundColor: theme.brand100,
    },
    quote: {
      marginBottom: 7,
      paddingLeft: 9,
      paddingVertical: 4,
      borderLeftWidth: 3,
      borderLeftColor: theme.brand,
      backgroundColor: theme.surfaceWarm,
    },
    ownQuote: {
      borderLeftColor: '#ffffff',
      backgroundColor: 'rgba(255,255,255,0.12)',
    },
    quoteSender: { ...type.caption, color: theme.ink700 },
    quoteBody: { ...type.caption, marginTop: 2, color: theme.ink500 },
    tasks: { gap: 5, marginTop: 8 },
    task: {
      alignSelf: 'flex-start',
      maxWidth: '100%',
      paddingHorizontal: 9,
      paddingVertical: 6,
      borderRadius: 10,
      backgroundColor: theme.brand50,
    },
    ownTask: { backgroundColor: 'rgba(255,255,255,0.14)' },
    taskText: { ...type.caption, color: theme.brandText },
    reply: {
      width: 44,
      height: 44,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 12,
    },
    disabled: { opacity: 0.35 },
    pressed: { opacity: 0.65 },
  });
}

const makeStyles = memoizeStyles(createStyles);
