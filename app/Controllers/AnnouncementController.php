<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnnouncementService;
use RuntimeException;
use Throwable;

final class AnnouncementController extends BaseController
{
    private AnnouncementService $announcements;

    public function __construct()
    {
        $this->announcements = new AnnouncementService();
    }

    public function index(string $unit)
    {
        return $this->respond(function () use ($unit) {
            $data = $this->announcements->listing($unit, $this->request->getGet('page') ?? 1);
            $reads = $this->request->getGet('reads');
            if ($reads !== null) {
                if (!is_string($reads) || !ctype_digit($reads) || (int) $reads < 1) { throw new RuntimeException('Invalid announcement.', 422); }
                $data['readHistory'] = $this->announcements->reads((int) $reads, $unit, $this->request->getGet('read_page') ?? 1);
            }
            return view('announcements/index', $data + ['unit' => $unit, 'title' => ucfirst($unit) . ' Announcements',
                'baseUrl' => site_url('/' . $unit . '/announcements'), 'formModalPage' => false]);
        });
    }

    public function create(string $unit)
    {
        return $this->respond(function () use ($unit) {
            $this->announcements->manager($unit);
            return $this->form($unit);
        });
    }

    public function store(string $unit)
    {
        return $this->respond(function () use ($unit) {
            $this->announcements->create($unit, $this->request->getPost());
            return $this->success('Announcement saved as a draft. Publish it separately when ready.', '/' . $unit . '/announcements');
        });
    }

    public function edit(int $id, string $unit)
    {
        return $this->respond(fn () => $this->form($unit, $this->announcements->draft($id, $unit)));
    }

    public function update(int $id, string $unit)
    {
        return $this->respond(function () use ($id, $unit) {
            $this->announcements->update($id, $unit, $this->request->getPost());
            return $this->success('Announcement draft updated.', '/' . $unit . '/announcements');
        });
    }

    public function publish(int $id, string $unit)
    {
        return $this->respond(function () use ($id, $unit) {
            $this->announcements->publish($id, $unit, $this->request->getPost('version'));
            return $this->success('Announcement published. Its content can no longer be edited.', '/' . $unit . '/announcements');
        });
    }

    public function archive(int $id, string $unit)
    {
        return $this->respond(function () use ($id, $unit) {
            $this->announcements->archive($id, $unit, $this->request->getPost('version'));
            return $this->success('Announcement archived.', '/' . $unit . '/announcements');
        });
    }

    public function portalIndex()
    {
        return $this->respond(fn () => view('announcements/portal', $this->announcements->portalListing($this->request->getGet('page') ?? 1, $this->request->getGet())
            + ['title' => 'Announcements', 'baseUrl' => site_url('/portal/announcements')]));
    }

    public function show(int $id)
    {
        return $this->respond(fn () => view('announcements/show', ['announcement' => $this->announcements->show($id), 'title' => 'Announcement']));
    }

    public function read(int $id)
    {
        return $this->respond(function () use ($id) {
            $this->announcements->read($id);
            return $this->success('Announcement marked as read.', '/portal/announcements/' . $id);
        });
    }

    private function form(string $unit, ?array $announcement = null): string
    {
        $base = '/' . $unit . '/announcements';
        return view('announcements/form', ['announcement' => $announcement, 'unit' => $unit,
            'title' => $announcement === null ? 'New Announcement Draft' : 'Edit Announcement Draft',
            'formAction' => site_url($base . ($announcement === null ? '/store' : '/' . $announcement['id'] . '/update')),
            'cancelUrl' => site_url($base), 'formModalPage' => true]);
    }

    private function respond(callable $operation)
    {
        $this->response->setHeader('Cache-Control', 'no-store, private');
        try { return $operation(); }
        catch (Throwable $e) {
            $expected = $e instanceof RuntimeException || $e instanceof \InvalidArgumentException;
            $status = $expected && in_array($e->getCode(), [401, 403, 404, 409, 422], true) ? $e->getCode() : 503;
            if ($status === 503) { log_message('error', 'Announcement operation failed: {exception}', ['exception' => $e]); }
            $message = $status === 503 ? 'Announcement operation could not be completed. Please retry or contact support.' : $e->getMessage();
            $this->response->setStatusCode($status);
            if ($this->json()) {
                return $this->response->setJSON(['success' => false, 'message' => $message, 'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
            }
            return $this->response->setContentType('text/plain')->setBody($message);
        }
    }

    private function json(): bool
    {
        return $this->request->isAJAX() || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'json');
    }

    private function success(string $message, string $redirect)
    {
        session()->setFlashdata('success', $message);
        if ($this->json()) {
            return $this->response->setJSON(['success' => true, 'message' => $message, 'redirect' => site_url($redirect),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
        }
        return redirect()->to(site_url($redirect))->setHeader('Cache-Control', 'no-store, private');
    }
}
