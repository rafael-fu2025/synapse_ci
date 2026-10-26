import { useMemo, useState, type ReactNode } from 'react'
import {
  Activity,
  AlertCircle,
  ArrowLeft,
  Bell,
  BookOpenText,
  Building2,
  CalendarDays,
  Camera,
  Check,
  CheckCircle2,
  ChevronDown,
  ChevronRight,
  CircleHelp,
  ClipboardCheck,
  Clock3,
  FileHeart,
  HeartPulse,
  Home,
  Info,
  KeyRound,
  ListChecks,
  LockKeyhole,
  LogOut,
  MapPin,
  Menu,
  MessageSquareText,
  Minus,
  Moon,
  MoreHorizontal,
  RefreshCw,
  Search,
  Settings,
  ShieldCheck,
  Sparkles,
  Stethoscope,
  User,
  UserRound,
  UsersRound,
  WifiOff,
  X,
  XCircle,
  type LucideIcon,
} from 'lucide-react'

type ScreenId =
  | 'splash'
  | 'sign-in'
  | 'sign-in-error'
  | 'session-expired'
  | 'access-restricted'
  | 'dashboard'
  | 'staff-personal-mode'
  | 'appointments'
  | 'appointments-filtered-empty'
  | 'appointment-detail'
  | 'appointment-requested'
  | 'appointment-checked-in'
  | 'booking-unit'
  | 'booking-details'
  | 'booking-slots'
  | 'booking-review'
  | 'booking-success'
  | 'cancel-appointment'
  | 'queue-active'
  | 'queue-multiple'
  | 'queue-empty'
  | 'announcements'
  | 'announcement-detail'
  | 'surveys'
  | 'survey-intro'
  | 'survey-form'
  | 'survey-success'
  | 'notifications'
  | 'notification-history'
  | 'profile'
  | 'health-profile'
  | 'profile-photo'
  | 'records'
  | 'record-detail'
  | 'settings'
  | 'privacy-security'
  | 'appearance-dark'
  | 'offline'

type ScreenDefinition = {
  id: ScreenId
  label: string
  group: string
}

const screenGroups: { label: string; screens: ScreenDefinition[] }[] = [
  {
    label: 'Access',
    screens: [
      { id: 'splash', label: 'Launch screen', group: 'Access' },
      { id: 'sign-in', label: 'MIS sign in', group: 'Access' },
      { id: 'sign-in-error', label: 'Sign-in error', group: 'Access' },
      { id: 'session-expired', label: 'Session expired', group: 'Access' },
      { id: 'access-restricted', label: 'Access restricted', group: 'Access' },
    ],
  },
  {
    label: 'Home',
    screens: [
      { id: 'dashboard', label: 'Dashboard', group: 'Home' },
      { id: 'staff-personal-mode', label: 'Staff personal mode', group: 'Home' },
    ],
  },
  {
    label: 'Appointments',
    screens: [
      { id: 'appointments', label: 'Appointments', group: 'Appointments' },
      { id: 'appointments-filtered-empty', label: 'No filter results', group: 'Appointments' },
      { id: 'appointment-detail', label: 'Confirmed appointment', group: 'Appointments' },
      { id: 'appointment-requested', label: 'Awaiting confirmation', group: 'Appointments' },
      { id: 'appointment-checked-in', label: 'Checked-in appointment', group: 'Appointments' },
      { id: 'booking-unit', label: 'Choose a service', group: 'Appointments' },
      { id: 'booking-details', label: 'Booking details', group: 'Appointments' },
      { id: 'booking-slots', label: 'Choose a time', group: 'Appointments' },
      { id: 'booking-review', label: 'Review booking', group: 'Appointments' },
      { id: 'booking-success', label: 'Request received', group: 'Appointments' },
      { id: 'cancel-appointment', label: 'Cancel appointment', group: 'Appointments' },
    ],
  },
  {
    label: 'Queue',
    screens: [
      { id: 'queue-active', label: 'Active queue', group: 'Queue' },
      { id: 'queue-multiple', label: 'Multiple queue tickets', group: 'Queue' },
      { id: 'queue-empty', label: 'No active queue', group: 'Queue' },
    ],
  },
  {
    label: 'Engagement',
    screens: [
      { id: 'announcements', label: 'Announcements', group: 'Engagement' },
      { id: 'announcement-detail', label: 'Announcement details', group: 'Engagement' },
      { id: 'surveys', label: 'Surveys', group: 'Engagement' },
      { id: 'survey-intro', label: 'Survey overview', group: 'Engagement' },
      { id: 'survey-form', label: 'Survey questions', group: 'Engagement' },
      { id: 'survey-success', label: 'Survey submitted', group: 'Engagement' },
      { id: 'notifications', label: 'Notifications', group: 'Engagement' },
      { id: 'notification-history', label: 'Notification history', group: 'Engagement' },
    ],
  },
  {
    label: 'Account & records',
    screens: [
      { id: 'profile', label: 'Profile', group: 'Account & records' },
      { id: 'health-profile', label: 'Health profile & flags', group: 'Account & records' },
      { id: 'profile-photo', label: 'Profile photo', group: 'Account & records' },
      { id: 'records', label: 'Personal records', group: 'Account & records' },
      { id: 'record-detail', label: 'Record summary', group: 'Account & records' },
      { id: 'settings', label: 'Settings', group: 'Account & records' },
      { id: 'privacy-security', label: 'Privacy & security', group: 'Account & records' },
      { id: 'appearance-dark', label: 'Dark appearance', group: 'Account & records' },
      { id: 'offline', label: 'Offline state', group: 'Account & records' },
    ],
  },
]

const allScreens = screenGroups.flatMap((group) => group.screens)

const navItems: { id: ScreenId; label: string; icon: LucideIcon }[] = [
  { id: 'dashboard', label: 'Home', icon: Home },
  { id: 'appointments', label: 'Visits', icon: CalendarDays },
  { id: 'queue-active', label: 'Queue', icon: ListChecks },
  { id: 'profile', label: 'Profile', icon: UserRound },
]

function Action({
  children,
  onClick,
  variant = 'primary',
  icon: Icon,
  full = false,
}: {
  children: ReactNode
  onClick?: () => void
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
  icon?: LucideIcon
  full?: boolean
}) {
  return (
    <button className={`action action--${variant}${full ? ' action--full' : ''}`} onClick={onClick}>
      {Icon ? <Icon size={18} strokeWidth={2.2} /> : null}
      <span>{children}</span>
    </button>
  )
}

function IconAction({
  label,
  icon: Icon,
  onClick,
  badge,
}: {
  label: string
  icon: LucideIcon
  onClick?: () => void
  badge?: boolean
}) {
  return (
    <button aria-label={label} className="icon-action" onClick={onClick}>
      <Icon size={20} />
      {badge ? <span className="notification-dot" /> : null}
    </button>
  )
}

function StatusPill({
  children,
  tone = 'neutral',
}: {
  children: ReactNode
  tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info'
}) {
  return <span className={`status status--${tone}`}>{children}</span>
}

function BrandMark({ large = false }: { large?: boolean }) {
  return (
    <div className={`brand-mark${large ? ' brand-mark--large' : ''}`}>
      <img src="/assets/brand/FoundationU.png" alt="" />
    </div>
  )
}

function SynapseLogo({ inverse = false }: { inverse?: boolean }) {
  return (
    <img
      className="synapse-logo"
      src={inverse ? '/assets/brand/synapse-white.png' : '/assets/brand/synapse-maroon.png'}
      alt="SYNAPSE"
    />
  )
}

function PageHeader({
  title,
  subtitle,
  back,
  action,
}: {
  title: string
  subtitle?: string
  back?: () => void
  action?: ReactNode
}) {
  return (
    <div className="page-header">
      <div className="page-header__row">
        {back ? <IconAction label="Go back" icon={ArrowLeft} onClick={back} /> : null}
        <div className="page-header__copy">
          <div className="page-title">{title}</div>
          {subtitle ? <div className="page-subtitle">{subtitle}</div> : null}
        </div>
        {action ? <div className="page-header__action">{action}</div> : null}
      </div>
    </div>
  )
}

function BottomNav({
  active,
  go,
}: {
  active: ScreenId
  go: (id: ScreenId) => void
}) {
  return (
    <div className="bottom-nav">
      {navItems.map((item) => {
        const Icon = item.icon
        const selected =
          item.id === active ||
          (item.id === 'appointments' && active.startsWith('appointment')) ||
          (item.id === 'queue-active' && active.startsWith('queue'))
        return (
          <button
            className={`bottom-nav__item${selected ? ' is-active' : ''}`}
            key={item.id}
            onClick={() => go(item.id)}
          >
            <Icon size={21} strokeWidth={selected ? 2.5 : 2} />
            <span>{item.label}</span>
          </button>
        )
      })}
    </div>
  )
}

function ScreenShell({
  children,
  active,
  go,
  scroll = true,
  nav = true,
  className = '',
}: {
  children: ReactNode
  active: ScreenId
  go: (id: ScreenId) => void
  scroll?: boolean
  nav?: boolean
  className?: string
}) {
  return (
    <div className={`mobile-screen ${className}`}>
      <div className={`screen-content${scroll ? ' screen-content--scroll' : ''}`}>{children}</div>
      {nav ? <BottomNav active={active} go={go} /> : null}
    </div>
  )
}

function DetailRow({
  icon: Icon,
  label,
  value,
  last = false,
}: {
  icon: LucideIcon
  label: string
  value: string
  last?: boolean
}) {
  return (
    <div className={`detail-row${last ? ' detail-row--last' : ''}`}>
      <div className="detail-row__icon">
        <Icon size={18} />
      </div>
      <div>
        <div className="detail-row__label">{label}</div>
        <div className="detail-row__value">{value}</div>
      </div>
    </div>
  )
}

function EmptyState({
  icon: Icon,
  title,
  body,
  action,
  onAction,
}: {
  icon: LucideIcon
  title: string
  body: string
  action?: string
  onAction?: () => void
}) {
  return (
    <div className="empty-state">
      <div className="empty-state__icon">
        <Icon size={30} />
      </div>
      <div className="empty-state__title">{title}</div>
      <div className="empty-state__body">{body}</div>
      {action ? (
        <Action onClick={onAction} variant="secondary">
          {action}
        </Action>
      ) : null}
    </div>
  )
}

function AppCard({
  children,
  onClick,
  className = '',
}: {
  children: ReactNode
  onClick?: () => void
  className?: string
}) {
  return (
    <button className={`app-card ${onClick ? 'app-card--interactive' : ''} ${className}`} onClick={onClick}>
      {children}
    </button>
  )
}

function TextField({
  label,
  value,
  placeholder,
  secure,
}: {
  label: string
  value?: string
  placeholder?: string
  secure?: boolean
}) {
  return (
    <div className="field">
      <div className="field__label">{label}</div>
      <div className={`field__control${value ? ' has-value' : ''}`}>
        <span>{secure && value ? '••••••••••' : value || placeholder}</span>
      </div>
    </div>
  )
}

function AccessRestricted({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="access-restricted" go={go} nav={false} className="centered-screen">
      <EmptyState
        icon={ShieldCheck}
        title="This service is restricted"
        body="Your account is not authorized for this department service. Portal access is based on your university role, not the device you use."
      />
      <div className="notice notice--warning"><Info size={18} /><span>If you believe this is a mistake, contact your system administrator.</span></div>
      <div className="bottom-actions">
        <Action full onClick={() => go('dashboard')}>Return to personal portal</Action>
        <Action full variant="ghost" onClick={() => go('sign-in')}>Use another account</Action>
      </div>
    </ScreenShell>
  )
}

function StaffPersonalMode({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="staff-personal-mode" go={go}>
      <PageHeader title="Personal health portal" subtitle="Employee self-service" />
      <div className="staff-mode-banner">
        <div className="staff-mode-banner__icon"><UserRound size={24} /></div>
        <div><strong>Staff member personal session</strong><span>You are viewing your individual employee health account, not a departmental workstation.</span></div>
      </div>
      <div className="section-title">Switch to an authorized console</div>
      <div className="console-list">
        <button><img src="/assets/brand/health-services.png" alt="" /><span><strong>Clinic staff console</strong><small>Patient care and clinic operations</small></span><ChevronRight size={18} /></button>
        <button><img src="/assets/brand/guidance-center.png" alt="" /><span><strong>Guidance console</strong><small>Counseling and student support</small></span><ChevronRight size={18} /></button>
      </div>
      <div className="notice"><ShieldCheck size={18} /><span>Department consoles use separate authorization and may require you to sign in again.</span></div>
      <div className="section-title section-title--spaced">Continue in personal mode</div>
      <div className="quick-grid">
        <button className="quick-link" onClick={() => go('appointments')}><span className="quick-link__icon"><CalendarDays size={20} /></span><span>My visits</span><ChevronRight size={16} /></button>
        <button className="quick-link" onClick={() => go('health-profile')}><span className="quick-link__icon quick-link__icon--gold"><FileHeart size={20} /></span><span>Health profile</span><ChevronRight size={16} /></button>
      </div>
    </ScreenShell>
  )
}

function Dashboard({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="dashboard" go={go}>
      <div className="dashboard-header">
        <div>
          <div className="eyebrow">TUESDAY, 27 OCTOBER</div>
          <div className="welcome-title">Good morning, Rafael</div>
          <div className="muted-copy">How are you feeling today?</div>
        </div>
        <div className="header-actions">
          <IconAction label="Notifications" icon={Bell} badge onClick={() => go('notifications')} />
          <button className="avatar avatar--small" onClick={() => go('profile')}>RU</button>
        </div>
      </div>

      <AppCard className="next-visit-card" onClick={() => go('appointment-detail')}>
        <div className="card-kicker">
          <span>NEXT APPOINTMENT</span>
          <StatusPill tone="success">Confirmed</StatusPill>
        </div>
        <div className="next-visit-card__date">
          <div className="date-tile">
            <span>OCT</span>
            <strong>29</strong>
          </div>
          <div>
            <div className="card-title">Clinic consultation</div>
            <div className="meta-line"><Clock3 size={15} /> 10:30 AM · University Clinic</div>
            <div className="meta-line"><Stethoscope size={15} /> Dr. Maria Santos</div>
          </div>
        </div>
        <div className="card-link">View appointment <ChevronRight size={16} /></div>
      </AppCard>

      <div className="section-heading">
        <div>
          <div className="section-title">Quick access</div>
          <div className="section-caption">What would you like to do?</div>
        </div>
      </div>
      <div className="quick-grid">
        {[
          { icon: CalendarDays, label: 'Book a visit', id: 'booking-unit' as ScreenId, tone: 'teal' },
          { icon: ListChecks, label: 'Queue status', id: 'queue-active' as ScreenId, tone: 'gold' },
          { icon: FileHeart, label: 'My records', id: 'records' as ScreenId, tone: 'blue' },
          { icon: MessageSquareText, label: 'Surveys', id: 'surveys' as ScreenId, tone: 'violet' },
        ].map((item) => {
          const Icon = item.icon
          return (
            <button className="quick-link" key={item.label} onClick={() => go(item.id)}>
              <span className={`quick-link__icon quick-link__icon--${item.tone}`}><Icon size={21} /></span>
              <span>{item.label}</span>
              <ChevronRight size={16} />
            </button>
          )
        })}
      </div>

      <div className="section-heading">
        <div className="section-title">Latest updates</div>
        <button className="text-link" onClick={() => go('announcements')}>See all</button>
      </div>
      <AppCard className="news-card" onClick={() => go('announcement-detail')}>
        <div className="news-card__icon"><Sparkles size={21} /></div>
        <div className="news-card__copy">
          <StatusPill tone="info">Health advisory</StatusPill>
          <div className="card-title card-title--small">Free seasonal flu vaccination</div>
          <div className="card-body clamp-2">Protect yourself and the community. Vaccination is available this week at the University Clinic.</div>
          <div className="card-time">2 hours ago</div>
        </div>
      </AppCard>
    </ScreenShell>
  )
}

function SignIn({ go, error = false }: { go: (id: ScreenId) => void; error?: boolean }) {
  return (
    <ScreenShell active={error ? 'sign-in-error' : 'sign-in'} go={go} nav={false} className="auth-screen">
      <div className="auth-hero">
        <SynapseLogo />
        <div className="brand-tagline">University health, guidance, and wellness services.</div>
      </div>
      <div className="auth-card">
        <div className="auth-title">Welcome back</div>
        <div className="auth-subtitle">Sign in using your university MIS account.</div>
        {error ? <div className="auth-error"><AlertCircle size={17} /><span><strong>Unable to sign in</strong>Check your ID number and password, then try again.</span></div> : null}
        <TextField label="ID Number" value={error ? '2023-001234' : undefined} placeholder="Enter your ID number" />
        <TextField label="Password" value={error ? 'password' : undefined} placeholder="Enter your password" secure />
        <button className="forgot-link">Trouble signing in? Contact your administrator.</button>
        <Action full onClick={() => go('dashboard')}>Sign in securely</Action>
        <div className="secure-note"><ShieldCheck size={17} /> Your credentials are never stored on this device.</div>
      </div>
      <div className="auth-footer">
        <img src="/assets/brand/guidance-center.png" alt="Guidance Center" />
        <img src="/assets/brand/FoundationU.png" alt="Foundation University" />
        <img src="/assets/brand/health-services.png" alt="Health Services" />
      </div>
    </ScreenShell>
  )
}

function Splash({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="splash" go={go} nav={false} scroll={false} className="splash-screen">
      <div className="splash-pattern splash-pattern--one" />
      <div className="splash-pattern splash-pattern--two" />
      <div className="splash-center">
        <SynapseLogo inverse />
        <div className="splash-tagline">University health, guidance, and wellness services.</div>
      </div>
      <div className="splash-footer">
        <div className="loading-line"><span /></div>
        <button onClick={() => go('sign-in')}>Tap to continue</button>
        <span>Foundation University</span>
      </div>
    </ScreenShell>
  )
}

function SessionExpired({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="session-expired" go={go} nav={false} className="centered-screen">
      <EmptyState
        icon={LockKeyhole}
        title="Your session has ended"
        body="For your privacy, we signed you out after a period of inactivity. Your information remains secure."
      />
      <div className="bottom-actions">
        <Action full icon={KeyRound} onClick={() => go('sign-in')}>Sign in again</Action>
      </div>
    </ScreenShell>
  )
}

function Appointments({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="appointments" go={go}>
      <PageHeader
        title="Appointments"
        subtitle="Your clinic and guidance visits"
        action={<IconAction label="More options" icon={MoreHorizontal} />}
      />
      <div className="segmented-control">
        <button className="is-active">Upcoming <span>2</span></button>
        <button>Past</button>
        <button>Cancelled</button>
      </div>
      <div className="list-date-label">THIS WEEK</div>
      <AppCard className="appointment-card" onClick={() => go('appointment-detail')}>
        <div className="appointment-card__top">
          <div className="service-icon service-icon--clinic"><Stethoscope size={22} /></div>
          <div className="appointment-card__copy">
            <div className="card-title">Clinic consultation</div>
            <div className="card-body">University Clinic</div>
          </div>
          <StatusPill tone="success">Confirmed</StatusPill>
        </div>
        <div className="appointment-card__schedule">
          <span><CalendarDays size={16} /> Thu, 29 Oct</span>
          <span><Clock3 size={16} /> 10:30 AM</span>
        </div>
        <div className="appointment-card__footer">
          <span>Dr. Maria Santos</span>
          <ChevronRight size={18} />
        </div>
      </AppCard>
      <div className="list-date-label">NEXT WEEK</div>
      <AppCard className="appointment-card">
        <div className="appointment-card__top">
          <div className="service-icon service-icon--guidance"><MessageSquareText size={22} /></div>
          <div className="appointment-card__copy">
            <div className="card-title">Guidance session</div>
            <div className="card-body">Guidance Center</div>
          </div>
          <StatusPill tone="warning">Pending</StatusPill>
        </div>
        <div className="appointment-card__schedule">
          <span><CalendarDays size={16} /> Tue, 3 Nov</span>
          <span><Clock3 size={16} /> 2:00 PM</span>
        </div>
        <div className="appointment-card__footer">
          <span>Assigned counselor</span>
          <ChevronRight size={18} />
        </div>
      </AppCard>
      <div className="floating-action">
        <Action icon={CalendarDays} onClick={() => go('booking-unit')}>Book appointment</Action>
      </div>
    </ScreenShell>
  )
}

function AppointmentsFilteredEmpty({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="appointments-filtered-empty" go={go}>
      <PageHeader title="Appointments" subtitle="Your clinic and guidance visits" />
      <div className="filter-summary">
        <button>Guidance & Counseling <X size={13} /></button>
        <button>Completed <X size={13} /></button>
      </div>
      <EmptyState
        icon={Search}
        title="No appointments match"
        body="Try changing or clearing the service and status filters."
        action="Clear all filters"
        onAction={() => go('appointments')}
      />
    </ScreenShell>
  )
}

function AppointmentState({
  go,
  checkedIn = false,
}: {
  go: (id: ScreenId) => void
  checkedIn?: boolean
}) {
  const active = checkedIn ? 'appointment-checked-in' : 'appointment-requested'
  return (
    <ScreenShell active={active} go={go}>
      <PageHeader title="Appointment details" back={() => go('appointments')} />
      <div className="detail-hero">
        <div className={`service-icon ${checkedIn ? 'service-icon--clinic' : 'service-icon--guidance'} service-icon--large`}>
          {checkedIn ? <Stethoscope size={26} /> : <MessageSquareText size={26} />}
        </div>
        <StatusPill tone={checkedIn ? 'info' : 'warning'}>{checkedIn ? 'In Queue' : 'Awaiting confirmation'}</StatusPill>
        <div className="detail-hero__title">{checkedIn ? 'University Clinic' : 'Guidance & Counseling'}</div>
        <div className="detail-hero__subtitle">Reference #{checkedIn ? '1048' : '1052'}</div>
      </div>
      <div className={`state-message ${checkedIn ? 'state-message--info' : 'state-message--warning'}`}>
        {checkedIn ? <ListChecks size={21} /> : <Clock3 size={21} />}
        <div>
          <strong>{checkedIn ? 'Checked in—please wait for your turn' : 'This is a preferred time, not yet confirmed'}</strong>
          <span>{checkedIn ? 'Your live waiting room ticket is ready.' : 'The department will review your request and notify you when a time is confirmed.'}</span>
        </div>
      </div>
      <AppCard className="detail-card">
        <DetailRow icon={CalendarDays} label="Preferred date" value={checkedIn ? 'Thursday, 29 October 2026' : 'Tuesday, 3 November 2026'} />
        <DetailRow icon={Clock3} label={checkedIn ? 'Confirmed time' : 'Preferred time'} value={checkedIn ? '10:30 AM – 11:00 AM' : '2:00 PM'} />
        <DetailRow icon={UserRound} label="Assigned staff" value={checkedIn ? 'Dr. Maria Santos' : 'Not yet assigned'} />
        <DetailRow icon={FileHeart} label="Type" value={checkedIn ? 'General consultation' : 'Personal counseling'} last />
      </AppCard>
      {checkedIn ? (
        <div className="bottom-actions"><Action full onClick={() => go('queue-active')}>View live queue ticket</Action></div>
      ) : (
        <div className="bottom-actions"><Action full variant="danger" onClick={() => go('cancel-appointment')}>Cancel request</Action></div>
      )}
    </ScreenShell>
  )
}

function AppointmentDetail({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="appointment-detail" go={go}>
      <PageHeader title="Appointment details" back={() => go('appointments')} action={<IconAction label="More options" icon={MoreHorizontal} />} />
      <div className="detail-hero">
        <div className="service-icon service-icon--clinic service-icon--large"><Stethoscope size={26} /></div>
        <StatusPill tone="success">Confirmed</StatusPill>
        <div className="detail-hero__title">Clinic consultation</div>
        <div className="detail-hero__subtitle">Reference #CL-2026-1029</div>
      </div>
      <AppCard className="detail-card">
        <DetailRow icon={CalendarDays} label="Date" value="Thursday, 29 October 2026" />
        <DetailRow icon={Clock3} label="Time" value="10:30 AM – 11:00 AM" />
        <DetailRow icon={Stethoscope} label="Healthcare professional" value="Dr. Maria Santos" />
        <DetailRow icon={MapPin} label="Location" value="University Clinic · Main Building" last />
      </AppCard>
      <div className="notice notice--info"><Info size={19} /><span>Please arrive 10 minutes early and bring your university ID.</span></div>
      <div className="section-title">Reason for visit</div>
      <AppCard className="text-card">General consultation and recurring headache.</AppCard>
      <div className="stacked-actions">
        <Action full variant="secondary" icon={CalendarDays}>Add to calendar</Action>
        <Action full variant="ghost" onClick={() => go('cancel-appointment')}>Cancel appointment</Action>
      </div>
    </ScreenShell>
  )
}

function ProgressSteps({ current }: { current: number }) {
  return (
    <div className="progress-steps">
      {[1, 2, 3, 4].map((step) => (
        <span key={step} className={step <= current ? 'is-complete' : ''} />
      ))}
    </div>
  )
}

function BookingUnit({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="booking-unit" go={go} nav={false}>
      <PageHeader title="Book an appointment" subtitle="Step 1 of 4" back={() => go('appointments')} />
      <ProgressSteps current={1} />
      <div className="form-heading">What type of support do you need?</div>
      <div className="form-caption">Choose the service that best matches your needs.</div>
      <button className="service-choice is-selected" onClick={() => go('booking-details')}>
        <div className="service-icon service-icon--clinic service-icon--large service-icon--logo"><img src="/assets/brand/health-services.png" alt="" /></div>
        <div>
          <div className="service-choice__title">Clinic</div>
          <div className="service-choice__body">Medical consultation, follow-up care, or health concerns.</div>
          <div className="service-choice__availability"><CheckCircle2 size={15} /> Earliest: Tomorrow</div>
        </div>
        <ChevronRight size={20} />
      </button>
      <button className="service-choice" onClick={() => go('booking-details')}>
        <div className="service-icon service-icon--guidance service-icon--large service-icon--logo"><img src="/assets/brand/guidance-center.png" alt="" /></div>
        <div>
          <div className="service-choice__title">Guidance & counseling</div>
          <div className="service-choice__body">Personal, academic, career, or wellbeing support.</div>
          <div className="service-choice__availability"><CheckCircle2 size={15} /> Earliest: 30 October</div>
        </div>
        <ChevronRight size={20} />
      </button>
      <div className="notice"><ShieldCheck size={19} /><span>Your appointment details are private and visible only to authorized staff.</span></div>
    </ScreenShell>
  )
}

function BookingDetails({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="booking-details" go={go} nav={false}>
      <PageHeader title="Appointment details" subtitle="Step 2 of 4" back={() => go('booking-unit')} />
      <ProgressSteps current={2} />
      <div className="selected-service-bar">
        <div className="service-icon service-icon--clinic"><Stethoscope size={20} /></div>
        <div><span>Service</span><strong>Clinic consultation</strong></div>
        <button onClick={() => go('booking-unit')}>Change</button>
      </div>
      <div className="field">
        <div className="field__label">Preferred healthcare professional</div>
        <div className="field__control field__control--select">
          <span>Any available professional</span><ChevronDown size={18} />
        </div>
      </div>
      <div className="field">
        <div className="field__label">Reason for visit</div>
        <div className="field__control field__control--textarea">
          <span>General consultation and recurring headache.</span>
          <small>54 / 300</small>
        </div>
      </div>
      <div className="notice notice--subtle"><Info size={18} /><span>Do not include emergency or highly sensitive information here.</span></div>
      <div className="bottom-actions">
        <Action full onClick={() => go('booking-slots')}>Continue to date & time</Action>
      </div>
    </ScreenShell>
  )
}

function BookingSlots({ go }: { go: (id: ScreenId) => void }) {
  const [selected, setSelected] = useState('10:30 AM')
  return (
    <ScreenShell active="booking-slots" go={go} nav={false}>
      <PageHeader title="Choose date & time" subtitle="Step 3 of 4" back={() => go('booking-details')} />
      <ProgressSteps current={3} />
      <div className="calendar-strip">
        {[
          ['WED', '28', false],
          ['THU', '29', true],
          ['FRI', '30', false],
          ['MON', '02', false],
          ['TUE', '03', false],
        ].map(([day, date, active]) => (
          <button className={active ? 'is-active' : ''} key={`${day}${date}`}>
            <span>{day}</span><strong>{date}</strong>
          </button>
        ))}
      </div>
      <div className="slots-heading">
        <div>
          <div className="section-title">Available times</div>
          <div className="section-caption">Thursday, 29 October</div>
        </div>
        <StatusPill tone="success">6 slots</StatusPill>
      </div>
      <div className="day-period"><span>Morning</span><span className="line" /></div>
      <div className="time-grid">
        {['8:30 AM', '9:00 AM', '9:30 AM', '10:30 AM', '11:00 AM', '11:30 AM'].map((time) => (
          <button className={selected === time ? 'is-selected' : ''} key={time} onClick={() => setSelected(time)}>
            {selected === time ? <Check size={15} /> : null}{time}
          </button>
        ))}
      </div>
      <div className="day-period"><span>Afternoon</span><span className="line" /></div>
      <div className="time-grid">
        {['1:30 PM', '2:00 PM', '3:30 PM'].map((time) => (
          <button className={selected === time ? 'is-selected' : ''} key={time} onClick={() => setSelected(time)}>{time}</button>
        ))}
      </div>
      <div className="bottom-actions"><Action full onClick={() => go('booking-review')}>Review appointment</Action></div>
    </ScreenShell>
  )
}

function BookingReview({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="booking-review" go={go} nav={false}>
      <PageHeader title="Review appointment" subtitle="Step 4 of 4" back={() => go('booking-slots')} />
      <ProgressSteps current={4} />
      <div className="review-banner">
        <div className="service-icon service-icon--clinic service-icon--large"><Stethoscope size={25} /></div>
        <div><div className="card-title">Clinic consultation</div><div className="card-body">University Clinic</div></div>
      </div>
      <AppCard className="detail-card">
        <DetailRow icon={CalendarDays} label="Date" value="Thursday, 29 October 2026" />
        <DetailRow icon={Clock3} label="Time" value="10:30 AM – 11:00 AM" />
        <DetailRow icon={Stethoscope} label="Professional" value="Any available professional" />
        <DetailRow icon={FileHeart} label="Reason" value="General consultation and recurring headache" last />
      </AppCard>
      <div className="notice notice--warning"><AlertCircle size={19} /><span>You can cancel up to 2 hours before your appointment. Repeated no-shows may limit online booking.</span></div>
      <label className="check-row">
        <span className="fake-check"><Check size={14} /></span>
        <span>I confirm that these appointment details are correct.</span>
      </label>
      <div className="bottom-actions"><Action full onClick={() => go('booking-success')}>Submit appointment request</Action></div>
    </ScreenShell>
  )
}

function BookingSuccess({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="booking-success" go={go} nav={false} className="centered-screen">
      <div className="success-ring"><Check size={38} /></div>
      <div className="success-title">Request received</div>
      <div className="success-body">Your preferred clinic time has been sent for review. This is not confirmed yet—we’ll notify you when staff responds.</div>
      <div className="confirmation-ticket">
        <div><span>THU</span><strong>29</strong><small>OCT 2026</small></div>
        <div>
          <div className="card-title">Clinic consultation</div>
          <div className="meta-line"><Clock3 size={15} /> 10:30 AM</div>
          <div className="meta-line"><MapPin size={15} /> University Clinic</div>
        </div>
      </div>
      <div className="stacked-actions stacked-actions--bottom">
        <Action full onClick={() => go('appointment-requested')}>View request status</Action>
        <Action full variant="ghost" onClick={() => go('dashboard')}>Return home</Action>
      </div>
    </ScreenShell>
  )
}

function CancelAppointment({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="cancel-appointment" go={go} nav={false}>
      <PageHeader title="Cancel appointment" back={() => go('appointment-detail')} />
      <div className="warning-illustration"><CalendarDays size={28} /><span><X size={17} /></span></div>
      <div className="form-heading form-heading--center">Are you sure you want to cancel?</div>
      <div className="form-caption form-caption--center">Thursday, 29 October at 10:30 AM<br />Clinic consultation</div>
      <div className="field">
        <div className="field__label">Reason for cancellation</div>
        <div className="field__control field__control--select"><span>Select a reason</span><ChevronDown size={18} /></div>
      </div>
      <div className="notice notice--warning"><AlertCircle size={19} /><span>This time slot may be offered to another person. You’ll need to book again if your plans change.</span></div>
      <div className="bottom-actions">
        <Action full variant="danger" onClick={() => go('appointments')}>Yes, cancel appointment</Action>
        <Action full variant="ghost" onClick={() => go('appointment-detail')}>Keep appointment</Action>
      </div>
    </ScreenShell>
  )
}

function QueueActive({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="queue-active" go={go}>
      <PageHeader title="Queue status" subtitle="Live updates from your current visit" action={<IconAction label="Refresh" icon={RefreshCw} />} />
      <div className="queue-card">
        <div className="queue-card__top"><span>CLINIC QUEUE</span><span className="live-status"><i /> LIVE</span></div>
        <div className="queue-number"><span>Your number</span><strong>C-014</strong></div>
        <div className="queue-progress">
          <div className="queue-progress__track"><span /></div>
          <div className="queue-progress__labels"><span>Checked in</span><span>Almost there</span></div>
        </div>
        <div className="queue-stats">
          <div><strong>2</strong><span>people ahead</span></div>
          <div><strong>~12</strong><span>minutes wait</span></div>
        </div>
      </div>
      <div className="now-serving">
        <div className="now-serving__icon"><Activity size={23} /></div>
        <div><span>NOW SERVING</span><strong>C-012</strong></div>
        <StatusPill tone="info">Room 2</StatusPill>
      </div>
      <AppCard className="detail-card">
        <DetailRow icon={MapPin} label="Waiting area" value="University Clinic · Ground floor" />
        <DetailRow icon={Clock3} label="Checked in" value="10:08 AM" last />
      </AppCard>
      <div className="notice notice--info"><Bell size={19} /><span>Keep this app nearby. We’ll notify you when you’re next.</span></div>
      <div className="last-updated">Updated just now</div>
    </ScreenShell>
  )
}

function QueueMultiple({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="queue-multiple" go={go}>
      <PageHeader title="My waiting room" subtitle="2 active tickets today" action={<IconAction label="Refresh" icon={RefreshCw} />} />
      <div className="queue-ticket queue-ticket--primary">
        <div><StatusPill>Live waiting room ticket</StatusPill><strong>C-014</strong><span>University Clinic · Waiting</span></div>
        <div><small>PRIORITY</small><b>Normal</b></div>
      </div>
      <div className="queue-ticket queue-ticket--guidance">
        <div><StatusPill>Live waiting room ticket</StatusPill><strong>G-006</strong><span>Guidance & Counseling · Called</span></div>
        <div><small>PRIORITY</small><b>Normal</b></div>
      </div>
      <div className="notice notice--info"><Info size={19} /><span>Each department manages its own queue. Select a ticket to see its current status.</span></div>
      <div className="section-title">Today’s appointments</div>
      <button className="menu-row" onClick={() => go('appointment-checked-in')}><span className="menu-row__icon"><Stethoscope size={20} /></span><span><strong>Clinic consultation</strong><small>Checked in at 10:08 AM</small></span><ChevronRight size={18} /></button>
      <button className="menu-row"><span className="menu-row__icon"><MessageSquareText size={20} /></span><span><strong>Guidance follow-up</strong><small>Checked in at 1:42 PM</small></span><ChevronRight size={18} /></button>
    </ScreenShell>
  )
}

function QueueEmpty({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="queue-empty" go={go}>
      <PageHeader title="Queue status" />
      <EmptyState
        icon={ListChecks}
        title="You’re not in a queue"
        body="When you check in for a clinic or guidance visit, your live position will appear here."
        action="View appointments"
        onAction={() => go('appointments')}
      />
      <div className="info-card">
        <div className="info-card__icon"><CircleHelp size={21} /></div>
        <div><strong>How check-in works</strong><span>Arrive at your service location and show your university ID. Staff will add you to the queue.</span></div>
      </div>
    </ScreenShell>
  )
}

function Announcements({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="announcements" go={go}>
      <PageHeader title="Announcements" subtitle="Campus health and wellbeing updates" action={<IconAction label="Search" icon={Search} />} />
      <div className="filter-chips">
        <button className="is-active">All</button><button>Health</button><button>Guidance</button><button>Campus</button>
      </div>
      <div className="featured-story" onClick={() => go('announcement-detail')}>
        <div className="featured-story__art"><HeartPulse size={44} /><span>WELLNESS WEEK</span></div>
        <div className="featured-story__body">
          <StatusPill tone="info">Featured</StatusPill>
          <div className="card-title">Your wellbeing matters: Wellness Week 2026</div>
          <div className="card-body clamp-2">Join a week of free activities, health checks, and guided conversations.</div>
          <div className="card-time">Today · 8:30 AM</div>
        </div>
      </div>
      <div className="list-date-label">LATEST</div>
      {[
        ['Free seasonal flu vaccination', 'Protect yourself and the community. Available this week at the University Clinic.', 'Health advisory', '2h'],
        ['Guidance Center schedule update', 'Extended counseling hours are available during midterm week.', 'Guidance', 'Yesterday'],
        ['Campus blood donation drive', 'Register to donate on 6 November at the University Gym.', 'Campus', '24 Oct'],
      ].map((item, index) => (
        <button className="story-row" key={item[0]} onClick={() => index === 0 && go('announcement-detail')}>
          <div className={`story-row__art story-row__art--${index + 1}`}><BookOpenText size={22} /></div>
          <div>
            <div className="story-row__meta">{item[2]} · {item[3]}</div>
            <div className="story-row__title">{item[0]}</div>
            <div className="story-row__body">{item[1]}</div>
          </div>
          {index === 0 ? <span className="unread-mark" /> : null}
        </button>
      ))}
    </ScreenShell>
  )
}

function AnnouncementDetail({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="announcement-detail" go={go}>
      <PageHeader title="Announcement" back={() => go('announcements')} action={<IconAction label="More options" icon={MoreHorizontal} />} />
      <div className="article-hero"><HeartPulse size={48} /><span>STAY PROTECTED</span></div>
      <article className="article">
        <StatusPill tone="info">Health advisory</StatusPill>
        <div className="article__title">Free seasonal flu vaccination</div>
        <div className="article__meta">University Clinic · 27 October 2026</div>
        <div className="article__lead">Protect yourself and the campus community this flu season.</div>
        <p>Free flu vaccinations are available to all currently enrolled students and active employees from 28–30 October.</p>
        <div className="article__callout">
          <div><CalendarDays size={19} /><span><strong>28–30 October</strong>8:30 AM – 4:00 PM</span></div>
          <div><MapPin size={19} /><span><strong>University Clinic</strong>Main Building, Ground Floor</span></div>
        </div>
        <p>Please bring your university ID. Vaccinations are provided on a first-come, first-served basis while supplies last.</p>
      </article>
      <div className="bottom-actions"><Action full onClick={() => go('booking-unit')}>Book a clinic visit</Action></div>
    </ScreenShell>
  )
}

function Surveys({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="surveys" go={go}>
      <PageHeader title="Surveys" subtitle="Your voice helps improve campus care" />
      <div className="survey-summary">
        <div className="survey-summary__icon"><ClipboardCheck size={25} /></div>
        <div><strong>2 surveys need your response</strong><span>About 6 minutes total</span></div>
      </div>
      <div className="list-date-label">AVAILABLE</div>
      <AppCard className="survey-card" onClick={() => go('survey-intro')}>
        <div className="survey-card__top">
          <div className="service-icon service-icon--guidance"><MessageSquareText size={21} /></div>
          <StatusPill tone="warning">Due in 3 days</StatusPill>
        </div>
        <div className="card-title">Student wellbeing pulse check</div>
        <div className="card-body">Help us understand how students are feeling this semester.</div>
        <div className="survey-card__meta"><span><Clock3 size={15} /> 3 minutes</span><span><ListChecks size={15} /> 8 questions</span></div>
        <div className="card-link">Start survey <ChevronRight size={16} /></div>
      </AppCard>
      <AppCard className="survey-card">
        <div className="survey-card__top">
          <div className="service-icon service-icon--clinic"><Stethoscope size={21} /></div>
          <StatusPill tone="neutral">Optional</StatusPill>
        </div>
        <div className="card-title">Clinic service experience</div>
        <div className="card-body">Tell us about your recent visit to the University Clinic.</div>
        <div className="survey-card__meta"><span><Clock3 size={15} /> 2 minutes</span><span><ListChecks size={15} /> 5 questions</span></div>
        <div className="card-link">Start survey <ChevronRight size={16} /></div>
      </AppCard>
      <div className="list-date-label">COMPLETED</div>
      <div className="completed-row"><CheckCircle2 size={20} /><div><strong>Campus health needs assessment</strong><span>Submitted 14 October</span></div><ChevronRight size={18} /></div>
    </ScreenShell>
  )
}

function SurveyIntro({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="survey-intro" go={go} nav={false}>
      <PageHeader title="Survey details" back={() => go('surveys')} />
      <div className="survey-intro-hero"><MessageSquareText size={31} /></div>
      <StatusPill tone="warning">Due 30 October</StatusPill>
      <div className="survey-title">Student wellbeing pulse check</div>
      <div className="survey-description">This short survey helps the Guidance Center understand current student wellbeing and plan better support.</div>
      <div className="survey-facts">
        <div><Clock3 size={21} /><span><strong>3 minutes</strong>Estimated time</span></div>
        <div><ListChecks size={21} /><span><strong>8 questions</strong>All are required</span></div>
        <div><ShieldCheck size={21} /><span><strong>Identified response</strong>Protected, but not anonymous</span></div>
      </div>
      <div className="notice notice--warning"><Info size={19} /><span>This survey is identified, not anonymous. Authorized Guidance staff can associate this response with your account.</span></div>
      <div className="bottom-actions"><Action full onClick={() => go('survey-form')}>Begin survey</Action><Action full variant="ghost" onClick={() => go('surveys')}>Maybe later</Action></div>
    </ScreenShell>
  )
}

function SurveyForm({ go }: { go: (id: ScreenId) => void }) {
  const [answer, setAnswer] = useState(3)
  return (
    <ScreenShell active="survey-form" go={go} nav={false}>
      <PageHeader title="Wellbeing pulse check" subtitle="Question 3 of 8" back={() => go('survey-intro')} />
      <div className="survey-progress"><span /></div>
      <div className="question-number">QUESTION 3</div>
      <div className="question-title">Over the past two weeks, how often have you felt overwhelmed by your responsibilities?</div>
      <div className="question-help">Choose the answer that feels most accurate.</div>
      <div className="answer-list">
        {['Not at all', 'Several days', 'More than half the days', 'Nearly every day'].map((label, index) => (
          <button className={answer === index ? 'is-selected' : ''} key={label} onClick={() => setAnswer(index)}>
            <span className="radio">{answer === index ? <i /> : null}</span>
            <span>{label}</span>
          </button>
        ))}
      </div>
      <div className="survey-privacy"><LockKeyhole size={16} /> Your response is saved securely.</div>
      <div className="bottom-actions bottom-actions--inline">
        <Action variant="secondary" onClick={() => go('survey-intro')}>Previous</Action>
        <Action onClick={() => go('survey-success')}>Next question</Action>
      </div>
    </ScreenShell>
  )
}

function SurveySuccess({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="survey-success" go={go} nav={false} className="centered-screen">
      <div className="success-ring"><Check size={38} /></div>
      <div className="success-title">Thank you for sharing</div>
      <div className="success-body">Your response to “Student wellbeing pulse check” has been submitted securely.</div>
      <div className="submission-card">
        <ShieldCheck size={22} />
        <div><strong>Response received</strong><span>27 October 2026 · 9:42 AM</span></div>
      </div>
      <div className="stacked-actions stacked-actions--bottom"><Action full onClick={() => go('surveys')}>Back to surveys</Action><Action full variant="ghost" onClick={() => go('dashboard')}>Return home</Action></div>
    </ScreenShell>
  )
}

function Notifications({ go }: { go: (id: ScreenId) => void }) {
  const notifications = [
    { icon: CalendarDays, tone: 'teal', title: 'Appointment confirmed', body: 'Your clinic visit on 29 October at 10:30 AM is confirmed.', time: '12 min', unread: true },
    { icon: ListChecks, tone: 'gold', title: 'You’re almost next', body: 'There are 2 people ahead of you in the clinic queue.', time: '38 min', unread: true },
    { icon: MessageSquareText, tone: 'violet', title: 'Survey available', body: 'Student wellbeing pulse check takes about 3 minutes.', time: '2h', unread: true },
    { icon: Sparkles, tone: 'blue', title: 'Free flu vaccination', body: 'Vaccinations are available this week at the University Clinic.', time: '5h', unread: false },
  ]
  return (
    <ScreenShell active="notifications" go={go}>
      <PageHeader title="Notifications" subtitle="3 unread" back={() => go('dashboard')} action={<button className="text-link">Mark all read</button>} />
      <div className="list-date-label">TODAY</div>
      {notifications.map((item, index) => {
        const Icon = item.icon
        return (
          <button className={`notification-row${item.unread ? ' is-unread' : ''}`} key={item.title} onClick={() => index === 0 && go('appointment-detail')}>
            <span className={`notification-row__icon notification-row__icon--${item.tone}`}><Icon size={20} /></span>
            <span className="notification-row__copy"><strong>{item.title}</strong><span>{item.body}</span><small>{item.time} ago</small></span>
            {item.unread ? <i className="unread-mark" /> : null}
          </button>
        )
      })}
      <div className="list-date-label">EARLIER</div>
      <button className="notification-row">
        <span className="notification-row__icon"><CheckCircle2 size={20} /></span>
        <span className="notification-row__copy"><strong>Survey submitted</strong><span>Your response was received successfully.</span><small>14 Oct</small></span>
      </button>
    </ScreenShell>
  )
}

function NotificationHistory({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="notification-history" go={go}>
      <PageHeader title="Notification history" subtitle="Previously read updates" back={() => go('notifications')} />
      <div className="filter-chips"><button className="is-active">All</button><button>Appointments</button><button>Queue</button><button>Campus</button></div>
      <div className="list-date-label">OCTOBER 2026</div>
      {[
        ['Survey submitted', 'Your response was received successfully.', '14 Oct'],
        ['Appointment cancelled', 'Your clinic request for 10 October was cancelled.', '9 Oct'],
        ['Guidance request confirmed', 'Your session is confirmed for 3 October at 2:00 PM.', '1 Oct'],
      ].map((item, index) => (
        <button className="notification-row" key={item[0]}>
          <span className="notification-row__icon">{index === 0 ? <ClipboardCheck size={20} /> : <CalendarDays size={20} />}</span>
          <span className="notification-row__copy"><strong>{item[0]}</strong><span>{item[1]}</span><small>{item[2]}</small></span>
          <ChevronRight size={16} />
        </button>
      ))}
      <EmptyState icon={CheckCircle2} title="You’re all caught up" body="Older notifications are removed according to university retention rules." />
    </ScreenShell>
  )
}

function Profile({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="profile" go={go}>
      <PageHeader title="Profile" action={<IconAction label="Settings" icon={Settings} onClick={() => go('settings')} />} />
      <div className="profile-hero">
        <button className="avatar avatar--large" onClick={() => go('profile-photo')}>RU<span><Camera size={14} /></span></button>
        <div className="profile-hero__name">Rafael Udtohan</div>
        <div className="profile-hero__meta">2023-001234 · Student</div>
        <StatusPill tone="success"><CheckCircle2 size={13} /> Active account</StatusPill>
      </div>
      <div className="profile-section-label">PERSONAL INFORMATION</div>
      <AppCard className="profile-list">
        <div><span>Full name</span><strong>Rafael Fuentes Udtohan</strong></div>
        <div><span>University email</span><strong>rafael.udtohan@foundationu.com</strong></div>
        <div><span>Program</span><strong>BS Information Technology</strong></div>
        <div><span>Year level</span><strong>Third year</strong></div>
      </AppCard>
      <div className="profile-section-label">HEALTH & WELLNESS</div>
      <button className="menu-row" onClick={() => go('health-profile')}><span className="menu-row__icon"><HeartPulse size={20} /></span><span><strong>Health profile & medical flags</strong><small>Blood type, allergies, and emergency details</small></span><ChevronRight size={18} /></button>
      <button className="menu-row" onClick={() => go('records')}><span className="menu-row__icon"><FileHeart size={20} /></span><span><strong>Personal records</strong><small>View your permitted health summaries</small></span><ChevronRight size={18} /></button>
      <button className="menu-row" onClick={() => go('appointments')}><span className="menu-row__icon"><CalendarDays size={20} /></span><span><strong>Appointment history</strong><small>Upcoming and past visits</small></span><ChevronRight size={18} /></button>
      <div className="profile-sync-note"><RefreshCw size={14} /> Profile details sync from your MIS account.</div>
    </ScreenShell>
  )
}

function HealthProfile({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="health-profile" go={go} nav={false}>
      <PageHeader title="Health profile" subtitle="University profile and medical flags" back={() => go('profile')} />
      <div className="health-id-card">
        <img className="health-id-card__art" src="/assets/brand/levin7-white-fade.svg" alt="" />
        <div className="health-id-card__label"><UserRound size={15} /> STUDENT PROFILE</div>
        <div className="health-id-card__name">Rafael Fuentes Udtohan</div>
        <div className="health-id-card__id">ID: 2023-001234</div>
        <div className="health-id-card__grid">
          <span>COURSE<strong>BS Information Technology</strong></span>
          <span>YEAR LEVEL<strong>Year 3</strong></span>
          <span>DEPARTMENT<strong>CCS</strong></span>
          <span>BLOOD TYPE<strong>O+</strong></span>
        </div>
      </div>
      <div className="profile-section-label">MEDICAL FLAGS</div>
      <div className="medical-flag medical-flag--warning"><AlertCircle size={20} /><div><strong>Allergy recorded</strong><span>Penicillin</span></div></div>
      <div className="medical-flag"><HeartPulse size={20} /><div><strong>Health profile on file</strong><span>Last synchronized from MIS on 26 October 2026</span></div></div>
      <div className="notice notice--privacy"><LockKeyhole size={18} /><span>This summary is shown only to you and authorized health staff. Contact the university office to correct identity details.</span></div>
      <Action full variant="secondary" icon={CircleHelp}>Request a profile correction</Action>
    </ScreenShell>
  )
}

function ProfilePhoto({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="profile-photo" go={go} nav={false}>
      <PageHeader title="Profile photo" back={() => go('profile')} />
      <div className="photo-preview"><span>RU</span></div>
      <div className="photo-title">Choose how you appear in SYNAPSE</div>
      <div className="photo-copy">JPEG, PNG, or WebP up to 10 MB. This changes your personal SYNAPSE picture, not your official university ID photo.</div>
      <div className="photo-actions">
        <button><span><Camera size={21} /></span><div><strong>Take a photo</strong><small>Use your device camera</small></div><ChevronRight size={18} /></button>
        <button><span><User size={21} /></span><div><strong>Choose from library</strong><small>Select an existing photo</small></div><ChevronRight size={18} /></button>
        <button className="danger-row"><span><XCircle size={21} /></span><div><strong>Remove current photo</strong><small>Use your initials instead</small></div><ChevronRight size={18} /></button>
      </div>
      <div className="notice notice--subtle"><ShieldCheck size={19} /><span>Pictures are resized to fit 1024 × 1024, compressed to WebP, and stripped of metadata including location data.</span></div>
    </ScreenShell>
  )
}

function Records({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="records" go={go}>
      <PageHeader title="Personal records" subtitle="Summaries shared with you" back={() => go('profile')} action={<IconAction label="Information" icon={Info} />} />
      <div className="notice notice--privacy"><LockKeyhole size={19} /><span>Only approved summaries are shown here. Internal clinical and counseling notes remain private.</span></div>
      <div className="filter-chips"><button className="is-active">All</button><button>Clinic</button><button>Guidance</button></div>
      <div className="timeline">
        <div className="timeline__month">OCTOBER 2026</div>
        <button className="record-card" onClick={() => go('record-detail')}>
          <span className="timeline__dot" />
          <div className="record-card__date"><strong>14</strong><span>OCT</span></div>
          <div className="record-card__copy"><StatusPill tone="info">Clinic</StatusPill><strong>Health consultation summary</strong><span>University Clinic · Dr. M. Santos</span></div>
          <ChevronRight size={18} />
        </button>
        <div className="timeline__month">SEPTEMBER 2026</div>
        <button className="record-card">
          <span className="timeline__dot timeline__dot--violet" />
          <div className="record-card__date"><strong>22</strong><span>SEP</span></div>
          <div className="record-card__copy"><StatusPill tone="neutral">Guidance</StatusPill><strong>Session attendance record</strong><span>Guidance Center</span></div>
          <ChevronRight size={18} />
        </button>
        <button className="record-card">
          <span className="timeline__dot" />
          <div className="record-card__date"><strong>05</strong><span>SEP</span></div>
          <div className="record-card__copy"><StatusPill tone="info">Clinic</StatusPill><strong>Medical certificate</strong><span>University Clinic</span></div>
          <ChevronRight size={18} />
        </button>
      </div>
    </ScreenShell>
  )
}

function RecordDetail({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="record-detail" go={go}>
      <PageHeader title="Record summary" back={() => go('records')} action={<IconAction label="More options" icon={MoreHorizontal} />} />
      <div className="record-detail-head">
        <div className="service-icon service-icon--clinic service-icon--large"><FileHeart size={25} /></div>
        <div><StatusPill tone="info">Clinic record</StatusPill><div className="card-title">Health consultation summary</div><div className="card-body">14 October 2026</div></div>
      </div>
      <AppCard className="detail-card">
        <DetailRow icon={Stethoscope} label="Healthcare professional" value="Dr. Maria Santos" />
        <DetailRow icon={MapPin} label="Facility" value="University Clinic" />
        <DetailRow icon={FileHeart} label="Record reference" value="HR-2026-00841" last />
      </AppCard>
      <div className="record-section">
        <div className="record-section__title">Visit summary</div>
        <div className="record-section__body">Consultation for recurring headache. General assessment completed. No urgent concerns identified at the time of visit.</div>
      </div>
      <div className="record-section">
        <div className="record-section__title">Recommendations shared with you</div>
        <ul><li>Maintain regular hydration and meals.</li><li>Track headache frequency for two weeks.</li><li>Return to the clinic if symptoms persist or worsen.</li></ul>
      </div>
      <div className="notice notice--warning"><AlertCircle size={19} /><span>This summary is not a diagnosis and does not replace professional medical advice.</span></div>
      <Action full variant="secondary" icon={CircleHelp}>Ask about this record</Action>
    </ScreenShell>
  )
}

function SettingsScreen({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="settings" go={go} nav={false}>
      <PageHeader title="Settings" back={() => go('profile')} />
      <div className="settings-group">
        <div className="profile-section-label">PREFERENCES</div>
        <button className="settings-row"><span className="menu-row__icon"><Bell size={20} /></span><span><strong>Notifications</strong><small>Appointments, queue, and updates</small></span><ChevronRight size={18} /></button>
        <button className="settings-row" onClick={() => go('appearance-dark')}><span className="menu-row__icon"><Moon size={20} /></span><span><strong>Appearance</strong><small>Light, dark, or device setting</small></span><ChevronRight size={18} /></button>
      </div>
      <div className="settings-group">
        <div className="profile-section-label">PRIVACY & SUPPORT</div>
        <button className="settings-row" onClick={() => go('privacy-security')}><span className="menu-row__icon"><ShieldCheck size={20} /></span><span><strong>Privacy & security</strong><small>How your information is protected</small></span><ChevronRight size={18} /></button>
        <button className="settings-row"><span className="menu-row__icon"><CircleHelp size={20} /></span><span><strong>Help & support</strong><small>FAQs and contact information</small></span><ChevronRight size={18} /></button>
        <button className="settings-row"><span className="menu-row__icon"><Info size={20} /></span><span><strong>About SYNAPSE</strong><small>Version 0.1.0</small></span><ChevronRight size={18} /></button>
      </div>
      <button className="sign-out-row" onClick={() => go('sign-in')}><LogOut size={20} /> Sign out on this device</button>
      <div className="settings-footer">SYNAPSE Mobile · Foundation University</div>
    </ScreenShell>
  )
}

function PrivacySecurity({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="privacy-security" go={go} nav={false}>
      <PageHeader title="Privacy & security" back={() => go('settings')} />
      <div className="privacy-hero"><ShieldCheck size={34} /><div><strong>Your information is protected</strong><span>SYNAPSE uses secure, university-managed systems.</span></div></div>
      <div className="privacy-list">
        <div><span><KeyRound size={20} /></span><div><strong>Secure sign-in</strong><p>Your MIS password is used only to authenticate and is never saved by this app.</p></div></div>
        <div><span><LockKeyhole size={20} /></span><div><strong>Protected session</strong><p>Session credentials are secured by your device’s Keychain or Keystore.</p></div></div>
        <div><span><UsersRound size={20} /></span><div><strong>Limited access</strong><p>Only authorized staff can access information required for your care.</p></div></div>
        <div><span><FileHeart size={20} /></span><div><strong>Private records</strong><p>Internal clinical and counseling notes are never displayed in the mobile app.</p></div></div>
      </div>
      <div className="section-title">On this device</div>
      <AppCard className="device-session">
        <div className="device-session__icon"><Activity size={20} /></div>
        <div><strong>Current session</strong><span>Android device · Active now</span></div>
        <StatusPill tone="success">This device</StatusPill>
      </AppCard>
      <Action full variant="secondary">Read full privacy notice</Action>
    </ScreenShell>
  )
}

function AppearanceDark({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="appearance-dark" go={go} nav={false} className="dark-demo">
      <PageHeader title="Appearance" subtitle="Saved on this device" back={() => go('settings')} />
      <div className="appearance-preview">
        <SynapseLogo inverse />
        <div><span>Preview</span><strong>Dark mode</strong></div>
      </div>
      <div className="profile-section-label">THEME</div>
      <div className="theme-options">
        <button><span><Sparkles size={20} /></span><div><strong>Light</strong><small>Bright surfaces and maroon actions</small></div><i /></button>
        <button className="is-selected"><span><Moon size={20} /></span><div><strong>Dark</strong><small>Achromatic neutral dark palette</small></div><i><Check size={13} /></i></button>
        <button><span><Settings size={20} /></span><div><strong>Use device setting</strong><small>Match your phone automatically</small></div><i /></button>
      </div>
      <div className="notice notice--subtle"><Info size={18} /><span>Maroon remains the primary action color in both themes, matching the SYNAPSE web portal.</span></div>
    </ScreenShell>
  )
}

function Offline({ go }: { go: (id: ScreenId) => void }) {
  return (
    <ScreenShell active="offline" go={go} nav={false} className="centered-screen">
      <EmptyState icon={WifiOff} title="You’re offline" body="SYNAPSE needs an internet connection to securely load your latest information." />
      <div className="offline-help"><Info size={17} /><span>No health or guidance information is stored for offline access.</span></div>
      <div className="bottom-actions"><Action full icon={RefreshCw} onClick={() => go('dashboard')}>Try again</Action><Action full variant="ghost" onClick={() => go('sign-in')}>Sign out</Action></div>
    </ScreenShell>
  )
}

function ScreenRenderer({ active, go }: { active: ScreenId; go: (id: ScreenId) => void }) {
  switch (active) {
    case 'splash': return <Splash go={go} />
    case 'sign-in': return <SignIn go={go} />
    case 'sign-in-error': return <SignIn go={go} error />
    case 'session-expired': return <SessionExpired go={go} />
    case 'access-restricted': return <AccessRestricted go={go} />
    case 'dashboard': return <Dashboard go={go} />
    case 'staff-personal-mode': return <StaffPersonalMode go={go} />
    case 'appointments': return <Appointments go={go} />
    case 'appointments-filtered-empty': return <AppointmentsFilteredEmpty go={go} />
    case 'appointment-detail': return <AppointmentDetail go={go} />
    case 'appointment-requested': return <AppointmentState go={go} />
    case 'appointment-checked-in': return <AppointmentState go={go} checkedIn />
    case 'booking-unit': return <BookingUnit go={go} />
    case 'booking-details': return <BookingDetails go={go} />
    case 'booking-slots': return <BookingSlots go={go} />
    case 'booking-review': return <BookingReview go={go} />
    case 'booking-success': return <BookingSuccess go={go} />
    case 'cancel-appointment': return <CancelAppointment go={go} />
    case 'queue-active': return <QueueActive go={go} />
    case 'queue-multiple': return <QueueMultiple go={go} />
    case 'queue-empty': return <QueueEmpty go={go} />
    case 'announcements': return <Announcements go={go} />
    case 'announcement-detail': return <AnnouncementDetail go={go} />
    case 'surveys': return <Surveys go={go} />
    case 'survey-intro': return <SurveyIntro go={go} />
    case 'survey-form': return <SurveyForm go={go} />
    case 'survey-success': return <SurveySuccess go={go} />
    case 'notifications': return <Notifications go={go} />
    case 'notification-history': return <NotificationHistory go={go} />
    case 'profile': return <Profile go={go} />
    case 'health-profile': return <HealthProfile go={go} />
    case 'profile-photo': return <ProfilePhoto go={go} />
    case 'records': return <Records go={go} />
    case 'record-detail': return <RecordDetail go={go} />
    case 'settings': return <SettingsScreen go={go} />
    case 'privacy-security': return <PrivacySecurity go={go} />
    case 'appearance-dark': return <AppearanceDark go={go} />
    case 'offline': return <Offline go={go} />
  }
}

export default function App() {
  const [active, setActive] = useState<ScreenId>('dashboard')
  const [menuOpen, setMenuOpen] = useState(false)
  const current = useMemo(() => allScreens.find((screen) => screen.id === active)!, [active])

  const go = (id: ScreenId) => {
    setActive(id)
    setMenuOpen(false)
  }

  return (
    <div className="prototype">
      <aside className={`prototype-sidebar${menuOpen ? ' is-open' : ''}`}>
        <div className="sidebar-brand">
          <SynapseLogo />
          <div><span>Mobile screen review</span></div>
          <button className="sidebar-close" onClick={() => setMenuOpen(false)}><X size={20} /></button>
        </div>
        <div className="screen-count"><span>{allScreens.length} screens</span><span>Complete MVP flow</span></div>
        <div className="screen-directory">
          {screenGroups.map((group) => (
            <div className="directory-group" key={group.label}>
              <div className="directory-group__label">{group.label}</div>
              {group.screens.map((screen, index) => (
                <button className={screen.id === active ? 'is-active' : ''} key={screen.id} onClick={() => go(screen.id)}>
                  <span>{String(index + 1).padStart(2, '0')}</span>
                  <strong>{screen.label}</strong>
                  {screen.id === active ? <ChevronRight size={15} /> : null}
                </button>
              ))}
            </div>
          ))}
        </div>
        <div className="sidebar-note"><ShieldCheck size={17} /><span>Prototype only. No personal information is stored.</span></div>
      </aside>
      {menuOpen ? <button className="sidebar-scrim" onClick={() => setMenuOpen(false)} /> : null}

      <main className="prototype-stage">
        <div className="review-toolbar">
          <div className="review-toolbar__title">
            <button className="menu-toggle" onClick={() => setMenuOpen(true)}><Menu size={20} /></button>
            <div><span>{current.group}</span><strong>{current.label}</strong></div>
          </div>
          <div className="review-toolbar__nav">
            <button
              disabled={allScreens.findIndex((item) => item.id === active) === 0}
              onClick={() => go(allScreens[allScreens.findIndex((item) => item.id === active) - 1].id)}
            >
              <ArrowLeft size={17} /> Previous
            </button>
            <span>{allScreens.findIndex((item) => item.id === active) + 1} / {allScreens.length}</span>
            <button
              disabled={allScreens.findIndex((item) => item.id === active) === allScreens.length - 1}
              onClick={() => go(allScreens[allScreens.findIndex((item) => item.id === active) + 1].id)}
            >
              Next <ChevronRight size={17} />
            </button>
          </div>
        </div>
        <div className="phone-wrap">
          <div className="phone-frame">
            <div className="phone-status">
              <span>9:41</span>
              <div><span className="signal-bars"><i /><i /><i /><i /></span><span className="wifi">⌁</span><span className="battery"><i /></span></div>
            </div>
            <ScreenRenderer active={active} go={go} />
            <div className="home-indicator"><span /></div>
          </div>
          <div className="review-hint"><Info size={16} /><span>Use the screen directory or tap elements inside the prototype to explore flows.</span></div>
        </div>
      </main>
    </div>
  )
}
