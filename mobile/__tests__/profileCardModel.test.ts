import type { HealthSummary } from '../src/api/endpoints/health';
import { profileCardFromSummary } from '../src/features/profile/profileCardModel';

function summary(
  person: Partial<HealthSummary['person']> = {},
  health: Partial<HealthSummary['health']> = {},
): HealthSummary {
  return {
    person: {
      name: '  Alex Rivera  ', kind: 'student', identifier: '  2026-001  ',
      email: '  alex@university.test  ', program: '  BS Nursing  ', year_level: 2,
      section: '  A  ', department: '  College of Nursing  ', position: null,
      employment_status: null, ...person,
    },
    health: {
      blood_type: '  O+  ', allergies: null, chronic_conditions: null,
      emergency_contact_name: null, emergency_contact_phone: null,
      emergency_contact_relationship: null, ...health,
    },
  };
}

describe('profileCardFromSummary', () => {
  it('maps and trims student identity and rows in portal order', () => {
    expect(profileCardFromSummary(summary({}, { allergies: '  Penicillin  ' }))).toEqual({
      caption: 'Student Profile', name: 'Alex Rivera', idNumber: '2026-001',
      rows: [
        { label: 'Email', value: 'alex@university.test' },
        { label: 'Course', value: 'BS Nursing' },
        { label: 'Year level', value: 'Year 2' },
        { label: 'Section', value: 'A' },
        { label: 'Department', value: 'College of Nursing' },
        { label: 'Blood type', value: 'O+' },
        { label: 'Allergies', value: 'Penicillin', warning: true },
      ],
    });
  });

  it('uses employee rows instead of academic rows and formats employment status', () => {
    expect(profileCardFromSummary(summary({
      kind: 'employee', identifier: ' EMP-42 ', department: ' Human Resources ',
      position: ' Office Assistant ', employment_status: ' part_TIME ',
    }))).toEqual({
      caption: 'Employee Profile', name: 'Alex Rivera', idNumber: 'EMP-42',
      rows: [
        { label: 'Email', value: 'alex@university.test' },
        { label: 'Department', value: 'Human Resources' },
        { label: 'Position', value: 'Office Assistant' },
        { label: 'Status', value: 'Part Time' },
        { label: 'Blood type', value: 'O+' },
      ],
    });
  });

  it.each([null, '', '   '])('uses student fallbacks and omits optional blank rows (%p)', missing => {
    expect(profileCardFromSummary(summary({
      name: '   ', identifier: missing, email: missing, program: missing,
      year_level: missing, section: missing, department: missing,
    }, { blood_type: missing, allergies: missing }))).toEqual({
      caption: 'Student Profile', name: 'University Member', idNumber: '—',
      rows: [
        { label: 'Email', value: 'N/A' },
        { label: 'Course', value: 'N/A' },
        { label: 'Year level', value: 'N/A' },
        { label: 'Blood type', value: 'Not Recorded' },
      ],
    });
  });

  it.each([null, '', '   '])('uses employee defaults for missing employment details (%p)', missing => {
    expect(profileCardFromSummary(summary({
      kind: 'employee', email: missing, department: missing,
      position: missing, employment_status: missing,
    }, { blood_type: missing, allergies: missing })).rows).toEqual([
      { label: 'Email', value: 'N/A' },
      { label: 'Department', value: 'Foundation University' },
      { label: 'Position', value: 'Staff' },
      { label: 'Status', value: 'Active' },
      { label: 'Blood type', value: 'Not Recorded' },
    ]);
  });

  it.each([3, ' 3 ', 0])('preserves a present numeric or string year level (%p)', year_level => {
    const card = profileCardFromSummary(summary({ year_level }));
    expect(card.rows.find(row => row.label === 'Year level')).toEqual({
      label: 'Year level', value: `Year ${String(year_level).trim()}`,
    });
  });

  it('uses the student mapping when person kind is not recorded', () => {
    const card = profileCardFromSummary(summary({ kind: null }));
    expect(card.caption).toBe('Student Profile');
    expect(card.rows.map(row => row.label)).toEqual([
      'Email', 'Course', 'Year level', 'Section', 'Department', 'Blood type',
    ]);
  });

  it.each(['Peanuts', '  Latex, shellfish  '])('flags only the populated allergy row as a warning (%p)', allergies => {
    const card = profileCardFromSummary(summary({}, { allergies }));
    expect(card.rows.filter(row => row.warning)).toEqual([
      { label: 'Allergies', value: allergies.trim(), warning: true },
    ]);
  });
});
