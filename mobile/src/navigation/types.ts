import type { CompositeNavigationProp, NavigatorScreenParams } from '@react-navigation/native';
import type { BottomTabNavigationProp } from '@react-navigation/bottom-tabs';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';

export type RootStackParamList = {
  AppTabs: NavigatorScreenParams<ContextTabsParamList> | undefined;
  Announcements: undefined;
  AnnouncementDetail: { id: number };
  Surveys: undefined;
  SurveyForm: { id: number };
  Notifications: undefined;
};

export type ContextTabsParamList = {
  PersonalContext: NavigatorScreenParams<PersonalTabsParamList> | undefined;
  WorkspaceContext: NavigatorScreenParams<WorkspaceTabsParamList> | undefined;
};

export type PersonalTabsParamList = {
  Home: NavigatorScreenParams<HomeStackParamList> | undefined;
  Visits: NavigatorScreenParams<VisitsStackParamList> | undefined;
  Queue: NavigatorScreenParams<QueueStackParamList> | undefined;
  Profile: NavigatorScreenParams<ProfileStackParamList> | undefined;
};

/** Backward-compatible alias for existing personal screens. */
export type AppTabsParamList = PersonalTabsParamList;

export type WorkspaceTabsParamList = {
  TeamBoard: NavigatorScreenParams<TeamTasksStackParamList> | undefined;
  MyTasks: NavigatorScreenParams<MyTasksStackParamList> | undefined;
  TeamChat: { targetMessageId?: number } | undefined;
  TeamCalendar: NavigatorScreenParams<TeamCalendarStackParamList> | undefined;
};

export type TeamCalendarStackParamList = {
  TeamCalendarHome: undefined;
  TeamCalendarEventDetail: { id: number };
  TeamCalendarEventForm: { id?: number; initialDate?: string } | undefined;
};

export type TeamTasksStackParamList = {
  TeamTaskList: undefined;
  TeamTaskDetail: { id: number };
  TeamTaskForm: { id?: number } | undefined;
};

export type MyTasksStackParamList = {
  MyTaskList: undefined;
  TeamTaskDetail: { id: number };
  TeamTaskForm: { id?: number } | undefined;
};

export type HomeStackParamList = {
  Dashboard: undefined;
};

export type VisitsStackParamList = {
  Appointments: undefined;
  AppointmentDetail: { id: number };
  Booking: undefined;
  CancelAppointment: { id: number };
};

export type QueueStackParamList = {
  QueueBoard: undefined;
};

export type ProfileStackParamList = {
  ProfileHome: undefined;
  HealthProfile: undefined;
  ProfilePhoto: undefined;
  Records: undefined;
  Settings: undefined;
  PrivacySecurity: undefined;
  Appearance: undefined;
  StaffQueue: undefined;
};

export type HomeNavigation = CompositeNavigationProp<
  NativeStackNavigationProp<HomeStackParamList, 'Dashboard'>,
  CompositeNavigationProp<BottomTabNavigationProp<PersonalTabsParamList, 'Home'>, NativeStackNavigationProp<RootStackParamList>>
>;

export type ProfileNavigation = CompositeNavigationProp<
  NativeStackNavigationProp<ProfileStackParamList, 'ProfileHome'>,
  BottomTabNavigationProp<PersonalTabsParamList, 'Profile'>
>;

export type QueueNavigation = CompositeNavigationProp<
  NativeStackNavigationProp<QueueStackParamList, 'QueueBoard'>,
  BottomTabNavigationProp<PersonalTabsParamList, 'Queue'>
>;
