export type RootStackParamList = {
  AppTabs: undefined;
  Announcements: undefined;
  AnnouncementDetail: { id: number };
  Surveys: undefined;
  SurveyForm: { id: number };
  Notifications: undefined;
};

export type AppTabsParamList = {
  Home: undefined;
  Visits: undefined;
  Queue: undefined;
  Profile: undefined;
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
