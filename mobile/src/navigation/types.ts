import type { CompositeNavigationProp, NavigatorScreenParams } from '@react-navigation/native';
import type { BottomTabNavigationProp } from '@react-navigation/bottom-tabs';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';

export type RootStackParamList = {
  AppTabs: NavigatorScreenParams<AppTabsParamList> | undefined;
  Announcements: undefined;
  AnnouncementDetail: { id: number };
  Surveys: undefined;
  SurveyForm: { id: number };
  Notifications: undefined;
};

export type AppTabsParamList = {
  Home: NavigatorScreenParams<HomeStackParamList> | undefined;
  Visits: NavigatorScreenParams<VisitsStackParamList> | undefined;
  Queue: NavigatorScreenParams<QueueStackParamList> | undefined;
  Profile: NavigatorScreenParams<ProfileStackParamList> | undefined;
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
  CompositeNavigationProp<BottomTabNavigationProp<AppTabsParamList, 'Home'>, NativeStackNavigationProp<RootStackParamList>>
>;

export type ProfileNavigation = CompositeNavigationProp<
  NativeStackNavigationProp<ProfileStackParamList, 'ProfileHome'>,
  BottomTabNavigationProp<AppTabsParamList, 'Profile'>
>;

export type QueueNavigation = CompositeNavigationProp<
  NativeStackNavigationProp<QueueStackParamList, 'QueueBoard'>,
  BottomTabNavigationProp<AppTabsParamList, 'Queue'>
>;
