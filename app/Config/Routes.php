<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Public Auth routes
$routes->get('auth/login', 'AuthController::login');
$routes->post('auth/login', 'AuthController::attemptLogin', ['filter' => ['web_csrf', 'login_throttle']]);
$routes->post('auth/logout', 'AuthController::logout', ['filter' => 'web_csrf']);
$routes->get('auth/logout/confirm', 'AuthController::confirmLogout');
$routes->get('auth/logout', static function () {
    return service('response')->setStatusCode(405)->setHeader('Allow', 'POST')->setBody('Use the Sign Out confirmation to end your session.');
});

// ESP32-S3 Hardware IoT API (authenticated via X-Device-Token header)
$routes->group('api/v1/devices/bmg', static function (RouteCollection $routes): void {
    $routes->get('schedule', 'Api\BmgDeviceApiController::schedule');
    $routes->post('heartbeat', 'Api\BmgDeviceApiController::heartbeat');
    $routes->post('runs', 'Api\BmgDeviceApiController::runs');
    $routes->get('commands', 'Api\BmgDeviceApiController::commands');
    $routes->post('commands/ack', 'Api\BmgDeviceApiController::acknowledge');
});

// Mobile app API: opaque bearer tokens, per-request identity revalidation, no browser session state.
$routes->group('api/v1', static function (RouteCollection $routes): void {
    $routes->post('auth/login', 'Api\V1\MobileAuthController::login');
    $routes->post('auth/refresh', 'Api\V1\MobileAuthController::refresh');
    $routes->group('', ['filter' => 'api_auth'], static function (RouteCollection $routes): void {
        $routes->post('auth/logout', 'Api\V1\MobileAuthController::logout');
        $routes->get('me', 'Api\V1\MeController::show');
        $routes->get('me/health-summary', 'Api\V1\MeController::healthSummary');
        $routes->get('me/photo', 'Api\V1\MeController::photo');
        $routes->post('me/photo', 'Api\V1\MeController::uploadPhoto');
        $routes->delete('me/photo', 'Api\V1\MeController::deletePhoto');

        // Personal portal resources.
        $routes->get('appointments', 'Api\V1\AppointmentsApiController::index');
        $routes->post('appointments', 'Api\V1\AppointmentsApiController::create');
        $routes->get('appointments/(:num)', 'Api\V1\AppointmentsApiController::show/$1');
        $routes->post('appointments/(:num)/cancel', 'Api\V1\AppointmentsApiController::cancel/$1');
        $routes->get('queue', 'Api\V1\QueueApiController::index');
        $routes->get('announcements', 'Api\V1\AnnouncementsApiController::index');
        $routes->get('announcements/(:num)', 'Api\V1\AnnouncementsApiController::show/$1');
        $routes->post('announcements/(:num)/read', 'Api\V1\AnnouncementsApiController::markRead/$1');
        $routes->get('surveys', 'Api\V1\SurveysApiController::index');
        $routes->get('surveys/(:num)', 'Api\V1\SurveysApiController::show/$1');
        $routes->post('surveys/(:num)/submit', 'Api\V1\SurveysApiController::submit/$1');
        $routes->get('notifications', 'Api\V1\NotificationsApiController::index');
        $routes->get('notifications/history', 'Api\V1\NotificationsApiController::history');
        $routes->post('notifications/read-all', 'Api\V1\NotificationsApiController::readAll');
        $routes->post('notifications/(:num)/read', 'Api\V1\NotificationsApiController::read/$1');

        // Staff monitoring only; no staff action endpoint exists on the mobile API.
        $routes->get('staff/queue-summary', 'Api\V1\StaffQueueApiController::index');
    });
});

// Protected application routes
$routes->group('', ['filter' => ['auth', 'web_csrf', 'portal_host', 'web_access']], static function (RouteCollection $routes): void {
    $routes->get('/', 'DashboardController::index');
    $routes->get('dashboard', 'DashboardController::index');

    $routes->get('reports/export', 'ReportExportController::index');
    $routes->post('reports/export/operational', 'ReportExportController::operational');
    $routes->group('admin/mis-sync', ['filter' => 'auth:mis.sync'], static function (RouteCollection $routes): void {
        $routes->get('', 'MisSyncController::index');
        $routes->post('run', 'MisSyncController::sync');
    });

    // Short-lived, server-authorized WebSocket grants; no client-selected channels.
    $routes->post('realtime/ticket', 'RealtimeController::ticket');

    // Personal, person-owned notifications (never a department-wide inbox).
    $routes->get('notifications', 'NotificationController::index');
    $routes->get('notifications/inbox', 'NotificationController::inbox');
    $routes->get('notifications/history', 'NotificationController::history');
    $routes->post('notifications/read-all', 'NotificationController::readAll');
    $routes->post('notifications/(:num)/read', 'NotificationController::read/$1');
    $routes->get('notifications/read-all', 'NotificationController::readAll');
    $routes->get('notifications/(:num)/read', 'NotificationController::read/$1');

    // Same-origin, self-owned avatar reads also work on an authorized workstation host.
    $routes->get('account/photo', 'ProfileSettingsController::photo');

    // Student & Employee Personal Health Portal
    $routes->get('portal/queue', 'PortalQueueController::index');
    foreach (['clinic', 'guidance'] as $unit) {
        $routes->get($unit . '/dashboard/queue-counts', 'QueueDashboardController::' . $unit, ['filter' => 'portal_access:' . $unit]);
    }
    $routes->get('portal', 'PortalController::index');
    $routes->get('portal/appointments', 'PortalController::index');
    $routes->get('portal/appointments/(:num)', 'PortalController::appointmentDetail/$1');
    $routes->get('portal/records', 'PortalController::index');
    $routes->get('portal/visits', 'PortalVisitHistoryController::index');
    $routes->get('portal/settings', 'ProfileSettingsController::index');
    $routes->get('portal/settings/photo', 'ProfileSettingsController::photo');
    $routes->post('portal/settings/photo', 'ProfileSettingsController::upload');
    $routes->post('portal/settings/photo/remove', 'ProfileSettingsController::remove');
    $routes->post('portal/settings/emergency-contact', 'ProfileSettingsController::updateEmergencyContact');

    // Operational Teams tasks use department membership, not clinical record grants.
    foreach (['clinic', 'guidance', 'facilities'] as $unit) {
        $routes->group($unit . '/teams', ['filter' => 'portal_access:' . ($unit === 'facilities' ? 'bmg' : $unit)], static function (RouteCollection $routes) use ($unit): void {
            $routes->get('', 'TeamsController::index/' . $unit);
            $routes->get('chat', 'TeamChatController::index/' . $unit);
            $routes->get('chat/history', 'TeamChatController::history/' . $unit);
                        $routes->get('chat/tasks', 'TeamChatController::tasks/' . $unit);
            $routes->post('chat/send', 'TeamChatController::send/' . $unit);
            $routes->post('chat/read', 'TeamChatController::read/' . $unit);
            $routes->get('calendar', 'TeamCalendarController::index/' . $unit);
            $routes->get('calendar/events/create', 'TeamCalendarController::create/' . $unit);
            $routes->post('calendar/events/store', 'TeamCalendarController::store/' . $unit);
            $routes->get('calendar/events/(:num)', 'TeamCalendarController::show/' . $unit . '/$1');
            $routes->get('calendar/events/(:num)/edit', 'TeamCalendarController::edit/' . $unit . '/$1');
            $routes->post('calendar/events/(:num)/update', 'TeamCalendarController::update/' . $unit . '/$1');
            $routes->post('calendar/events/(:num)/cancel', 'TeamCalendarController::cancel/' . $unit . '/$1');
            $routes->get('create', 'TeamsController::create/' . $unit);
            $routes->post('store', 'TeamsController::store/' . $unit);
            $routes->get('(:num)', 'TeamsController::show/' . $unit . '/$1');
            $routes->get('(:num)/edit', 'TeamsController::edit/' . $unit . '/$1');
            $routes->post('(:num)/update', 'TeamsController::update/' . $unit . '/$1');
            $routes->post('(:num)/move', 'TeamsController::move/' . $unit . '/$1');
            $routes->post('(:num)/comments', 'TeamsController::comment/' . $unit . '/$1');
        });
    }

    // Campus content: personal reads are separate from department publishing.
    $routes->get('portal/announcements', 'AnnouncementController::portalIndex');
    $routes->get('portal/announcements/(:num)', 'AnnouncementController::show/$1');
    $routes->post('portal/announcements/(:num)/read', 'AnnouncementController::read/$1');
    $routes->get('portal/surveys', 'PortalSurveyController::index');
    $routes->get('portal/surveys/(:num)', 'PortalSurveyController::show/$1');
    $routes->post('portal/surveys/(:num)/submit', 'PortalSurveyController::submit/$1');
    foreach (['clinic', 'guidance'] as $unit) {
        $routes->group($unit . '/announcements', ['filter' => 'portal_access:' . $unit], static function (RouteCollection $routes) use ($unit): void {
            $routes->get('', 'AnnouncementController::index/' . $unit);
            $routes->get('create', 'AnnouncementController::create/' . $unit);
            $routes->post('store', 'AnnouncementController::store/' . $unit);
            $routes->get('(:num)/edit', 'AnnouncementController::edit/$1/' . $unit);
            $routes->post('(:num)/update', 'AnnouncementController::update/$1/' . $unit);
            $routes->post('(:num)/publish', 'AnnouncementController::publish/$1/' . $unit);
            $routes->post('(:num)/archive', 'AnnouncementController::archive/$1/' . $unit);
        });
    }
    $routes->group('guidance/question-bank', ['filter' => 'portal_access:guidance'], static function (RouteCollection $routes): void {
        $routes->get('', 'QuestionBankController::index');
        $routes->get('catalog', 'QuestionBankController::catalog');
        $routes->get('create', 'QuestionBankController::create');
        $routes->post('store', 'QuestionBankController::store');
        $routes->get('(:num)', 'QuestionBankController::show/$1');
        $routes->get('(:num)/edit', 'QuestionBankController::edit/$1');
        $routes->post('(:num)/update', 'QuestionBankController::update/$1');
        $routes->post('(:num)/archive', 'QuestionBankController::archive/$1');
    });
    $routes->group('guidance/surveys', ['filter' => 'portal_access:guidance'], static function (RouteCollection $routes): void {
        $routes->get('', 'SurveyController::index');
        $routes->get('create', 'SurveyController::create');
        $routes->post('store', 'SurveyController::store');
        $routes->get('(:num)/edit', 'SurveyController::edit/$1');
        $routes->post('(:num)/update', 'SurveyController::update/$1');
        $routes->post('(:num)/publish', 'SurveyController::publish/$1');
        $routes->post('(:num)/close', 'SurveyController::close/$1');
        $routes->get('(:num)/responses', 'SurveyController::responses/$1');
        $routes->get('(:num)/responses/(:num)', 'SurveyController::responseDetail/$1/$2');
        $routes->post('(:num)/responses/export', 'SurveyController::exportResponses/$1');
    });

    // Facilities (BMG) - Gated to BMG Operators & Admins
    $routes->group('', ['filter' => 'portal_access:bmg'], static function (RouteCollection $routes): void {
        $routes->get('facilities/dashboard', 'FacilitiesController::dashboard');
        $routes->get('facilities', 'FacilitiesController::index');
        $routes->get('facilities/unit/(:num)', 'FacilitiesController::unitView/$1');
        $routes->get('facilities/unit/(:num)/usb', 'BmgUsbController::index/$1');
        $routes->get('facilities/unit/(:num)/usb/setup-status', 'BmgUsbController::setupStatus/$1');
        $routes->get('facilities/unit/(:num)/usb/sketch', 'BmgUsbController::sketch/$1');
        $routes->post('facilities/unit/(:num)/usb/build', 'BmgUsbController::build/$1');
        $routes->get('facilities/unit/(:num)/usb/releases/([a-z0-9]+(?:-[a-z0-9]+)*)/manifest', 'BmgUsbController::manifest/$1/$2');
        $routes->get('facilities/unit/(:num)/usb/releases/([a-z0-9]+(?:-[a-z0-9]+)*)/parts/(:num)', 'BmgUsbController::part/$1/$2/$3');
        $routes->post('facilities/unit/(:num)/usb/authorize-flash', 'BmgUsbController::authorizeFlash/$1');
        $routes->post('facilities/units/store', 'FacilitiesController::storeUnit');
        $routes->post('facilities/unit/(:num)/update', 'FacilitiesController::updateUnit/$1');
        $routes->post('facilities/unit/(:num)/archive', 'FacilitiesController::archiveUnit/$1');
        $routes->post('facilities/unit/(:num)/restore', 'FacilitiesController::restoreUnit/$1');
        $routes->post('facilities/unit/(:num)/schedule', 'FacilitiesController::saveSchedule/$1');
        $routes->post('facilities/unit/(:num)/device', 'FacilitiesController::registerDevice/$1');
        $routes->post('facilities/device/(:num)/revoke', 'FacilitiesController::revokeDevice/$1');
        $routes->post('facilities/unit/(:num)/command', 'FacilitiesController::command/$1');
        $routes->post('facilities/unit/(:num)/batch', 'FacilitiesController::startBatch/$1');
        $routes->post('facilities/batch/(:num)/release', 'FacilitiesController::releaseBatch/$1');
        $routes->post('facilities/batch/(:num)/cancel', 'FacilitiesController::cancelBatch/$1');
    });

    // Assignment policy is additionally scoped by EmployeeAccessService.
    $routes->group('admin/access', ['filter' => 'auth:rbac.manage'], static function (RouteCollection $routes): void {
        $routes->get('', 'EmployeeAccessController::index');
        $routes->get('(:num)/edit', 'EmployeeAccessController::edit/$1');
        $routes->post('(:num)/update', 'EmployeeAccessController::update/$1');
    });

    // Audit Trail & Cryptographic Chain
    $routes->get('admin', 'DashboardController::admin', ['filter' => 'auth:audit.read']);
    $routes->get('admin/dashboard', 'DashboardController::admin', ['filter' => 'auth:audit.read']);
    $routes->get('admin/audit', 'AuditController::index', ['filter' => 'auth:audit.read']);
    $routes->post('admin/audit/verify', 'AuditController::verify', ['filter' => 'auth:audit.read']);



    // Login Carousel Management - Gated to RBAC managers only
    $routes->group('admin/carousel', ['filter' => 'auth:rbac.manage'], static function (RouteCollection $routes): void {
        $routes->get('', 'AdminCarouselController::index');
        $routes->get('create', 'AdminCarouselController::create');
        $routes->post('store', 'AdminCarouselController::store');
        $routes->get('(:num)/edit', 'AdminCarouselController::edit/$1');
        $routes->post('(:num)/update', 'AdminCarouselController::update/$1');
        $routes->post('reorder', 'AdminCarouselController::reorder');
        $routes->post('(:num)/toggle', 'AdminCarouselController::toggle/$1');
        $routes->post('(:num)/delete', 'AdminCarouselController::delete/$1');
    });

    // APIs
    $routes->get('api/patients/search', 'PatientLookupController::search');
    $routes->get('api/appointments/slots', 'AppointmentController::slots');

    // Guidance Console - Gated to Guidance Staff & Counselors
    $routes->group('', ['filter' => 'portal_access:guidance'], static function (RouteCollection $routes): void {
        $routes->get('guidance', 'GuidanceController::dashboard');
        $routes->get('guidance/dashboard', 'GuidanceController::dashboard');
        $routes->get('guidance/cases', 'GuidanceController::cases');
        $routes->get('guidance/cases/create', 'GuidanceController::newCase');
        $routes->post('guidance/cases/store', 'GuidanceController::storeCase');
        $routes->get('guidance/cases/(:num)', 'GuidanceController::caseView/$1');
        $routes->get('guidance/cases/(:num)/photo', 'GuidanceController::casePhoto/$1');
        $routes->post('guidance/cases/(:num)/session', 'GuidanceController::startSession/$1');
        $routes->post('guidance/cases/(:num)/followups', 'GuidanceController::addFollowup/$1');
        $routes->post('guidance/cases/(:num)/close', 'GuidanceController::closeCase/$1');
        $routes->post('guidance/cases/(:num)/monitor', 'GuidanceController::monitorCase/$1');
        $routes->get('guidance/cases/choose-for-ticket/(:num)', 'GuidanceController::chooseSessionForTicket/$1');
        $routes->post('guidance/cases/start-for-ticket/(:num)', 'GuidanceController::startForTicket/$1');
        $routes->get('guidance/cases/start-for-ticket/(:num)', static function () {
            return service('response')->setStatusCode(405)->setHeader('Allow', 'POST')->setBody('Method not allowed.');
        });
        $routes->get('guidance/sessions/(:num)/read', 'GuidanceController::readSessionNote/$1');
        $routes->post('guidance/sessions/(:num)/notes', 'GuidanceController::saveSessionNotes/$1');
        $routes->post('guidance/sessions/(:num)/close', 'GuidanceController::closeSession/$1');
        $routes->get('guidance/sessions/(:num)/history', 'GuidanceController::readSessionNoteHistory/$1');
        $routes->post('guidance/followups/(:num)/complete', 'GuidanceController::completeFollowup/$1');
        $routes->post('guidance/followups/(:num)/missed', 'GuidanceController::missFollowup/$1');

        $routes->get('guidance/queue', 'QueueController::index/guidance');
        $routes->get('guidance/queue/board', 'QueueController::board/guidance');
        $routes->post('guidance/queue/call-next', 'QueueController::callNext/guidance');
        $routes->post('guidance/queue/call/(:num)', 'QueueController::call/guidance/$1');
        $routes->post('guidance/queue/start/(:num)', 'QueueController::start/guidance/$1');
        $routes->post('guidance/queue/complete/(:num)', 'QueueController::complete/guidance/$1');
        $routes->post('guidance/queue/skip/(:num)', 'QueueController::skip/guidance/$1');
        $routes->post('guidance/queue/recall/(:num)', 'QueueController::recall/guidance/$1');
        $routes->post('guidance/queue/no-show/(:num)', 'QueueController::noShow/guidance/$1');
        $routes->post('guidance/queue/walkin', 'QueueController::walkin/guidance');
    });

    foreach (['clinic', 'guidance'] as $unit) {
        $routes->group($unit . '/referrals', ['filter' => 'portal_access:' . $unit], static function (RouteCollection $routes) use ($unit): void {
            $routes->get('', 'ReferralController::index/' . $unit);
            $routes->get('create', 'ReferralController::create/' . $unit);
            $routes->post('store', 'ReferralController::store/' . $unit);
            $routes->post('(:num)/acknowledge', 'ReferralController::acknowledge/$1/' . $unit);
            $routes->post('(:num)/review', 'ReferralController::review/$1/' . $unit);
            $routes->post('(:num)/close', 'ReferralController::close/$1/' . $unit);
            $routes->get('(:num)/handoff', 'ReferralController::handoff/$1/' . $unit);
            $routes->post('(:num)/handoff', 'ReferralController::handoffStore/$1/' . $unit);
        });
    }

    // Compatibility routes; new forms always bind routing to an explicit workstation.
    $routes->get('referrals', 'ReferralController::index');
    $routes->get('referrals/create', 'ReferralController::create');
    $routes->post('referrals/store', 'ReferralController::store');
    $routes->post('referrals/(:num)/acknowledge', 'ReferralController::acknowledge/$1');
    $routes->post('referrals/(:num)/review', 'ReferralController::review/$1');
    $routes->post('referrals/(:num)/convert', 'ReferralController::convertToCase/$1');
    $routes->post('referrals/(:num)/close', 'ReferralController::close/$1');

    // Clinic Console - Gated to Clinic Nurses, Doctors & Admins
    $routes->group('', ['filter' => 'portal_access:clinic'], static function (RouteCollection $routes): void {
        $routes->get('clinic', 'ClinicController::dashboard');
        $routes->get('clinic/dashboard', 'ClinicController::dashboard');
        $routes->get('clinic/encounters', 'ClinicController::encounters');
        $routes->get('clinic/encounters/(:num)', 'ClinicController::encounter/$1');
        $routes->post('clinic/encounters/(:num)/vitals', 'ClinicController::saveVitals/$1');
        $routes->post('clinic/encounters/(:num)/assessment', 'ClinicController::saveAssessment/$1');
        $routes->post('clinic/encounters/(:num)/draft', 'ClinicController::saveDraft/$1', ['filter' => 'inventory_csrf']);
        $routes->post('clinic/encounters/(:num)/dispense', 'ClinicController::dispense/$1', ['filter' => 'inventory_csrf']);
        $routes->post('clinic/encounters/(:num)/complete', 'ClinicController::complete/$1', ['filter' => 'inventory_csrf']);
        $routes->post('clinic/encounters/start-for-ticket/(:num)', 'ClinicController::startForTicket/$1', ['filter' => 'inventory_csrf']);
        $routes->get('clinic/encounters/start-for-ticket/(:num)', static function () {
            return service('response')->setStatusCode(405)->setHeader('Allow', 'POST')->setBody('Method not allowed.');
        });
        $routes->post('clinic/encounters/start-direct/(:num)', 'ClinicController::startDirect/$1');

        $routes->group('clinic/inventory', static function (RouteCollection $routes): void {
            $routes->get('', 'ClinicInventoryController::index');
            $routes->get('create', 'ClinicInventoryController::create');
            $routes->post('store', 'ClinicInventoryController::store', ['filter' => 'inventory_csrf']);
            $routes->get('(:num)/edit', 'ClinicInventoryController::edit/$1');
            $routes->post('(:num)/update', 'ClinicInventoryController::update/$1', ['filter' => 'inventory_csrf']);
            $routes->get('(:num)/receive', 'ClinicInventoryController::receiveForm/$1');
            $routes->post('(:num)/receive', 'ClinicInventoryController::receive/$1', ['filter' => 'inventory_csrf']);
            $routes->get('batches/(:num)/adjust', 'ClinicInventoryController::adjustForm/$1');
            $routes->post('batches/(:num)/adjust', 'ClinicInventoryController::adjust/$1', ['filter' => 'inventory_csrf']);
            $routes->get('batches/(:num)/status', 'ClinicInventoryController::statusForm/$1');
            $routes->post('batches/(:num)/status', 'ClinicInventoryController::status/$1', ['filter' => 'inventory_csrf']);
            $routes->get('operations/(:num)/reverse', 'ClinicInventoryController::reverseForm/$1');
            $routes->post('operations/(:num)/reverse', 'ClinicInventoryController::reverse/$1', ['filter' => 'inventory_csrf']);
            $routes->get('(:num)', 'ClinicInventoryController::show/$1');
        });

        $routes->get('clinic/patients', 'ClinicController::patients');
        $routes->get('clinic/patients/(:num)', 'ClinicController::patientChart/$1');
        $routes->post('clinic/patients/(:num)/health', 'ClinicController::updateHealth/$1');

        $routes->get('clinic/queue', 'QueueController::index/clinic');
        $routes->get('clinic/queue/board', 'QueueController::board/clinic');
        $routes->post('clinic/queue/call-next', 'QueueController::callNext/clinic');
        $routes->post('clinic/queue/call/(:num)', 'QueueController::call/clinic/$1');
        $routes->post('clinic/queue/start/(:num)', 'QueueController::start/clinic/$1');
        $routes->post('clinic/queue/complete/(:num)', 'QueueController::complete/clinic/$1');
        $routes->post('clinic/queue/skip/(:num)', 'QueueController::skip/clinic/$1');
        $routes->post('clinic/queue/recall/(:num)', 'QueueController::recall/clinic/$1');
        $routes->post('clinic/queue/no-show/(:num)', 'QueueController::noShow/clinic/$1');
        $routes->post('clinic/queue/walkin', 'QueueController::walkin/clinic');
    });

    foreach (['clinic', 'guidance'] as $unit) {
        // Staff, including staff acting as patients, retain separate self-service access.
        $routes->get('portal/appointments/' . $unit . '/create', 'AppointmentController::personalCreate/' . $unit);
        $routes->post('portal/appointments/' . $unit . '/store', 'AppointmentController::personalStore/' . $unit, ['filter' => 'appointment_csrf']);
        $routes->post('portal/appointments/' . $unit . '/cancel/(:num)', 'AppointmentController::personalCancel/' . $unit . '/$1', ['filter' => 'appointment_csrf']);

        $routes->group($unit . '/appointments', ['filter' => 'portal_access:' . $unit], static function (RouteCollection $routes) use ($unit): void {
            $routes->get('', 'AppointmentController::index/' . $unit);
            $routes->get('create', 'AppointmentController::create/' . $unit);
            $routes->post('store', 'AppointmentController::store/' . $unit, ['filter' => 'appointment_csrf']);
            $routes->get('availability', 'AppointmentController::availability/' . $unit);
            $routes->post('availability', 'AppointmentController::availabilityStore/' . $unit, ['filter' => 'appointment_csrf']);
            $routes->post('availability/template', 'AppointmentController::availabilityTemplateStore/' . $unit, ['filter' => 'appointment_csrf']);
            $routes->post('availability/enroll', 'AppointmentController::availabilityEnroll/' . $unit, ['filter' => 'appointment_csrf']);
            $routes->post('availability/exceptions', 'AppointmentController::availabilityExceptionStore/' . $unit, ['filter' => 'appointment_csrf']);
            $routes->post('availability/exceptions/remove', 'AppointmentController::availabilityExceptionRemove/' . $unit, ['filter' => 'appointment_csrf']);
            $routes->get('(:num)/confirm', 'AppointmentController::confirm/' . $unit . '/$1');
            $routes->post('(:num)/confirm', 'AppointmentController::confirmStore/' . $unit . '/$1', ['filter' => 'appointment_csrf']);
            $routes->post('check-in/(:num)', 'AppointmentController::checkIn/' . $unit . '/$1', ['filter' => 'appointment_csrf']);
            $routes->post('cancel/(:num)', 'AppointmentController::cancel/' . $unit . '/$1', ['filter' => 'appointment_csrf']);
            $routes->post('no-show/(:num)', 'AppointmentController::noShow/' . $unit . '/$1', ['filter' => 'appointment_csrf']);
        });
    }
});
