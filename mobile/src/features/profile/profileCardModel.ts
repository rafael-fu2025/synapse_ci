import { HealthSummary } from '../../api/endpoints/health';
import type { ProfileCardRow } from '../../components/ProfileCard';

const NOT_AVAILABLE = 'N/A';

function present(value: string | number | null | undefined): string | null {
  if (value === null || value === undefined) {
    return null;
  }
  const text = String(value).trim();
  return text === '' ? null : text;
}

function titleCase(value: string): string {
  return value
    .replace(/_/g, ' ')
    .replace(/\w\S*/g, (word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase());
}

/** Same fields, order and fallbacks as the web portal's profile card (portal/index.php). */
export function profileCardFromSummary(summary: HealthSummary): {
  caption: string;
  name: string;
  idNumber: string;
  rows: ProfileCardRow[];
} {
  const { person, health } = summary;
  const isEmployee = person.kind === 'employee';
  const rows: ProfileCardRow[] = [{ label: 'Email', value: present(person.email) ?? NOT_AVAILABLE }];

  if (isEmployee) {
    rows.push(
      { label: 'Department', value: present(person.department) ?? 'Foundation University' },
      { label: 'Position', value: present(person.position) ?? 'Staff' },
      { label: 'Status', value: titleCase(present(person.employment_status) ?? 'Active') },
    );
  } else {
    const year = present(person.year_level);
    rows.push(
      { label: 'Course', value: present(person.program) ?? NOT_AVAILABLE },
      { label: 'Year level', value: year ? `Year ${year}` : NOT_AVAILABLE },
    );
    const section = present(person.section);
    if (section) {
      rows.push({ label: 'Section', value: section });
    }
    const department = present(person.department);
    if (department) {
      rows.push({ label: 'Department', value: department });
    }
  }

  rows.push({ label: 'Blood type', value: present(health.blood_type) ?? 'Not Recorded' });
  const allergies = present(health.allergies);
  if (allergies) {
    rows.push({ label: 'Allergies', value: allergies, warning: true });
  }

  return {
    caption: isEmployee ? 'Employee Profile' : 'Student Profile',
    name: present(person.name) ?? 'University Member',
    idNumber: present(person.identifier) ?? '—',
    rows,
  };
}
