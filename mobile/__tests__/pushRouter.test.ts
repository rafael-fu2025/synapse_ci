import { routePushTap } from '../src/lib/push/router';
import { navigationRef } from '../src/navigation/navigationRef';

jest.mock('../src/navigation/navigationRef', () => ({
  navigationRef: { isReady: jest.fn(), navigate: jest.fn() },
  navigateToAppointmentDetail: jest.fn(),
}));

const mockedRef = navigationRef as unknown as { isReady: jest.Mock; navigate: jest.Mock };
const { navigateToAppointmentDetail } = jest.requireMock('../src/navigation/navigationRef') as {
  navigateToAppointmentDetail: jest.Mock;
};

beforeEach(() => {
  jest.clearAllMocks();
  mockedRef.isReady.mockReturnValue(true);
});

it('routes a confirmed-appointment push to the nested Visits detail screen', () => {
  routePushTap({ data: { type: 'appointment_confirmed', id: '314' } });
  expect(navigateToAppointmentDetail).toHaveBeenCalledWith(314);
});

it('routes a published-announcement push to the announcement detail', () => {
  routePushTap({ data: { type: 'announcement_published', id: '41' } });
  expect(mockedRef.navigate).toHaveBeenCalledWith('AnnouncementDetail', { id: 41 });
});

it('routes a published-survey push to the surveys list', () => {
  routePushTap({ data: { type: 'survey_published', id: '9' } });
  expect(mockedRef.navigate).toHaveBeenCalledWith('Surveys');
});

it('ignores unknown types, bad ids, missing data, and a cold navigator', () => {
  routePushTap({ data: { type: 'unknown_type', id: '1' } });
  routePushTap({ data: { type: 'appointment_confirmed', id: 'abc' } });
  routePushTap({ data: { type: 'appointment_confirmed', id: '0' } });
  routePushTap({ data: { type: 'appointment_confirmed' } });
  routePushTap(null);
  expect(navigateToAppointmentDetail).not.toHaveBeenCalled();
  expect(mockedRef.navigate).not.toHaveBeenCalled();

  mockedRef.isReady.mockReturnValue(false);
  routePushTap({ data: { type: 'announcement_published', id: '5' } });
  expect(mockedRef.navigate).not.toHaveBeenCalled();
});
